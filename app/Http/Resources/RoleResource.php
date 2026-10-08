<?php

namespace App\Http\Resources;

use App\Services\Access\RoleManagementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Serialize role, quyền và cờ thao tác an toàn
 * =====================================================================
 * Caller eager load permissions và users_count; DTO có version để tránh ghi đè.
 * CÁC HÀM/METHOD TRONG FILE: toArray().
 * INPUT/OUTPUT CỦA CLASS: Role có permissions/users_count và actor -> DTO role/version/cờ thao tác.
 * SIDE EFFECT: Đọc quyền actor; không ghi database hoặc gọi dịch vụ ngoài.
 * EXCEPTION/TRANSACTION: Route phải xác thực User; resource không mở transaction.
 * =====================================================================
 */
final class RoleResource extends JsonResource
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Chuyển role thành DTO cho card/editor
     * INPUT: Request actor và Role eager loaded.
     * OUTPUT: ID/name/permissions/users_count/version/is_system/can_edit/can_delete.
     * SIDE EFFECT: Không ghi dữ liệu; đọc actor permissions.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function toArray(Request $request): array
    {
        $system = in_array($this->name, RoleManagementService::SYSTEM_ROLES, true);
        $editable = RoleManagementService::canEditRole($request->user(), $this->resource);
        $deletable = RoleManagementService::canDeleteRoleModel($request->user(), $this->resource);

        return [
            'id' => $this->id, 'name' => $this->name,
            'permissions' => $this->permissions->sortBy('name')->map(fn ($permission) => $permission->only(['id', 'name']))->values()->all(),
            'users_count' => (int) $this->users_count,
            'version' => RoleManagementService::roleVersion($this->resource),
            'is_system' => $system, 'can_edit' => $editable,
            'can_delete' => $deletable && ! $system && (int) $this->users_count === 0,
        ];
    }
}
