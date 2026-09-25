<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Seed dữ liệu tối thiểu cho môi trường development/test
 * =====================================================================
 *
 * Seeder gọi role/permission catalog và tạo một admin local để kiểm tra
 * authentication V1. Không seed customer OAuth giả nếu không có identity
 * provider hợp lệ.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - run(): gọi các seeder nền và tạo admin development
 * =====================================================================
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo dữ liệu nền cho development/test
     * =====================================================================
     *
     * OUTPUT:
     * - Không trả giá trị; database có permission catalog và admin mẫu
     *
     * SIDE EFFECT:
     * - INSERT role/permission và một admin account development
     * =====================================================================
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $admin = User::factory()->create([
            'name' => 'Development Admin',
            'email' => 'admin@example.com',
            'username' => 'admin',
            'status' => 'active',
        ]);

        $admin->assignRole('super-admin');
    }
}
