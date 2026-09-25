<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Two\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử authentication và guard separation V1
 * =====================================================================
 *
 * Test suite dùng SQLite in-memory riêng để kiểm tra admin session,
 * customer OAuth identity và việc hai guard không truy cập chéo nhau.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp(): cấu hình connection/schema cô lập cho mỗi test
 * - tearDown(): rollback schema và giải phóng connection
 * - test_admin_can_login(): kiểm tra admin login và admin API
 * - test_suspended_admin_cannot_login(): kiểm tra status enforcement
 * - test_customer_oauth_creates_local_identity(): kiểm tra OAuth local mapping
 * - test_customer_guard_cannot_access_admin_endpoint(): kiểm tra guard boundary
 * =====================================================================
 */
class AuthenticationTest extends TestCase
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị database và config cho authentication test
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Tạo SQLite in-memory connection và chạy toàn bộ migrations
     * =====================================================================
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        Config::set('cache.default', 'array');
        Config::set('permission.cache.store', 'array');
        Config::set('database.connections.auth_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        Config::set('database.default', 'auth_test');

        Artisan::call('migrate', [
            '--database' => 'auth_test',
            '--force' => true,
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn schema và connection sau authentication test
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Reset migration và purge connection auth_test
     * =====================================================================
     */
    protected function tearDown(): void
    {
        Artisan::call('migrate:reset', [
            '--database' => 'auth_test',
            '--force' => true,
        ]);
        DB::purge('auth_test');

        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra admin đăng nhập và gọi được admin API
     * =====================================================================
     *
     * OUTPUT:
     * - Assert HTTP 200, admin guard authenticated và profile đúng
     * =====================================================================
     */
    public function test_admin_can_login(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('a-secure-password'),
            'status' => 'active',
        ]);

        $response = $this->postJson('/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'a-secure-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $admin->id)
            ->assertJsonPath('tokenType', 'Bearer');
        $this->assertNotEmpty($response->json('accessToken'));
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('user.email', 'admin@example.com');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Từ chối admin đã bị suspended
     * =====================================================================
     *
     * OUTPUT:
     * - Assert HTTP 422 và admin guard chưa authenticated
     * =====================================================================
     */
    public function test_suspended_admin_cannot_login(): void
    {
        User::query()->create([
            'name' => 'Suspended Admin',
            'email' => 'suspended@example.com',
            'password' => Hash::make('a-secure-password'),
            'status' => 'suspended',
        ]);

        $this->postJson('/admin/login', [
            'email' => 'suspended@example.com',
            'password' => 'a-secure-password',
        ])->assertStatus(422);

        $this->assertGuest('admin');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo customer và identity từ OAuth callback
     * =====================================================================
     *
     * OUTPUT:
     * - Assert customer guard authenticated và identity được lưu đúng provider ID
     * =====================================================================
     */
    public function test_customer_oauth_creates_local_identity(): void
    {
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-user-123',
            'name' => 'OAuth Customer',
            'email' => 'oauth@example.com',
            'avatar' => 'https://example.com/avatar.png',
        ]));

        $this->get('/auth/google/callback')->assertRedirect('/account');

        $customer = Customer::query()->where('email', 'oauth@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertDatabaseHas('customer_identities', [
            'customer_id' => $customer->id,
            'provider' => 'google',
            'provider_user_id' => 'google-user-123',
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Bảo đảm customer không truy cập admin endpoint
     * =====================================================================
     *
     * OUTPUT:
     * - Assert HTTP 401 cho admin API khi chỉ có customer session
     * =====================================================================
     */
    public function test_customer_guard_cannot_access_admin_endpoint(): void
    {
        $customer = Customer::query()->create([
            'name' => 'Customer',
            'email' => 'customer@example.com',
            'status' => 'active',
        ]);

        $this->actingAs($customer, 'customer')
            ->getJson('/api/admin/me')
            ->assertUnauthorized();
    }
}
