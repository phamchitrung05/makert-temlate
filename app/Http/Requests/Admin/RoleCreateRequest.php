<?php

namespace App\Http\Requests\Admin;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate request tạo role tùy chỉnh của admin
 * =====================================================================
 * Tách boundary tạo khỏi cập nhật; dùng rules tên/catalog và users.manage từ
 * RoleSaveRequest. Tạo mới không cần expected_version vì chưa có bản ghi.
 * CÁC HÀM/METHOD TRONG FILE: rules(), authorize(), prepareForValidation() kế thừa.
 * INPUT/OUTPUT CỦA CLASS: name/permission_ids -> payload đã validate.
 * SIDE EFFECT: Query validation role/permission; không ghi database.
 * EXCEPTION/TRANSACTION: Thiếu quyền trả 403, validation trả 422; không transaction.
 * =====================================================================
 */
final class RoleCreateRequest extends RoleSaveRequest {}
