<?php

namespace Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cấu hình database SQLite in-memory riêng cho test
 * =====================================================================
 *
 * Test chạy trên database development MySQL sẽ vô tình xoá dữ liệu thật nếu
 * dùng RefreshDatabase. Trait này ép mỗi test về một connection SQLite
 * in-memory được migrate trong setUp và rollback trong tearDown, nên test
 * không cần database `testing` riêng và không đụng tới dữ liệu development.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - useIsolatedDatabase(): migrate SQLite in-memory cho test hiện tại
 * - tearDownIsolatedDatabase(): rollback schema và giải phóng connection
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : không có; cấu hình nằm trong trait
 * - OUTPUT: connection `isolated_test` trỏ tới SQLite in-memory
 *
 * SIDE EFFECT:
 * - Đổi database.default sang `isolated_test` trong suốt test
 *
 * EXCEPTION/TRANSACTION:
 * - Không mở transaction thủ công; Laravel tự bọc transaction cho test
 * =====================================================================
 */
trait UsesIsolatedDatabase
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Migrate SQLite in-memory rồi trỏ database.default sang đó
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Ghi config database và chạy migrate cho connection isolated_test
     */
    protected function useIsolatedDatabase(): void
    {
        Config::set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        Config::set('cache.default', 'array');
        Config::set('permission.cache.store', 'array');
        Config::set('database.connections.isolated_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        Config::set('database.default', 'isolated_test');

        Artisan::call('migrate', [
            '--database' => 'isolated_test',
            '--force' => true,
        ]);

        // Spatie Permission cache giữ quyền đã nạp; xoá sau mỗi lần
        // migrate để test cấp quyền mới nhận giá trị mới ngay.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Rollback schema và giải phóng connection sau test
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Reset migration rồi purge connection isolated_test
     */
    protected function tearDownIsolatedDatabase(): void
    {
        Artisan::call('migrate:reset', [
            '--database' => 'isolated_test',
            '--force' => true,
        ]);

        DB::purge('isolated_test');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xoá cache permission của Spatie giữa các bước trong test
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Gọi forgetCachedPermissions(); không đụng dữ liệu
     */
    protected function flushPermissionCache(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
