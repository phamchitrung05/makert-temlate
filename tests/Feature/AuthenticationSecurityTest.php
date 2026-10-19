<?php

namespace Tests\Feature;

use App\Models\AiImport;
use App\Models\Customer;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Access\RoleManagementService;
use App\Services\Ai\Runs\AiTaskRunService;
use App\Services\Marketing\FacebookOAuthService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm hồi quy xác thực, credential admin, RBAC và OAuth.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp(), tearDown(): cô lập SQLite/cache và chặn HTTP ngoài.
 * - actor(), asToken(), facebookState(): tạo actor/token và state OAuth giả lập.
 * - rejectedAccounts(), test_rejected_login_always_performs_password_work(): chi phí băm.
 * - test_login_rechecks_account_before_issuing_token(): race trạng thái.
 * - test_login_limits_are_per_normalized_account_and_ip(): NAT và chuẩn hóa key.
 * - test_login_limits_follow_account_across_ips(): hạn chế nhiều IP cùng tài khoản.
 * - test_root_permissions_do_not_bypass_arbitrary_gates(): root và catalog mới.
 * - test_ai_routes_keep_registry_permissions(): quyền route AI động.
 * - test_root_can_cancel_staff_tasks_without_source_edit_access(): vận hành AI có audit.
 * - test_customer_with_admin_or_wildcard_ability_cannot_enter_admin(): boundary theo model.
 * - test_admin_account_rejects_weak_passwords_and_role_injection(): validation credential.
 * - test_admin_account_crud_hashes_password_and_preserves_history(): CRUD/xóa mềm.
 * - test_account_writes_are_scoped_and_versioned(): quyền, xung đột và tự khóa.
 * - test_credentials_and_status_revoke_only_admin_tokens(): password/status/guard.
 * - test_role_assignment_revokes_tokens_but_noop_does_not(): gán role/idempotency.
 * - test_account_audit_failure_rolls_back_credentials_and_tokens(): rollback.
 * - test_upload_has_a_per_admin_limit(): giới hạn upload theo actor.
 * - test_customer_collision_redirects_without_linking(): chống takeover và UX.
 * - test_customer_invalid_state_and_denial_are_recoverable(): OAuth lỗi an toàn.
 * - test_facebook_invalid_expired_and_replayed_states_are_rejected(): state một lần.
 * - test_facebook_state_rechecks_actor_permissions(): state không giữ quyền cũ.
 * - test_facebook_provider_rejection_is_a_safe_client_error(): không lộ provider secret.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): fixture/token HTTP -> assertions 200/4xx và dữ liệu an toàn.
 * Chỉ sửa DB SQLite in-memory; không gọi Google, Facebook hoặc AI thật.
 * =====================================================================
 */
final class AuthenticationSecurityTest extends TestCase
{
    use UsesIsolatedDatabase;

    /** Input: PHPUnit lifecycle. Output: schema/quyền cô lập và HTTP chỉ được fake; không dùng DB thật. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Http::preventStrayRequests();
        $this->withoutVite();
    }

    /** Input: PHPUnit lifecycle. Output: giải phóng SQLite sau test. */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Input: quyền hoặc role optional. Output: [User, token admin]; chỉ tạo fixture. */
    private function actor(array $permissions = [], ?string $role = null): array
    {
        $user = User::factory()->create(['status' => 'active']);
        if ($role !== null) {
            $user->assignRole($role);
        }
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return [$user, $user->createToken('security-test', ['admin'])->plainTextToken];
    }

