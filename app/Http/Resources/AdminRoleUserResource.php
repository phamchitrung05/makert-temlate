<?php

namespace App\Http\Resources;

use App\Services\Access\RoleManagementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Serialize tài khoản admin cho bảng gán role
 * =====================================================================
 * Chỉ field hồ sơ cần hiển thị; không password/token/customer. Eager load roles.permissions và permissions trước serialize.
 * CÁC HÀM/METHOD TRONG FILE: toArray().
 * INPUT/OUTPUT CỦA CLASS: User với roles/permissions và actor -> DTO hồ sơ tối thiểu/version/cờ chỉnh role.
 * SIDE EFFECT: Đọc quyền actor; không ghi database hoặc gọi dịch vụ ngoài.
 * EXCEPTION/TRANSACTION: Route phải xác thực User; resource không mở transaction.
 * =====================================================================
 */
final class AdminRoleUserResource extends JsonResource
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo DTO và quyền chỉnh role tài khoản
     * INPUT: Request actor và User eager loaded.
     * OUTPUT: Hồ sơ tối thiểu, roles, version, can_edit_roles.
     * SIDE EFFECT: Không ghi dữ liệu.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function toArray(Request $request): array
    {
        $actor = $request->user();

        return [
            'id' => $this->id, 'name' => $this->name, 'email' => $this->email,
            'username' => $this->username, 'status' => $this->status,
            'roles' => $this->roles->where('guard_name', 'admin')->map(fn ($role) => $role->only(['id', 'name']))->values()->all(),
            'version' => RoleManagementService::userVersion($this->resource),
            'can_edit_roles' => $actor->can('users.manage') && $actor->id !== $this->id
                && RoleManagementService::canGrant($actor, $this->resource->getAllPermissions())
                && (! $this->resource->hasRole('super-admin', 'admin') || $actor->hasRole('super-admin', 'admin')),
        ];
    }
}
