<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\Access\RoleManagementService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm API quản lý role/permission thật cho admin.
 * =====================================================================
 *
 * Test dùng SQLite in-memory, token Sanctum và catalog permission đã seed.
 * Kiểm quyền đọc/ghi, catalog allowlist, version conflict, role protection,
 * gán role tài khoản admin và audit; không khởi động giao diện Vue.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp(), tearDown(), token(), superAdminToken(), permissionIds().
 * - test_*(): CRUD, phân trang, quyền theo guard/scope, system role, version,
 *   hiệu lực token hiện có, audit rollback và seeder giữ cấu hình quản trị.
 * =====================================================================
 */
final class AccessManagementApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị database và catalog quyền cho test access API
     * =====================================================================
     * INPUT: PHPUnit lifecycle.
     * OUTPUT: SQLite cô lập, permissions/roles admin đã seed.
     * SIDE EFFECT: Migrate schema và xóa permission cache.
     * EXCEPTION/TRANSACTION: Chỉ setup test; không gọi provider hoặc UI.
     * =====================================================================
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn database cô lập sau test access API
     * =====================================================================
     * INPUT: PHPUnit lifecycle.
     * OUTPUT: Connection/schema test được giải phóng.
     * SIDE EFFECT: Reset migration SQLite in-memory.
     * EXCEPTION/TRANSACTION: Lỗi teardown truyền lên PHPUnit.
     * =====================================================================
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cấp token admin với permission cụ thể
     * =====================================================================
     * INPUT: Danh sách permission hoặc role tùy chọn.
     * OUTPUT: Plaintext Sanctum token chỉ dùng trong test.
     * SIDE EFFECT: Ghi user/token/role trong database cô lập.
     * EXCEPTION/TRANSACTION: Không mở transaction; teardown dọn dữ liệu.
     * =====================================================================
     */
    private function token(array $permissions = [], ?string $role = null): string
    {
        $user = User::factory()->create(['status' => 'active']);
        if ($role !== null) {
            $user->assignRole($role);
        }
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('access-test', ['admin'])->plainTextToken;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cấp token super-admin
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: Token actor có toàn bộ quyền catalog.
     * SIDE EFFECT: Gọi token() với role super-admin.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    private function superAdminToken(): string
    {
        return $this->token(role: 'super-admin');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy permission IDs từ catalog database
     * =====================================================================
     * INPUT: Tên permission.
     * OUTPUT: ID theo đúng guard admin.
     * SIDE EFFECT: Query read-only database test.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    private function permissionIds(string ...$names): array
    {
        return Permission::query()->where('guard_name', 'admin')->whereIn('name', $names)
            ->orderBy('id')->pluck('id')->all();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm boundary users.view/users.manage của các endpoint access
     * =====================================================================
     * INPUT: Token thiếu/quyền đọc.
     * OUTPUT: 401 hoặc 403 khi không đủ quyền; 200 khi có users.view.
     * SIDE EFFECT: HTTP GET trên database cô lập.
     * EXCEPTION/TRANSACTION: Không mở transaction nghiệp vụ.
     * =====================================================================
     */
    public function test_access_endpoints_require_users_permission(): void
    {
        $this->getJson('/api/admin/roles')->assertUnauthorized();

        $withoutPermission = $this->token();
        $this->withToken($withoutPermission)->getJson('/api/admin/roles')->assertForbidden();

        $readToken = $this->token(['users.view']);
        $this->withToken($readToken)->getJson('/api/admin/roles')->assertOk()->assertJsonPath('data.items.0.name', 'admin');
        $this->withToken($readToken)->getJson('/api/admin/permissions')->assertOk();
        $this->withToken($readToken)->getJson('/api/admin/permissions/catalog')->assertOk()
            ->assertJsonPath('data.can_manage', false);
        $this->withToken($readToken)->getJson('/api/admin/access/users')->assertOk();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm CRUD custom role và audit của super-admin
     * =====================================================================
     * INPUT: Tên role, permission IDs và version trả từ API.
     * OUTPUT: 201/200/204; role và audit tồn tại đúng.
     * SIDE EFFECT: Ghi role, pivot và activity_log.
     * EXCEPTION/TRANSACTION: Mutation service chạy transaction/lock.
     * =====================================================================
     */
    public function test_super_admin_can_create_update_and_delete_custom_role(): void
    {
        $token = $this->superAdminToken();
        $permissionIds = $this->permissionIds('posts.manage', 'media.view');

        $created = $this->withToken($token)->postJson('/api/admin/roles', [
            'name' => 'content-editor', 'permission_ids' => $permissionIds,
        ])->assertCreated()->assertJsonPath('data.name', 'content-editor');

        $roleId = $created->json('data.id');
        $version = $created->json('data.version');
        $updated = $this->withToken($token)->patchJson('/api/admin/roles/'.$roleId, [
            'name' => 'content-editor', 'permission_ids' => [$permissionIds[0]], 'expected_version' => $version,
        ])->assertOk();
        $this->assertCount(1, $updated->json('data.permissions'));
        $this->assertDatabaseHas('role_has_permissions', ['role_id' => $roleId, 'permission_id' => $permissionIds[0]]);

        $this->withToken($token)->deleteJson('/api/admin/roles/'.$roleId, [
            'expected_version' => $updated->json('data.version'),
        ])->assertNoContent();
        $this->assertDatabaseMissing('roles', ['id' => $roleId]);
        foreach (['role_created', 'role_updated', 'role_deleted'] as $event) {
            $this->assertDatabaseHas('activity_log', ['log_name' => 'access', 'event' => $event, 'subject_type' => 'role', 'subject_id' => $roleId]);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn ghi đè role khi client dùng version cũ
     * =====================================================================
     * INPUT: Hai payload cập nhật cùng version ban đầu.
     * OUTPUT: Request thứ hai trả 409; permission mới nhất được giữ.
     * SIDE EFFECT: Chỉ mutation đầu tiên ghi database.
     * EXCEPTION/TRANSACTION: Conflict rollback transaction thứ hai.
     * =====================================================================
     */
    public function test_stale_role_version_is_rejected_without_overwrite(): void
    {
        $token = $this->superAdminToken();
        $ids = $this->permissionIds('posts.manage', 'media.view', 'settings.view');
        $created = $this->withToken($token)->postJson('/api/admin/roles', [
            'name' => 'stale-check', 'permission_ids' => [$ids[0]],
        ])->assertCreated();
        $roleId = $created->json('data.id');
        $version = $created->json('data.version');

        $this->withToken($token)->patchJson('/api/admin/roles/'.$roleId, [
            'name' => 'stale-check', 'permission_ids' => [$ids[1]], 'expected_version' => $version,
        ])->assertOk();
        $this->withToken($token)->patchJson('/api/admin/roles/'.$roleId, [
            'name' => 'stale-check', 'permission_ids' => [$ids[2]], 'expected_version' => $version,
        ])->assertStatus(409);
        $this->assertDatabaseHas('role_has_permissions', ['role_id' => $roleId, 'permission_id' => $ids[1]]);
        $this->assertDatabaseMissing('role_has_permissions', ['role_id' => $roleId, 'permission_id' => $ids[2]]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Không cho manager cấp quyền ngoài phạm vi của mình
     * =====================================================================
     * INPUT: Actor chỉ có users.manage và một permission hợp lệ.
     * OUTPUT: 403 khi cố cấp posts.manage; role không được tạo.
     * SIDE EFFECT: Không ghi role/pivot.
     * EXCEPTION/TRANSACTION: Service rollback mutation khi thiếu quyền.
     * =====================================================================
     */
    public function test_manager_cannot_grant_permission_outside_its_scope(): void
    {
        $token = $this->token(['users.manage']);
        $postsManage = $this->permissionIds('posts.manage');

        $this->withToken($token)->postJson('/api/admin/roles', [
            'name' => 'restricted-editor', 'permission_ids' => $postsManage,
        ])->assertForbidden();
        $this->assertDatabaseMissing('roles', ['name' => 'restricted-editor', 'guard_name' => 'admin']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gán role cho admin khác và chặn tự sửa role
     * =====================================================================
     * INPUT: Super-admin actor, target admin và role/version.
     * OUTPUT: 200 khi gán; 422 khi actor tự gán role.
     * SIDE EFFECT: Sync model_has_roles và audit access.
     * EXCEPTION/TRANSACTION: Mutation dùng lock/transaction.
     * =====================================================================
     */
    public function test_role_assignment_updates_another_admin_and_blocks_self_change(): void
    {
        $actor = User::factory()->create(['status' => 'active']);
        $actor->assignRole('super-admin');
        $target = User::factory()->create(['status' => 'active']);
        $target->assignRole('support');
        $this->flushPermissionCache();
        Auth::forgetGuards();
        $token = $actor->createToken('assign-test', ['admin'])->plainTextToken;

        $targetResponse = $this->withToken($token)->getJson('/api/admin/access/users/'.$target->id)->assertOk();
        $adminRole = Role::query()->where('name', 'admin')->where('guard_name', 'admin')->firstOrFail();
        $this->withToken($token)->patchJson('/api/admin/access/users/'.$target->id.'/roles', [
            'role_ids' => [$adminRole->id], 'expected_version' => $targetResponse->json('data.version'),
        ])->assertOk()->assertJsonPath('data.roles.0.name', 'admin');
        $this->assertTrue($target->fresh()->hasRole('admin', 'admin'));
        $this->withToken($token)->patchJson('/api/admin/access/users/'.$target->id.'/roles', [
            'role_ids' => [], 'expected_version' => $targetResponse->json('data.version'),
        ])->assertStatus(409);
        $this->assertTrue($target->fresh()->hasRole('admin', 'admin'));

        $actorResponse = $this->withToken($token)->getJson('/api/admin/access/users/'.$actor->id)->assertOk();
        $this->withToken($token)->patchJson('/api/admin/access/users/'.$actor->id.'/roles', [
            'role_ids' => [], 'expected_version' => $actorResponse->json('data.version'),
        ])->assertStatus(422);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giữ role đang dùng và ẩn permission ngoài catalog
     * =====================================================================
     * INPUT: Role custom đã gán user và permission lạ trong database.
     * OUTPUT: Xóa trả 409; permission lạ không xuất hiện API.
     * SIDE EFFECT: Không xóa role đang được dùng.
     * EXCEPTION/TRANSACTION: Delete conflict rollback.
     * =====================================================================
     */
    public function test_role_in_use_cannot_be_deleted_and_unknown_permissions_are_hidden(): void
    {
        $token = $this->superAdminToken();
        $permissionIds = $this->permissionIds('posts.manage');
        $created = $this->withToken($token)->postJson('/api/admin/roles', [
            'name' => 'in-use-role', 'permission_ids' => $permissionIds,
        ])->assertCreated();
        $roleId = $created->json('data.id');
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole(Role::findOrFail($roleId));
        $this->flushPermissionCache();
        Permission::query()->create(['name' => 'legacy.hidden', 'guard_name' => 'admin']);

        $this->withToken($token)->deleteJson('/api/admin/roles/'.$roleId, [
            'expected_version' => $created->json('data.version'),
        ])->assertStatus(409);
        $this->withToken($token)->getJson('/api/admin/permissions')->assertOk()
            ->assertJsonMissing(['name' => 'legacy.hidden']);
        $this->assertDatabaseHas('roles', ['id' => $roleId]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn mọi mutation với quyền chỉ xem
     * INPUT: Reader users.view, role và admin target.
     * OUTPUT: Các endpoint ghi trả 403, không sửa database.
     * SIDE EFFECT: Gửi HTTP trên database test cô lập.
     * EXCEPTION/TRANSACTION: Middleware chặn trước mutation service.
     * =====================================================================
     */
    public function test_reader_cannot_mutate_roles_or_admin_role_assignments(): void
    {
        $token = $this->token(['users.view']);
        $role = Role::query()->where('name', 'editor')->where('guard_name', 'admin')->firstOrFail();
        $target = User::factory()->create(['status' => 'active']);
        $this->withToken($token)->postJson('/api/admin/roles', ['name' => 'reader-created', 'permission_ids' => []])->assertForbidden();
        $this->withToken($token)->patchJson('/api/admin/roles/'.$role->id, [])->assertForbidden();
        $this->withToken($token)->deleteJson('/api/admin/roles/'.$role->id, [])->assertForbidden();
        $this->withToken($token)->patchJson('/api/admin/access/users/'.$target->id.'/roles', [])->assertForbidden();
        $this->assertDatabaseMissing('roles', ['name' => 'reader-created']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giữ bất biến root và tên/xóa system role; tách guard
     * INPUT: Super-admin token, role root/system và role guard customer.
     * OUTPUT: 403 root, 422 đổi tên/xóa system, 404 role khác guard.
     * SIDE EFFECT: Chỉ đọc database; mutation bị rollback.
     * EXCEPTION/TRANSACTION: Invariant được kiểm trong transaction service.
     * =====================================================================
     */
    public function test_system_roles_are_protected_and_other_guards_are_unavailable(): void
    {
        $token = $this->superAdminToken();
        $root = Role::query()->where('name', 'super-admin')->where('guard_name', 'admin')->firstOrFail();
        $rootVersion = RoleManagementService::roleVersion($root->load('permissions'));
        $this->withToken($token)->patchJson('/api/admin/roles/'.$root->id, [
            'name' => 'super-admin', 'permission_ids' => [], 'expected_version' => $rootVersion,
        ])->assertForbidden();
        $this->withToken($token)->deleteJson('/api/admin/roles/'.$root->id, ['expected_version' => $rootVersion])->assertForbidden();

        $admin = Role::query()->where('name', 'admin')->where('guard_name', 'admin')->firstOrFail();
        $version = RoleManagementService::roleVersion($admin->load('permissions'));
        $this->withToken($token)->patchJson('/api/admin/roles/'.$admin->id, [
            'name' => 'renamed-admin', 'permission_ids' => [], 'expected_version' => $version,
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->withToken($token)->deleteJson('/api/admin/roles/'.$admin->id, ['expected_version' => $version])->assertUnprocessable();

        $other = Role::query()->create(['name' => 'customer-only', 'guard_name' => 'customer']);
        $this->withToken($token)->getJson('/api/admin/roles/'.$other->id)->assertNotFound();
        $this->withToken($token)->patchJson('/api/admin/roles/'.$other->id, [
            'name' => 'customer-only', 'permission_ids' => [], 'expected_version' => str_repeat('0', 64),
        ])->assertNotFound();
        $this->withToken($token)->getJson('/api/admin/roles')->assertJsonMissing(['name' => 'customer-only']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Không cho manager tự mất quyền quản lý qua sửa role
     * INPUT: Actor chỉ nhận users.manage/view từ một custom role.
     * OUTPUT: 422 khi bỏ users.manage; pivot giữ nguyên.
     * SIDE EFFECT: Tạo fixture actor/role, mutation rollback.
     * EXCEPTION/TRANSACTION: Invariant kiểm với actor đã khóa trong service.
     * =====================================================================
     */
    public function test_manager_cannot_remove_own_management_permission(): void
    {
        $role = Role::query()->create(['name' => 'access-manager', 'guard_name' => 'admin']);
        $role->syncPermissions(['users.manage', 'users.view']);
        $token = $this->token(role: 'access-manager');
        $response = $this->withToken($token)->getJson('/api/admin/roles/'.$role->id)->assertOk();
        $this->withToken($token)->patchJson('/api/admin/roles/'.$role->id, [
            'name' => $role->name, 'permission_ids' => $this->permissionIds('users.view'),
            'expected_version' => $response->json('data.version'),
        ])->assertUnprocessable();
        $this->assertTrue($role->fresh()->hasPermissionTo('users.manage'));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giữ scope manager khi sửa role và gán role cao hơn
     * INPUT: Manager users.manage, target root và role posts.manage ngoài scope.
     * OUTPUT: 403 khi sửa role cao hơn, đổi root hoặc gán role cao hơn.
     * SIDE EFFECT: Mutation bị rollback, fixtures trong database test.
     * EXCEPTION/TRANSACTION: Scope kiểm lại dưới lock service.
     * =====================================================================
     */
    public function test_manager_cannot_change_or_assign_roles_outside_scope(): void
    {
        $token = $this->token(['users.manage']);
        $highRole = Role::query()->create(['name' => 'higher-role', 'guard_name' => 'admin']);
        $highRole->syncPermissions(['posts.manage']);
        $roleResponse = $this->withToken($token)->getJson('/api/admin/roles/'.$highRole->id)
            ->assertOk()->assertJsonPath('data.can_edit', false);
        $this->withToken($token)->patchJson('/api/admin/roles/'.$highRole->id, [
            'name' => 'higher-role', 'permission_ids' => [], 'expected_version' => $roleResponse->json('data.version'),
        ])->assertForbidden();

        $rootUser = User::factory()->create(['status' => 'active']);
        $rootUser->assignRole('super-admin');
        $target = User::factory()->create(['status' => 'active']);
        $this->flushPermissionCache();
        $rootResponse = $this->withToken($token)->getJson('/api/admin/access/users/'.$rootUser->id)
            ->assertOk()->assertJsonPath('data.can_edit_roles', false);
        $this->withToken($token)->patchJson('/api/admin/access/users/'.$rootUser->id.'/roles', [
            'role_ids' => [], 'expected_version' => $rootResponse->json('data.version'),
        ])->assertForbidden();
        $targetResponse = $this->withToken($token)->getJson('/api/admin/access/users/'.$target->id)->assertOk();
        $this->withToken($token)->patchJson('/api/admin/access/users/'.$target->id.'/roles', [
            'role_ids' => [$highRole->id], 'expected_version' => $targetResponse->json('data.version'),
        ])->assertForbidden();
        $this->assertFalse($target->fresh()->hasRole('higher-role', 'admin'));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đổi role permissions có hiệu lực với token đã cấp
     * INPUT: Token reader hiện có và root sửa quyền role của reader.
     * OUTPUT: GET từ 200 chuyển 403 mà không cấp token mới.
     * SIDE EFFECT: Sửa pivot qua API và invalidation permission cache.
     * EXCEPTION/TRANSACTION: Mutation commit trước khi request reader mới chạy.
     * =====================================================================
     */
    public function test_role_permission_changes_apply_to_existing_admin_tokens(): void
    {
        $rootToken = $this->superAdminToken();
        $created = $this->withToken($rootToken)->postJson('/api/admin/roles', [
            'name' => 'temporary-reader', 'permission_ids' => $this->permissionIds('users.view'),
        ])->assertCreated();
        $readerToken = $this->token(role: 'temporary-reader');
        $this->withToken($readerToken)->getJson('/api/admin/roles')->assertOk();
        Auth::forgetGuards();
        $this->withToken($rootToken)->patchJson('/api/admin/roles/'.$created->json('data.id'), [
            'name' => 'temporary-reader', 'permission_ids' => [], 'expected_version' => $created->json('data.version'),
        ])->assertOk();
        Auth::forgetGuards();
        $this->withToken($readerToken)->getJson('/api/admin/roles')->assertForbidden();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Seeder không ghi đè role đã quản lý qua admin
     * INPUT: Editor bị thay quyền và root tạm thiếu quyền trong fixture.
     * OUTPUT: Seed giữ cấu hình editor, root có lại catalog đầy đủ.
     * SIDE EFFECT: Seed permissions/roles database test, xóa cache.
     * EXCEPTION/TRANSACTION: Không gọi database ứng dụng.
     * =====================================================================
     */
    public function test_seeding_preserves_managed_roles_and_refreshes_root_catalog(): void
    {
        $editor = Role::query()->where('name', 'editor')->where('guard_name', 'admin')->firstOrFail();
        $editor->syncPermissions(['media.view']);
        $root = Role::query()->where('name', 'super-admin')->where('guard_name', 'admin')->firstOrFail();
        $root->syncPermissions([]);
        $this->seed(RolePermissionSeeder::class);
        $this->assertSame(['media.view'], $editor->fresh()->permissions->pluck('name')->all());
        $this->assertCount(count(RoleManagementService::permissionNames()), $root->fresh()->permissions);
        $this->assertSame(4, Role::query()->where('guard_name', 'admin')->count());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate shape/catalog/guard và version bắt buộc
     * INPUT: Payload thiếu mảng/version, permission lạ, sai guard hoặc duplicate.
     * OUTPUT: 422 theo field; không tạo role không hợp lệ.
     * SIDE EFFECT: Query validation ở database test.
     * EXCEPTION/TRANSACTION: FormRequest chặn trước mutation.
     * =====================================================================
     */
    public function test_invalid_catalog_guard_and_missing_version_are_rejected(): void
    {
        $token = $this->superAdminToken();
        $unknown = Permission::query()->create(['name' => 'legacy.hidden', 'guard_name' => 'admin']);
        $foreign = Permission::query()->create(['name' => 'users.view', 'guard_name' => 'customer']);
        foreach ([$unknown->id, $foreign->id, 999999] as $id) {
            $this->withToken($token)->postJson('/api/admin/roles', [
                'name' => 'invalid-role', 'permission_ids' => [$id],
            ])->assertUnprocessable()->assertJsonValidationErrors('permission_ids.0');
        }
        $this->withToken($token)->postJson('/api/admin/roles', ['name' => 'missing-array'])
            ->assertUnprocessable()->assertJsonValidationErrors('permission_ids');
        $ids = $this->permissionIds('users.view');
        $this->withToken($token)->postJson('/api/admin/roles', [
            'name' => 'duplicate-array', 'permission_ids' => [$ids[0], $ids[0]],
        ])->assertUnprocessable();
        $role = Role::query()->where('name', 'support')->where('guard_name', 'admin')->firstOrFail();
        $this->withToken($token)->patchJson('/api/admin/roles/'.$role->id, [
            'name' => 'support', 'permission_ids' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->assertDatabaseMissing('roles', ['name' => 'invalid-role']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Filter/phân trang admin thật và không lộ credential/customer
     * INPUT: Root token, hai admin và customer có tên trùng filter.
     * OUTPUT: Total/page đúng, users_count đúng, không password/remember_token.
     * SIDE EFFECT: HTTP GET chỉ đọc dữ liệu test đã seed.
     * EXCEPTION/TRANSACTION: Không mutation; query eager load và count pivot.
     * =====================================================================
     */
    public function test_lists_filter_paginate_count_admins_and_hide_credentials(): void
    {
        $token = $this->superAdminToken();
        $role = Role::query()->where('name', 'support')->where('guard_name', 'admin')->firstOrFail();
        foreach (['Access Alpha', 'Access Beta'] as $name) {
            $user = User::factory()->create(['name' => $name, 'status' => 'active']);
            $user->assignRole($role);
        }
        User::factory()->create(['name' => 'Access Inactive', 'status' => 'suspended']);
        Customer::query()->create(['name' => 'Access Customer', 'email' => 'access-customer@example.com', 'status' => 'active']);
        $users = $this->withToken($token)->getJson('/api/admin/access/users?search=Access&status=active&role_id='.$role->id.'&per_page=1')
            ->assertOk()->assertJsonPath('data.itemsLength', 2)->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.name', 'Access Alpha')->assertJsonPath('meta.pagination.last_page', 2);
        $this->assertArrayNotHasKey('password', $users->json('data.items.0'));
        $this->assertArrayNotHasKey('remember_token', $users->json('data.items.0'));
        $this->withToken($token)->getJson('/api/admin/roles?search=support')
            ->assertOk()->assertJsonPath('data.itemsLength', 1)->assertJsonPath('data.items.0.users_count', 2);
        $this->withToken($token)->getJson('/api/admin/permissions?group=users&per_page=1')
            ->assertOk()->assertJsonPath('data.itemsLength', 2)->assertJsonCount(1, 'data.items');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Mutation rollback khi audit không ghi được
     * INPUT: Trigger SQLite từ chối insert activity_log.
     * OUTPUT: 500 an toàn, role/pivot không tồn tại sau request lỗi.
     * SIDE EFFECT: Trigger chỉ trong database test; transaction rollback.
     * EXCEPTION/TRANSACTION: Lỗi audit truyền qua BaseResponse, không commit dữ liệu.
     * =====================================================================
     */
    public function test_audit_failure_rolls_back_role_mutation(): void
    {
        $token = $this->superAdminToken();
        DB::unprepared("CREATE TRIGGER reject_access_audit BEFORE INSERT ON activity_log BEGIN SELECT RAISE(ABORT, 'test audit unavailable'); END");
        $this->withToken($token)->postJson('/api/admin/roles', [
            'name' => 'rollback-role', 'permission_ids' => $this->permissionIds('users.view'),
        ])->assertStatus(500)->assertJsonPath('success', false);
        $this->assertDatabaseMissing('roles', ['name' => 'rollback-role']);
        $this->assertDatabaseCount('activity_log', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn customer và admin đã suspended khỏi access API
     * INPUT: Customer token có cả ability admin và root token của admin suspended.
     * OUTPUT: HTTP 403; không gọi mutation hoặc trả dữ liệu access.
     * SIDE EFFECT: Fixture token/status ở database test cô lập.
     * EXCEPTION/TRANSACTION: Middleware/FormRequest chặn trước service.
     * =====================================================================
     */
    public function test_customer_and_suspended_admin_cannot_access_management(): void
    {
        $customer = Customer::query()->create(['name' => 'Customer', 'email' => 'guard-customer@example.com', 'status' => 'active']);
        $customerToken = $customer->createToken('invalid-admin-ability', ['admin'])->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($customerToken)->getJson('/api/admin/roles')->assertForbidden();
        $this->withToken($customerToken)->postJson('/api/admin/roles', ['name' => 'customer-role', 'permission_ids' => []])->assertForbidden();

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('super-admin');
        $token = $admin->createToken('suspended-access', ['admin'])->plainTextToken;
        $admin->update(['status' => 'suspended']);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/admin/roles')->assertForbidden();
        $this->withToken($token)->postJson('/api/admin/roles', ['name' => 'suspended-role', 'permission_ids' => []])->assertForbidden();
        $this->assertDatabaseMissing('roles', ['name' => 'customer-role']);
        $this->assertDatabaseMissing('roles', ['name' => 'suspended-role']);
    }
}
