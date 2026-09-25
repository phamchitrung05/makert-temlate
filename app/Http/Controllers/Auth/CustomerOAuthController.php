<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Laravel\Socialite\Facades\Socialite;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Xử lý đăng nhập customer bằng OAuth
 * =====================================================================
 *
 * Controller này hỗ trợ Google/Facebook thông qua Socialite. Identity được
 * tìm bằng provider_user_id trước; email trùng không tự động gộp customer.
 * Access token OAuth không được lưu vì V1 chỉ cần xác thực đăng nhập.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - redirect(): redirect tới provider được allow-list
 * - callback(): nhận provider user, tạo/liên kết identity và login customer
 * - logout(): đăng xuất customer và hủy session
 * - supportedProviders(): trả danh sách provider được phép
 * - resolveCustomer(): tìm hoặc tạo customer/identity trong transaction
 * =====================================================================
 */
class CustomerOAuthController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Redirect customer tới OAuth provider
     * =====================================================================
     *
     * INPUT:
     * - provider: chỉ nhận google hoặc facebook
     *
     * OUTPUT:
     * - RedirectResponse tới OAuth provider
     * - HttpException 404 nếu provider không được allow-list
     * =====================================================================
     */
    public function redirect(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, $this->supportedProviders(), true), 404);

        return Socialite::driver($provider)->redirect();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xử lý callback OAuth và tạo customer session
     * =====================================================================
     *
     * INPUT:
     * - provider: provider đã được allow-list
     * - OAuth callback request do Socialite xử lý
     *
     * OUTPUT:
     * - RedirectResponse về /account sau khi login thành công
     * - HttpException 403/404/409 khi provider, identity hoặc customer không hợp lệ
     *
     * SIDE EFFECT:
     * - Có thể tạo customer và customer_identity trong transaction
     * - Cập nhật last_login_at/last_used_at và tạo customer session
     * =====================================================================
     */
    public function callback(string $provider, Request $request): RedirectResponse
    {
        abort_unless(in_array($provider, $this->supportedProviders(), true), 404);

        $providerUser = Socialite::driver($provider)->user();
        $customer = $this->resolveCustomer($provider, (string) $providerUser->id, [
            'email' => $providerUser->email,
            'name' => $providerUser->name ?: $providerUser->nickname ?: 'Customer',
            'provider_email' => $providerUser->email,
            'provider_name' => $providerUser->name ?: $providerUser->nickname,
            'provider_avatar_url' => $providerUser->avatar,
        ]);

        abort_unless($customer->isActive(), 403, 'Customer account is suspended.');

        Auth::guard('customer')->login($customer, true);
        $request->session()->regenerate();

        $customer->forceFill(['last_login_at' => now()])->saveQuietly();
        $customer->identities()
            ->where('provider', $provider)
            ->where('provider_user_id', (string) $providerUser->id)
            ->update(['last_used_at' => now()]);

        activity()
            ->causedBy($customer)
            ->performedOn($customer)
            ->event('customer_logged_in')
            ->useLog('auth')
            ->withProperties(['provider' => $provider])
            ->log('Customer logged in with OAuth.');

        return redirect('/account');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đăng xuất customer
     * =====================================================================
     *
     * INPUT:
     * - Request hiện tại có customer session
     *
     * OUTPUT:
     * - RedirectResponse về trang chủ
     *
     * SIDE EFFECT:
     * - Logout customer guard, invalidate session và regenerate CSRF token
     * =====================================================================
     */
    public function logout(Request $request): RedirectResponse
    {
        $customer = Auth::guard('customer')->user();

        if ($customer) {
            activity()
                ->causedBy($customer)
                ->performedOn($customer)
                ->event('customer_logged_out')
                ->useLog('auth')
                ->log('Customer logged out.');
        }

        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả danh sách provider OAuth được phép
     * =====================================================================
     *
     * OUTPUT:
     * - list<string>: provider đã có credentials và route callback
     * =====================================================================
     */
    private function supportedProviders(): array
    {
        return ['google', 'facebook'];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tìm hoặc tạo customer từ OAuth identity
     * =====================================================================
     *
     * INPUT:
     * - provider/providerUserId: định danh ổn định từ OAuth provider
     * - profile: name, email và metadata hiển thị; không chứa access token
     *
     * OUTPUT:
     * - Customer: customer đã tồn tại hoặc vừa được tạo
     *
     * SIDE EFFECT/TRANSACTION:
     * - Tạo customer_identity và customer nếu cần trong DB transaction
     * - Nếu email trùng customer khác mà chưa đăng nhập, throw 409 để tránh auto-link
     * =====================================================================
     */
    private function resolveCustomer(string $provider, string $providerUserId, array $profile): Customer
    {
        return DB::transaction(function () use ($provider, $providerUserId, $profile): Customer {
            $identity = CustomerIdentity::query()
                ->with('customer')
                ->where('provider', $provider)
                ->where('provider_user_id', $providerUserId)
                ->lockForUpdate()
                ->first();

            if ($identity) {
                return $identity->customer;
            }

            $currentCustomer = Auth::guard('customer')->user();
            $existingCustomer = $profile['email']
                ? Customer::query()->where('email', $profile['email'])->first()
                : null;

            if ($existingCustomer && (! $currentCustomer || $currentCustomer->isNot($existingCustomer))) {
                throw new HttpException(409, 'Customer identity must be linked while authenticated.');
            }

            $customer = $currentCustomer ?? Customer::query()->create([
                'name' => $profile['name'],
                'email' => $profile['email'],
                'status' => 'active',
            ]);

            $customer->identities()->create([
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
                'provider_email' => $profile['provider_email'],
                'provider_name' => $profile['provider_name'],
                'provider_avatar_url' => $profile['provider_avatar_url'],
                'last_used_at' => now(),
            ]);

            return $customer;
        });
    }
}
