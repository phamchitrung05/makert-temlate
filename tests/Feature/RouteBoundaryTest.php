<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử boundary route giữa public Blade và admin SPA
 * =====================================================================
 *
 * File test bảo đảm trang chủ public render bằng Blade và admin SPA chỉ mount
 * khi truy cập dưới tiền tố /admin, tránh việc URL gốc tình cờ boot admin app.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - test_root_renders_public_home_page(): GET / trả HTML public
 * - test_admin_shell_only_mounts_under_admin_prefix(): /admin trả HTML admin
 * =====================================================================
 */
class RouteBoundaryTest extends TestCase
{
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
