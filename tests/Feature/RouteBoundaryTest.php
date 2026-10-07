<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử boundary route giữa public Blade và admin SPA
 * =====================================================================
 *
 * PHPUnit bảo đảm trang chủ public render bằng Blade, admin SPA chỉ mount dưới
 * tiền tố /admin; URL gốc không boot admin app. Database SQLite in-memory riêng
 * phục vụ HTTP/render assertions, không thay dữ liệu ứng dụng hoặc gọi AI.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp().
 * - tearDown().
 * - test_root_renders_public_home_page().
 * - test_admin_shell_only_mounts_under_admin_prefix().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Fixtures, HTTP request và dependency test đã cô lập.
 * - OUTPUT: Assertions cho output, quyền, validation và lifecycle hiện có.
 * - SIDE EFFECT: Tạo/đọc/sửa dữ liệu ở DB test; setup/teardown quản lý schema và connection riêng.
 * - EXCEPTION/TRANSACTION: Lỗi assertion/dependency truyền ra PHPUnit; transaction code nghiệp vụ chạy trong môi trường test.
 * =====================================================================
 */
class RouteBoundaryTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị môi trường cô lập trước mỗi ca kiểm thử
     * =====================================================================
     *
     * INPUT:
     * - Không có tham số; PHPUnit gọi trước mỗi ca test.
     *
     * OUTPUT:
     * - Database test riêng và dependency đã khởi tạo.
     *
     * SIDE EFFECT:
     * - Khởi tạo app và schema SQLite in-memory qua setup của class; không đổi database ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi setup/schema truyền ra PHPUnit; không mở transaction nghiệp vụ bao toàn bộ ca test.
     *
     * =====================================================================
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn môi trường cô lập sau mỗi ca kiểm thử
     * =====================================================================
     *
     * INPUT:
     * - Không có tham số; PHPUnit gọi sau mỗi ca test.
     *
     * OUTPUT:
     * - Schema/connection test và trạng thái framework đã được dọn.
     *
     * SIDE EFFECT:
     * - Rollback hoặc drop schema test và giải phóng connection theo teardown của class; không dọn dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi teardown truyền ra PHPUnit; không gọi provider hoặc mở transaction nghiệp vụ mới.
     *
     * =====================================================================
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác nhận route gốc render trang chủ public bằng Blade
     * =====================================================================
     *
     * INPUT:
     * - HTTP GET / không có tham số
     *
     * OUTPUT:
     * - HTTP 200, chứa app container của layout public và không phải admin shell
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_root_renders_public_home_page(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertViewIs('public.home')
            ->assertSee('id="main-content"', false);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác nhận admin Vue chỉ mount dưới tiền tố /admin
     * =====================================================================
     *
     * INPUT:
     * - HTTP GET /admin và GET /admin/apps/users
     *
     * OUTPUT:
     * - HTTP 200 cho cả hai URL, dùng chung admin SPA shell
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_admin_shell_only_mounts_under_admin_prefix(): void
    {
        $this->get('/admin')
            ->assertOk()
            ->assertViewIs('admin')
            ->assertSee('id="app"', false);

        $this->get('/admin/apps/users')
            ->assertOk()
            ->assertViewIs('admin');
    }
}
