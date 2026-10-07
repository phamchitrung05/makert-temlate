<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Serialize quyền catalog và role đang sử dụng
 * =====================================================================
 * Trang Permissions chỉ đọc catalog config-backed; không cung cấp CRUD permission name.
 * CÁC HÀM/METHOD TRONG FILE: toArray().
 * INPUT/OUTPUT CỦA CLASS: Permission trong catalog đã load roles -> ID/name/group/action/roles/ngày tạo.
 * SIDE EFFECT: Serialize dữ liệu đã nạp; không query hoặc ghi database.
 * EXCEPTION/TRANSACTION: Caller giới hạn catalog resource.action; không mở transaction.
 * =====================================================================
 */
final class AccessPermissionResource extends JsonResource
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Chuyển quyền catalog thành dòng bảng
     * INPUT: Permission có roles eager loaded và Request.
     * OUTPUT: ID/name/group/action/roles/created_at.
     * SIDE EFFECT: Không ghi dữ liệu.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function toArray(Request $request): array
    {
        [$group, $action] = explode('.', $this->name, 2);

        return ['id' => $this->id, 'name' => $this->name, 'group' => $group, 'action' => $action,
            'roles' => $this->roles->map(fn ($role) => $role->only(['id', 'name']))->values()->all(),
            'created_at' => $this->created_at?->toIso8601String()];
    }
}
