<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Cấp quyền Settings cho admin/super-admin; giữ quyền tùy chỉnh.
     */
    public function up(): void
    {
        foreach (['settings.view', 'settings.manage'] as $name) {
            $permission = Permission::findOrCreate($name, 'admin');
            Role::query()->where('guard_name', 'admin')->whereIn('name', ['admin', 'super-admin'])
                ->get()->each(fn (Role $role) => $role->givePermissionTo($permission));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Không thu hồi quyền có thể đã được quản trị viên cấp sau triển khai.
     */
    public function down(): void
    {
        //
    }
};
