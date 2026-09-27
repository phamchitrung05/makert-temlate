<?php

namespace App\Http\Controllers;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Giữ tên Controller tương thích với Laravel skeleton
 * =====================================================================
 *
 * Các controller cũ đang kế thừa class này sẽ tự động nhận helper HTTP từ
 * BaseController. Controller mới có thể kế thừa trực tiếp BaseController hoặc
 * một base chuyên biệt như BaseCrudController mà không phải đổi đồng loạt.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - Không có; kế thừa toàn bộ helper từ BaseController
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTTP request do controller con tiếp nhận
 * - OUTPUT: response do controller con tạo
 * =====================================================================
 */
abstract class Controller extends BaseController {}
