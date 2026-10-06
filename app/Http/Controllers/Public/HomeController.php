<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\Settings\ProjectSettingsService;
use Illuminate\Contracts\View\View;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Render trang chủ public bằng Blade
 * =====================================================================
 *
 * Controller public chỉ điều phối HTTP và trả về view. Không truy vấn database
 * ở controller; các trang catalog sau này sẽ dùng query object hoặc Action
 * riêng rồi truyền dữ liệu xuống view.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - index(): trả trang chủ public dạng Blade placeholder
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : không có input ngoài HTTP request hiện tại
 * - OUTPUT: View `public.home` kế thừa layout `layouts.public`
 * - SIDE EFFECT: không ghi database, không phát event
 * =====================================================================
 */
class HomeController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Render trang chủ public
     * =====================================================================
     *
     * INPUT:
     * - Không có; route GET / không nhận tham số
     *
     * OUTPUT:
     * - View: Blade view `public.home`
     *
     * SIDE EFFECT:
     * - Không có; truy cập database sẽ được thêm ở task catalog sau
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction và không ném exception nghiệp vụ
     * =====================================================================
     */
    public function index(ProjectSettingsService $settings): View
    {
        return view('public.home', [
            'site' => $settings->effective('site'), 'seo' => $settings->effective('seo'),
        ]);
    }
}