    /** Input: bearer token. Output: TestCase dùng guard mới để kiểm thu hồi token thật qua HTTP. */
    private function asToken(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    /** Input: actor đã có quyền Page. Output: state cache thật với credentials giả; không gọi Graph. */
    private function facebookState(User $actor): string
    {
        config()->set('social.facebook.app_id', 'test-app');
        config()->set('social.facebook.app_secret', 'server-secret');
        config()->set('social.facebook.redirect_uri', 'https://example.test/api/admin/social-accounts/facebook/callback');
        parse_str(parse_url(app(FacebookOAuthService::class)->authorizationUrl($actor), PHP_URL_QUERY), $query);

        return $query['state'];
    }

    /** Input: không có. Output: các trạng thái login bị từ chối để chạy cùng hợp đồng lỗi. */
    public static function rejectedAccounts(): array
    {
        return [['missing'], ['active'], ['inactive'], ['suspended']];
    }

    /** Input: email thiếu hoặc account/wrong password. Output: luôn một phép băm và cùng lỗi 422, không cấp token. */
    #[DataProvider('rejectedAccounts')]
    public function test_rejected_login_always_performs_password_work(string $status): void
    {
        $user = $status === 'missing' ? null : User::factory()->create(['email' => 'rejected@example.test', 'status' => $status]);
        if ($user) {
            Hash::shouldReceive('check')->once()->with('wrong-password', $user->password)->andReturn(false);
            Hash::shouldReceive('make')->never();
        } else {
            Hash::shouldReceive('make')->once()->with('wrong-password')->andReturn('discarded');
            Hash::shouldReceive('check')->never();
        }
        $this->postJson('/api/admin/login', ['email' => 'rejected@example.test', 'password' => 'wrong-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /** Input: trạng thái đổi trong khi kiểm mật khẩu. Output: fresh check từ chối token và không ghi last_login_at. */
    public function test_login_rechecks_account_before_issuing_token(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Hash::shouldReceive('check')->once()->andReturnUsing(function () use ($user): bool {
            DB::table('users')->where('id', $user->id)->update(['status' => 'inactive']);

            return true;
        });
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull($user->fresh()->last_login_at);
    }

    /** Input: cùng IP, email hoa/thường và tài khoản khác. Output: cùng email bị 429, email khác cùng NAT vẫn 422. */
    public function test_login_limits_are_per_normalized_account_and_ip(): void
    {
        config()->set('project-settings.login_max_attempts', 1);
        $this->postJson('/api/admin/login', ['email' => 'nat@example.test', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/admin/login', ['email' => 'NAT@example.test', 'password' => 'wrong'])->assertTooManyRequests();
        $this->postJson('/api/admin/login', ['email' => 'nát@example.test', 'password' => 'wrong'])->assertTooManyRequests();
        $this->postJson('/api/admin/login', ['email' => 'colleague@example.test', 'password' => 'wrong'])->assertUnprocessable();
    }

    /** Input: cùng tài khoản từ 11 IP. Output: quota tổng tài khoản chặn đổi IP, tài khoản khác vẫn hoạt động. */
    public function test_login_limits_follow_account_across_ips(): void
    {
        config()->set('project-settings.login_max_attempts', 1);
        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])->postJson('/api/admin/login', [
                'email' => 'spray@example.test', 'password' => 'wrong',
            ])->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])->postJson('/api/admin/login', [
            'email' => 'spray@example.test', 'password' => 'wrong',
        ])->assertTooManyRequests();
        $this->postJson('/api/admin/login', ['email' => 'other@example.test', 'password' => 'wrong'])->assertUnprocessable();
    }

    /** Input: root không có permission pivot và permission mới chưa seed. Output: root có quyền catalog, gate nghiệp vụ vẫn từ chối. */
    public function test_root_permissions_do_not_bypass_arbitrary_gates(): void
    {
        Role::findByName('super-admin', 'admin')->syncPermissions([]);
        [$root, $token] = $this->actor(role: 'super-admin');
        config()->set('permissions.catalog.new_module', ['view']);
        $this->assertTrue($root->can('new_module.view'));
        Gate::define('protected-record', fn (User $user): bool => false);
        $this->assertFalse($root->can('protected-record'));
        $this->asToken($token)->getJson('/api/admin/access/users')->assertOk();
        [$ordinary] = $this->actor();
        $this->assertFalse($ordinary->can('new_module.view'));
    }

    /** Input: actor thiếu AI hoặc chỉ resources.create. Output: chặn sớm thiếu quyền, hỗ trợ đúng target registry ngoài posts. */
    public function test_ai_routes_keep_registry_permissions(): void
    {
        [, $token] = $this->actor();
        $this->asToken($token)->getJson('/api/admin/ai/tasks')->assertForbidden();
        $this->asToken($token)->getJson('/api/admin/ai-agent/targets')->assertForbidden();
        $this->asToken($token)->postJson('/api/admin/ai-agent/sessions', [])->assertForbidden();
        [, $resourceToken] = $this->actor(['resources.create']);
        $this->asToken($resourceToken)->getJson('/api/admin/ai-agent/targets')->assertOk();
        $this->asToken($resourceToken)->getJson('/api/admin/ai/tasks')->assertOk();
        $this->asToken($resourceToken)->getJson('/api/admin/ai-agent/capabilities/post')->assertForbidden();
    }

    /** Input: task của nhân viên, admin thường và root. Output: admin thường 404; root xem/hủy metadata có audit, không sửa nội dung nguồn. */
    public function test_root_can_cancel_staff_tasks_without_source_edit_access(): void
    {
        [$owner] = $this->actor(['posts.manage']);
        $import = AiImport::query()->create([
            'created_by' => $owner->id, 'source_url' => 'https://example.test/private?token=source-secret',
            'source_hash' => hash('sha256', 'source'), 'status' => 'queued', 'operation' => 'create',
            'input_json' => ['target_type' => 'post', 'prompt' => 'private-prompt'], 'expires_at' => now()->addDay(),
        ]);
        $task = app(AiTaskRunService::class)->registerImport($import);
        [, $ordinary] = $this->actor(['posts.manage']);
        $this->asToken($ordinary)->getJson('/api/admin/ai/tasks/'.$task->id)->assertNotFound();
        $this->asToken($ordinary)->postJson('/api/admin/ai/tasks/'.$task->id.'/cancel')->assertNotFound();
        [$root, $token] = $this->actor(role: 'super-admin');
        $this->asToken($token)->getJson('/api/admin/ai/tasks')->assertOk()->assertDontSee('private-prompt')->assertDontSee('source-secret');
        $this->asToken($token)->getJson('/api/admin/ai/tasks/'.$task->id)->assertOk()->assertDontSee('private-prompt');
        $this->asToken($token)->getJson('/api/admin/ai-agent/sessions/'.$import->id)->assertNotFound();
        $this->asToken($token)->postJson('/api/admin/ai/tasks/'.$task->id.'/cancel')->assertOk();
        $this->assertSame('cancelled', $import->fresh()->status);
        $this->assertDatabaseHas('activity_log', ['event' => 'ai_task_cancelled_by_super_admin', 'causer_id' => $root->id]);
    }

    /** Input: Customer token có ability admin hoặc wildcard. Output: mọi endpoint admin 403 dù token còn hạn và customer active. */
    public function test_customer_with_admin_or_wildcard_ability_cannot_enter_admin(): void
    {
        $customer = Customer::query()->create(['name' => 'Customer', 'email' => 'boundary@example.test', 'status' => 'active']);
        foreach ([['admin'], ['*']] as $abilities) {
            $token = $customer->createToken('invalid-boundary', $abilities)->plainTextToken;
            $this->asToken($token)->getJson('/api/admin/me')->assertForbidden();
            $this->asToken($token)->getJson('/api/admin/settings/preferences')->assertForbidden();
        }
    }

    /** Input: password yếu, thiếu xác nhận hoặc kèm role_ids. Output: 422 đúng field và không tạo tài khoản ngoài ý muốn. */
    public function test_admin_account_rejects_weak_passwords_and_role_injection(): void
    {
        [, $token] = $this->actor(role: 'super-admin');
        $payload = ['name' => 'Invalid', 'email' => 'invalid@example.test', 'status' => 'active'];
        $this->asToken($token)->postJson('/api/admin/access/users', [...$payload, 'password' => 'weak', 'password_confirmation' => 'weak'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->asToken($token)->postJson('/api/admin/access/users', [...$payload, 'password' => 'StrongPassword123!'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->asToken($token)->postJson('/api/admin/access/users', [
            ...$payload, 'password' => 'StrongPassword123!', 'password_confirmation' => 'StrongPassword123!',
            'role_ids' => [Role::findByName('super-admin', 'admin')->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('role_ids');
        $this->assertDatabaseMissing('users', ['email' => 'invalid@example.test']);
    }

    /** Input: root tạo/sửa/xóa admin. Output: băm mật khẩu, không trả/log secret, token bị thu hồi và giữ row lịch sử. */
    public function test_admin_account_crud_hashes_password_and_preserves_history(): void
    {
        [, $token] = $this->actor(role: 'super-admin');
        $created = $this->asToken($token)->postJson('/api/admin/access/users', [
            'name' => 'New admin', 'email' => 'new-admin@example.test', 'status' => 'active',
            'password' => 'StrongPassword123!', 'password_confirmation' => 'StrongPassword123!',
        ])->assertCreated();
        $user = User::findOrFail($created->json('data.id'));
        $this->assertTrue(Hash::check('StrongPassword123!', $user->password));
        $this->assertArrayNotHasKey('password', $created->json('data'));
        $oldToken = $user->createToken('old', ['admin'])->plainTextToken;
        $updated = $this->asToken($token)->patchJson('/api/admin/access/users/'.$user->id, [
            'name' => 'Changed admin', 'expected_version' => $created->json('data.account_version'),
            'password' => 'ChangedPassword123!', 'password_confirmation' => 'ChangedPassword123!',
        ])->assertOk()->assertJsonPath('data.name', 'Changed admin');
        $this->assertTrue(Hash::check('ChangedPassword123!', $user->fresh()->password));
        $this->asToken($oldToken)->getJson('/api/admin/me')->assertUnauthorized();
        $deleteToken = $user->createToken('before-delete', ['admin'])->plainTextToken;
        $this->asToken($token)->deleteJson('/api/admin/access/users/'.$user->id, [
            'expected_version' => $updated->json('data.account_version'),
        ])->assertOk();
        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->asToken($deleteToken)->getJson('/api/admin/me')->assertUnauthorized();
        $this->asToken($token)->getJson('/api/admin/access/users/'.$user->id)->assertNotFound();
        $logs = DB::table('activity_log')->where('log_name', 'access')->pluck('properties')->implode('');
        $this->assertStringNotContainsString('StrongPassword123!', $logs);
        $this->assertStringNotContainsString('ChangedPassword123!', $logs);
        $this->assertStringNotContainsString($user->password, $logs);
    }

    /** Input: reader, manager thấp hơn root, version cũ và tự xóa/khóa. Output: 403/409/422, không vượt quyền hoặc tự khóa. */
    public function test_account_writes_are_scoped_and_versioned(): void
    {
        [$root, $rootToken] = $this->actor(role: 'super-admin');
        [, $readerToken] = $this->actor(['users.view']);
        $this->asToken($readerToken)->postJson('/api/admin/access/users', [])->assertForbidden();
        [$manager, $managerToken] = $this->actor(['users.manage']);
        $version = RoleManagementService::accountVersion($root->load(['roles.permissions', 'permissions']));
        $this->asToken($managerToken)->patchJson('/api/admin/access/users/'.$root->id, [
            'name' => 'Hijacked', 'expected_version' => $version,
        ])->assertForbidden();
        $this->asToken($rootToken)->patchJson('/api/admin/access/users/'.$manager->id, [
            'name' => 'Stale', 'expected_version' => str_repeat('0', 64),
        ])->assertConflict();
        $this->asToken($rootToken)->deleteJson('/api/admin/access/users/'.$root->id, ['expected_version' => $version])->assertUnprocessable();
        $this->asToken($rootToken)->patchJson('/api/admin/access/users/'.$root->id, [
            'status' => 'inactive', 'expected_version' => $version,
        ])->assertUnprocessable();
        $this->assertSame('active', $root->fresh()->status);
    }

    /** Input: User và Customer trùng ID, admin đổi password rồi status. Output: chỉ token admin tương ứng bị xóa; customer vẫn còn. */
    public function test_credentials_and_status_revoke_only_admin_tokens(): void
    {
        [$user, $token] = $this->actor();
        $customer = Customer::query()->create(['name' => 'Customer', 'email' => 'customer@example.test', 'status' => 'active']);
        $customerToken = $customer->createToken('customer', ['customer']);
        $user->update(['password' => 'NewStrongPassword123!']);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $customer->tokens()->whereKey($customerToken->accessToken->id)->count());
        $this->asToken($token)->getJson('/api/admin/me')->assertUnauthorized();
        $user->createToken('new', ['admin']);
        $user->update(['status' => 'suspended']);
        $this->assertSame(0, $user->tokens()->count());
    }

    /** Input: cùng role rồi đổi role thật qua API. Output: noop giữ token, thay đổi thu hồi mọi thiết bị admin. */
    public function test_role_assignment_revokes_tokens_but_noop_does_not(): void
    {
        [, $rootToken] = $this->actor(role: 'super-admin');
        [$target, $token] = $this->actor();
        $target->createToken('second-device', ['admin']);
        $version = RoleManagementService::userVersion($target->load('roles'));
        $this->asToken($rootToken)->patchJson('/api/admin/access/users/'.$target->id.'/roles', [
            'role_ids' => [], 'expected_version' => $version,
        ])->assertOk();
        $this->asToken($token)->getJson('/api/admin/me')->assertOk();
        $this->asToken($rootToken)->patchJson('/api/admin/access/users/'.$target->id.'/roles', [
            'role_ids' => [Role::findByName('editor', 'admin')->id], 'expected_version' => $version,
        ])->assertOk();
        $this->assertSame(0, $target->tokens()->count());
        $this->asToken($token)->getJson('/api/admin/me')->assertUnauthorized();
    }

    /** Input: audit INSERT bị hỏng sau đổi credential. Output: password và token cùng rollback, không để mutation nửa chừng. */
    public function test_account_audit_failure_rolls_back_credentials_and_tokens(): void
    {
        [$root] = $this->actor(role: 'super-admin');
        [$target] = $this->actor();
        $version = RoleManagementService::accountVersion($target->load(['roles.permissions', 'permissions']));
        $hash = $target->password;
        DB::unprepared("CREATE TRIGGER reject_access_audit BEFORE INSERT ON activity_log WHEN NEW.log_name = 'access' BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
        try {
            app(RoleManagementService::class)->saveUser($root, ['password' => 'ChangedPassword123!', 'expected_version' => $version], $target);
            $this->fail('Audit failure phải rollback mutation.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame($hash, $target->fresh()->password);
            $this->assertSame(1, $target->tokens()->count());
        }
    }

    /** Input: 31 upload thiếu file cùng actor, actor khác cùng IP. Output: quota chặn actor đầu và không chặn actor thứ hai. */
    public function test_upload_has_a_per_admin_limit(): void
    {
        [, $token] = $this->actor(['media.upload']);
        for ($i = 0; $i < 30; $i++) {
            $this->asToken($token)->postJson('/api/admin/media-assets', [])->assertUnprocessable();
        }
        $this->asToken($token)->postJson('/api/admin/media-assets', [])->assertTooManyRequests();
        [, $other] = $this->actor(['media.upload']);
        $this->asToken($other)->postJson('/api/admin/media-assets', [])->assertUnprocessable();
    }

    /** Input: identity OAuth mới trùng email customer đã có. Output: redirect có thông báo, không gộp hoặc tạo identity trái phép. */
    public function test_customer_collision_redirects_without_linking(): void
    {
        Customer::query()->create(['name' => 'Existing', 'email' => 'existing@example.test', 'status' => 'active']);
        Socialite::fake('google', SocialiteUser::fake(['id' => 'unlinked', 'email' => 'existing@example.test', 'name' => 'Provider']));
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHas('error');
        $this->assertGuest('customer');
        $this->assertDatabaseCount('customer_identities', 0);
        $this->assertDatabaseCount('customers', 1);
        $this->get('/login')->assertOk()->assertSee('phương thức ban đầu')->assertSee('role="alert"', false);
    }

    /** Input: state Socialite sai hoặc người dùng từ chối quyền. Output: thông báo thử lại, không tạo session/customer. */
    public function test_customer_invalid_state_and_denial_are_recoverable(): void
    {
        Socialite::shouldReceive('driver')->with('google')->once()->andReturnSelf();
        Socialite::shouldReceive('user')->once()->andThrow(new InvalidStateException);
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHas('error');
        $this->get('/auth/google/callback?error=access_denied')->assertRedirect('/login')->assertSessionHas('error');
        $this->assertGuest('customer');
        $this->assertDatabaseCount('customers', 0);
    }

    /** Input: state không tồn tại/hết hạn/tái sử dụng. Output: 400 có hướng dẫn, Graph chỉ chạy cho callback hợp lệ và token lưu encrypted. */
    public function test_facebook_invalid_expired_and_replayed_states_are_rejected(): void
    {
        [$actor] = $this->actor(['social_accounts.manage']);
        $this->getJson('/api/admin/social-accounts/facebook/callback?code=code&state=invalid')->assertStatus(400)->assertJsonPath('success', false);
        $expired = $this->facebookState($actor);
        $this->travel(11)->minutes();
        $this->getJson('/api/admin/social-accounts/facebook/callback?code=code&state='.$expired)->assertStatus(400);
        $this->travelBack();
        $state = $this->facebookState($actor);
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'user-token']),
            '*/me/accounts*' => Http::response(['data' => [['id' => 'page-1', 'name' => 'Page', 'access_token' => 'page-secret']]]),
        ]);
        $this->getJson('/api/admin/social-accounts/facebook/callback?code=code&state='.$state)->assertOk()->assertJsonMissing(['access_token' => 'page-secret']);
        $this->getJson('/api/admin/social-accounts/facebook/callback?code=code&state='.$state)->assertStatus(400);
        Http::assertSentCount(2);
        $this->assertSame('page-secret', SocialAccount::query()->firstOrFail()->access_token);
        $this->assertNotSame('page-secret', DB::table('social_accounts')->value('access_token'));
    }

    /** Input: actor bị khóa hoặc gỡ quyền sau khi mở OAuth. Output: callback 403 trước Graph, state bị tiêu thụ và không ghi Page. */
    public function test_facebook_state_rechecks_actor_permissions(): void
    {
        [$actor] = $this->actor(['social_accounts.manage']);
        $state = $this->facebookState($actor);
        $actor->revokePermissionTo('social_accounts.manage');
        $this->flushPermissionCache();
        $this->getJson('/api/admin/social-accounts/facebook/callback?code=code&state='.$state)->assertForbidden();
        $this->assertFalse(Cache::has('facebook-oauth:'.$state));
        Http::assertNothingSent();
        $this->assertDatabaseCount('social_accounts', 0);
    }

    /** Input: Graph từ chối code với thông tin nhạy cảm. Output: 422 có hướng dẫn, không echo token/secret hoặc ghi account. */
    public function test_facebook_provider_rejection_is_a_safe_client_error(): void
    {
        [$actor] = $this->actor(['social_accounts.manage']);
        $state = $this->facebookState($actor);
        Http::fake(['*' => Http::response(['error' => ['message' => 'secret-provider-token']], 400)]);
        $this->getJson('/api/admin/social-accounts/facebook/callback?code=code&state='.$state)
            ->assertUnprocessable()->assertDontSee('secret-provider-token')->assertDontSee('server-secret');
        $this->assertDatabaseCount('social_accounts', 0);
    }
}
