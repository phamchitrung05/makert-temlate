<?php

namespace App\Services\Access;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Quản lý role admin, catalog quyền và gán role có bảo vệ quyền hệ thống
 * =====================================================================
 * Controller dùng service cho query/mutation. Catalog lấy từ config; super-admin bất biến, role mặc định giữ tên. Mọi mutation khóa role super-admin để serialize thay đổi quyền và kiểm lại actor; audit cùng transaction, cache xóa sau commit.
 * CÁC HÀM/METHOD TRONG FILE: permissionNames(), canGrant(), canEditRole(), roleVersion(), userVersion(), roles(), roleDetails(), users(), permissions(), catalog(), save(), remove(), assign(), mutate(), selectedPermissions(), userCount(), assertVersion(), audit().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): dữ liệu đã validate/admin -> DTO hoặc mutation quyền.
 * SIDE EFFECT: Theo boundary từng method; không gọi dịch vụ bên ngoài.
 * EXCEPTION/TRANSACTION: Quyền/validation/conflict trả 403/422/409; mutation dùng transaction.
 * =====================================================================
 */
final class RoleManagementService
{
    public const SYSTEM_ROLES = ['super-admin', 'admin', 'editor', 'support'];

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy tên quyền được ứng dụng hỗ trợ
     * INPUT: config permissions.catalog.
     * OUTPUT: Danh sách tên resource.action.
     * SIDE EFFECT: Hàm thuần, không query.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public static function permissionNames(): array
    {
        return collect(config('permissions.catalog', []))
            ->flatMap(fn (array $actions, string $group) => array_map(fn (string $action) => $group.'.'.$action, $actions))
            ->values()->all();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm quyền được cấp có nằm trong phạm vi của người quản lý
     * INPUT: Actor đã xác thực và collection Permission.
     * OUTPUT: true nếu super-admin hoặc actor có mọi quyền được cấp.
     * SIDE EFFECT: Đọc relation permission; caller list eager load.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public static function canGrant(User $actor, Collection $permissions): bool
    {
        return $actor->hasRole('super-admin', 'admin')
            || $permissions->pluck('name')->diff($actor->getAllPermissions()->pluck('name'))->isEmpty();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác định role được phép chỉnh sửa
     * INPUT: Actor và role đã eager load permissions.
     * OUTPUT: Boolean quyền users.manage, guard admin và giới hạn cấp quyền.
     * SIDE EFFECT: Không ghi dữ liệu.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public static function canEditRole(User $actor, Role $role): bool
    {
        return $role->guard_name === 'admin' && $role->name !== 'super-admin'
            && $actor->can('users.manage') && self::canGrant($actor, $role->permissions);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Băm version role để phát hiện thay đổi đồng thời
     * INPUT: Role với permissions đã load.
     * OUTPUT: SHA-256 của tên và permission IDs đã sort.
     * SIDE EFFECT: Hàm thuần trên DTO/relation.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public static function roleVersion(Role $role): string
    {
        return hash('sha256', json_encode([$role->name, $role->permissions->pluck('id')->sort()->values()->all()]));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Băm version gán role của tài khoản admin
     * INPUT: User với roles đã load.
     * OUTPUT: SHA-256 của role IDs đã sort.
     * SIDE EFFECT: Hàm thuần trên relation.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public static function userVersion(User $user): string
    {
        return hash('sha256', json_encode($user->roles->pluck('id')->sort()->values()->all()));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Phân trang role admin
     * INPUT: search/page/per_page đã validate.
     * OUTPUT: Paginator role có permissions và users_count; sort name rồi ID.
     * SIDE EFFECT: Query read-only có eager load/count.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function roles(array $filters): LengthAwarePaginator
    {
        $roleTable = (new Role)->getTable();
        $pivotTable = config('permission.table_names.model_has_roles', 'model_has_roles');
        $pivotRole = app(PermissionRegistrar::class)->pivotRole;
        $userMorph = (new User)->getMorphClass();
        $userCount = DB::table($pivotTable)->selectRaw('count(*)')
            ->whereColumn($pivotRole, $roleTable.'.id')
            ->where('model_type', $userMorph);

        return Role::query()->where('guard_name', 'admin')->with('permissions')
            ->addSelect(['users_count' => $userCount])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')->orderBy('id')->paginate($filters['per_page'] ?? 12);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Nạp chi tiết role với số tài khoản đang sử dụng
     * INPUT: Role guard admin từ route binding hoặc mutation.
     * OUTPUT: Role đã có permissions và users_count.
     * SIDE EFFECT: Query read-only role/pivot.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function roleDetails(Role $role): Role
    {
        return $role->load('permissions')->setAttribute('users_count', $this->userCount($role));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Phân trang tài khoản admin để gán role
     * INPUT: search/status/role_id/page/per_page đã validate.
     * OUTPUT: Paginator User không password/token, eager load role permissions và direct permissions.
     * SIDE EFFECT: Query read-only, sort name rồi ID.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function users(array $filters): LengthAwarePaginator
    {
        return User::query()->select(['id', 'name', 'email', 'username', 'status', 'created_at'])
            ->with(['roles.permissions', 'permissions'])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['role_id'] ?? null, fn ($query, $id) => $query->whereHas('roles', fn ($role) => $role->where('roles.id', $id)->where('guard_name', 'admin')))
            ->orderBy('name')->orderBy('id')->paginate($filters['per_page'] ?? 10);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Phân trang catalog quyền được hỗ trợ
     * INPUT: search/group/page/per_page đã validate.
     * OUTPUT: Paginator quyền admin với roles; chỉ tên trong config.
     * SIDE EFFECT: Query read-only, eager load roles và sort name/ID.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function permissions(array $filters): LengthAwarePaginator
    {
        return Permission::query()->where('guard_name', 'admin')->whereIn('name', self::permissionNames())
            ->with(['roles' => fn ($query) => $query->where('guard_name', 'admin')->orderBy('name')])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('name', 'like', '%'.$search.'%'))
            ->when($filters['group'] ?? null, fn ($query, $group) => $query->whereIn('name', array_map(fn ($action) => $group.'.'.$action, config('permissions.catalog.'.$group, []))))
            ->orderBy('name')->orderBy('id')->paginate($filters['per_page'] ?? 25);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả nhóm quyền và role options thật cho form
     * INPUT: Actor admin đã được cấp quyền xem.
     * OUTPUT: groups, role_options, can_manage và actor_id; mỗi quyền/role có cờ assignable.
     * SIDE EFFECT: Đọc quyền/role đã eager load; không seed hoặc sửa catalog.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function catalog(User $actor): array
    {
        $permissions = Permission::query()->where('guard_name', 'admin')->whereIn('name', self::permissionNames())->orderBy('name')->get();
        $groups = $permissions->groupBy(fn ($permission) => explode('.', $permission->name, 2)[0])->map(function ($items, $group) use ($actor) {
            return ['name' => $group, 'permissions' => $items->map(fn ($permission) => [
                'id' => $permission->id, 'name' => $permission->name,
                'action' => explode('.', $permission->name, 2)[1],
                'assignable' => $actor->can('users.manage') && self::canGrant($actor, collect([$permission])),
            ])->values()->all()];
        })->values()->all();

        return [
            'groups' => $groups,
            'role_options' => Role::query()->where('guard_name', 'admin')->with('permissions')->orderBy('name')->get()
                ->map(fn ($role) => ['id' => $role->id, 'name' => $role->name,
                    'assignable' => $actor->can('users.manage') && self::canGrant($actor, $role->permissions)
                        && ($role->name !== 'super-admin' || $actor->hasRole('super-admin', 'admin')),
                ])->all(),
            'can_manage' => $actor->can('users.manage'),
            'actor_id' => $actor->id,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo hoặc sửa role và gán permission từ catalog
     * INPUT: Actor, name/permission_ids/expected_version đã validate, role optional.
     * OUTPUT: Role đã eager load/count sau mutation.
     * SIDE EFFECT: Ghi role/pivot và audit; không sửa permission catalog.
     * EXCEPTION/TRANSACTION: mutate() mở transaction và lock; root bất biến, tên system role cố định, không tự mất users.manage; lỗi rollback.
     * =====================================================================
     */
    public function save(User $actor, array $data, ?Role $role = null): Role
    {
        return $this->mutate($actor, function (User $freshActor) use ($data, $role): Role {
            $selected = $this->selectedPermissions($data['permission_ids']);
            abort_unless(self::canGrant($freshActor, $selected), 403, 'You can only grant permissions you already have.');
            $before = null;
            if ($role) {
                $role = Role::query()->where('guard_name', 'admin')->with('permissions')->lockForUpdate()->findOrFail($role->id);
                abort_unless(self::canEditRole($freshActor, $role), 403, 'This role is protected or outside your access scope.');
                $this->assertVersion(self::roleVersion($role), $data['expected_version']);
                if (in_array($role->name, self::SYSTEM_ROLES, true) && $role->name !== $data['name']) {
                    throw ValidationException::withMessages(['name' => 'System role names cannot be changed.']);
                }
                $before = ['name' => $role->name, 'permission_ids' => $role->permissions->pluck('id')->all()];
                if ($freshActor->roles->contains('id', $role->id) && $freshActor->can('users.manage')) {
                    $otherPermissions = $freshActor->permissions->pluck('name')->merge($freshActor->roles
                        ->where('id', '!=', $role->id)->flatMap(fn ($other) => $other->permissions->pluck('name')));
                    abort_unless($selected->pluck('name')->merge($otherPermissions)->contains('users.manage'), 422, 'Keep your access management permission.');
                }
            }
            abort_if($data['name'] === 'super-admin', 422, 'The super-admin role is reserved.');
            if (Role::query()->where('guard_name', 'admin')->where('name', $data['name'])
                ->when($role, fn ($query) => $query->where('id', '!=', $role->id))->exists()) {
                throw ValidationException::withMessages(['name' => 'This role name is already in use.']);
            }
            $role ??= new Role;
            $role->forceFill(['name' => $data['name'], 'guard_name' => 'admin'])->save();
            $role->syncPermissions($selected);
            $this->audit($freshActor, $role, $before === null ? 'role_created' : 'role_updated', $before,
                ['name' => $role->name, 'permission_ids' => $selected->pluck('id')->all()]);

            return $this->roleDetails($role);
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xóa custom role chưa được gán
     * INPUT: Actor, role và expected_version.
     * OUTPUT: void sau xóa.
     * SIDE EFFECT: Xóa role/pivot và audit.
     * EXCEPTION/TRANSACTION: Transaction/lock qua mutate(); chặn role mặc định, role đang dùng và version cũ.
     * =====================================================================
     */
    public function remove(User $actor, Role $role, string $version): void
    {
        $this->mutate($actor, function (User $freshActor) use ($role, $version): void {
            $role = Role::query()->where('guard_name', 'admin')->with('permissions')->lockForUpdate()->findOrFail($role->id);
            abort_unless(self::canEditRole($freshActor, $role), 403);
            $this->assertVersion(self::roleVersion($role), $version);
            abort_if(in_array($role->name, self::SYSTEM_ROLES, true), 422, 'System roles cannot be deleted.');
            abort_if($this->userCount($role) > 0, 409, 'Remove this role from its users before deleting it.');
            $this->audit($freshActor, $role, 'role_deleted', ['name' => $role->name, 'permission_ids' => $role->permissions->pluck('id')->all()], null);
            $role->delete();
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Thay danh sách role của một tài khoản admin
     * INPUT: Actor, target User, role_ids và expected_version.
     * OUTPUT: User với roles/permissions mới; direct permissions giữ riêng.
     * SIDE EFFECT: Sync model_has_roles và ghi audit, không sửa customer/password/trạng thái.
     * EXCEPTION/TRANSACTION: Transaction/lock qua mutate(); chặn tự đổi role, quyền cao hơn và version cũ; chỉ root gán hoặc gỡ root.
     * =====================================================================
     */
    public function assign(User $actor, User $user, array $data): User
    {
        return $this->mutate($actor, function (User $freshActor) use ($user, $data): User {
            $user = User::query()->with(['roles.permissions', 'permissions'])->lockForUpdate()->findOrFail($user->id);
            abort_if($freshActor->id === $user->id, 422, 'You cannot change your own roles.');
            $this->assertVersion(self::userVersion($user), $data['expected_version']);
            abort_unless(self::canGrant($freshActor, $user->getAllPermissions())
                && (! $user->hasRole('super-admin', 'admin') || $freshActor->hasRole('super-admin', 'admin')), 403);
            $roles = Role::query()->where('guard_name', 'admin')->whereIn('id', $data['role_ids'])->with('permissions')->lockForUpdate()->get();
            if ($roles->count() !== count($data['role_ids'])) {
                throw ValidationException::withMessages(['role_ids' => 'Choose existing admin roles.']);
            }
            foreach ($roles as $role) {
                abort_unless(self::canGrant($freshActor, $role->permissions)
                    && ($role->name !== 'super-admin' || $freshActor->hasRole('super-admin', 'admin')), 403);
            }
            $before = ['role_ids' => $user->roles->pluck('id')->all()];
            $user->syncRoles($roles);
            $this->audit($freshActor, $user, 'admin_roles_updated', $before, ['role_ids' => $roles->pluck('id')->all()]);

            return $user->load(['roles.permissions', 'permissions']);
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Serialize thay đổi quyền và kiểm lại người quản lý
     * INPUT: Actor và callback mutation.
     * OUTPUT: Kết quả callback.
     * SIDE EFFECT: Lock role super-admin và actor; xóa cache Spatie sau commit.
     * EXCEPTION/TRANSACTION: Transaction retry deadlock tối đa 5; callback/audit lỗi rollback.
     * =====================================================================
     */
    private function mutate(User $actor, Closure $callback): mixed
    {
        return DB::transaction(function () use ($actor, $callback) {
            $root = Role::query()->where('guard_name', 'admin')->where('name', 'super-admin')->lockForUpdate()->first();
            abort_unless($root, 409, 'System roles have not been initialized.');
            $freshActor = User::query()->with(['roles.permissions', 'permissions'])->lockForUpdate()->findOrFail($actor->id);
            abort_unless($freshActor->isActive() && $freshActor->can('users.manage'), 403);
            $result = $callback($freshActor);
            DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());

            return $result;
        }, 5);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đối chiếu permission IDs dưới transaction
     * INPUT: Danh sách IDs đã validate.
     * OUTPUT: Collection quyền guard admin trong catalog.
     * SIDE EFFECT: Đọc và khóa rows permission.
     * EXCEPTION/TRANSACTION: Caller giữ transaction; permission thiếu/ngoài catalog trả 422.
     * =====================================================================
     */
    private function selectedPermissions(array $ids): Collection
    {
        $permissions = Permission::query()->where('guard_name', 'admin')->whereIn('name', self::permissionNames())->whereIn('id', $ids)->lockForUpdate()->get();
        if ($permissions->count() !== count($ids)) {
            throw ValidationException::withMessages(['permission_ids' => 'Choose supported admin permissions.']);
        }

        return $permissions;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đếm tài khoản admin đang dùng một role
     * INPUT: Role guard admin.
     * OUTPUT: Số dòng model_has_roles thuộc morph `user`.
     * SIDE EFFECT: Query read-only pivot.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    private function userCount(Role $role): int
    {
        $pivotTable = config('permission.table_names.model_has_roles', 'model_has_roles');
        $pivotRole = app(PermissionRegistrar::class)->pivotRole;

        return DB::table($pivotTable)->where($pivotRole, $role->getKey())
            ->where('model_type', (new User)->getMorphClass())->count();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn ghi đè version đã thay đổi
     * INPUT: Version thật và expected_version.
     * OUTPUT: void nếu khớp.
     * SIDE EFFECT: Không ghi dữ liệu.
     * EXCEPTION/TRANSACTION: 409 khi conflict; transaction caller rollback.
     * =====================================================================
     */
    private function assertVersion(string $actual, string $expected): void
    {
        abort_unless(hash_equals($actual, $expected), 409, 'Access settings changed. Reload before saving.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi audit mutation role hoặc tài khoản admin
     * INPUT: Actor, model subject, event và snapshot IDs trước/sau.
     * OUTPUT: void.
     * SIDE EFFECT: Ghi activity_log, không ghi credential/email thật vào properties.
     * EXCEPTION/TRANSACTION: Phải nằm trong transaction mutation; audit lỗi rollback.
     * =====================================================================
     */
    private function audit(User $actor, Model $subject, string $event, ?array $before, ?array $after): void
    {
        activity('access')->causedBy($actor)->performedOn($subject)->event($event)
            ->withProperties(['before' => $before, 'after' => $after])->log($event);
    }
}
