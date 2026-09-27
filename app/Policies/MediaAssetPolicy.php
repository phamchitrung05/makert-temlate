<?php

namespace App\Policies;

use App\Models\MediaAsset;
use App\Models\User;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Phân quyền truy cập và thao tác MediaAsset
 * =====================================================================
 *
 * Policy là lớp authorization theo model cho admin API. Permission middleware
 * vẫn được dùng ở route để chặn coarse access; policy bảo đảm các action
 * attach/delete/retry không tự bypass khi được gọi từ service hoặc controller.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - viewAny(), view(): xem danh sách hoặc asset
 * - create(): tạo/upload asset
 * - update(): sửa metadata asset
 * - delete(): soft-delete asset
 * - attach(), detach(): gắn hoặc tháo asset khỏi model nghiệp vụ
 * - retry(): retry scan/conversion thất bại
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : User admin và MediaAsset khi action yêu cầu model
 * - OUTPUT: bool cho phép hoặc từ chối action theo Spatie permission
 * =====================================================================
 */
class MediaAssetPolicy
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra admin được xem danh sách asset
     * =====================================================================
     *
     * INPUT: $user là admin đã xác thực bằng Sanctum.
     * OUTPUT: bool theo permission media.view.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('media.view');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra admin được xem một asset
     * =====================================================================
     *
     * INPUT: $user và $mediaAsset cần xem.
     * OUTPUT: bool theo permission media.view; private asset cần media.upload.
     */
    public function view(User $user, MediaAsset $mediaAsset): bool
    {
        return $user->can('media.view')
            && ($mediaAsset->visibility->value === 'public' || $user->can('media.upload'));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra admin được upload asset
     * =====================================================================
     *
     * INPUT: $user admin hiện tại.
     * OUTPUT: bool theo permission media.upload.
     */
    public function create(User $user): bool
    {
        return $user->can('media.upload');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra admin được cập nhật metadata asset
     * =====================================================================
     *
     * INPUT: $user và $mediaAsset cần cập nhật.
     * OUTPUT: bool theo permission media.upload.
     */
    public function update(User $user, MediaAsset $mediaAsset): bool
    {
        return $user->can('media.upload');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra admin được soft-delete asset
     * =====================================================================
     *
     * INPUT: $user và $mediaAsset cần xóa.
     * OUTPUT: bool theo permission media.delete.
     */
    public function delete(User $user, MediaAsset $mediaAsset): bool
    {
        return $user->can('media.delete');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra admin được attach asset vào model nghiệp vụ
     * =====================================================================
     *
     * INPUT: $user và $mediaAsset được attach.
     * OUTPUT: bool theo permission media.attach.
     */
    public function attach(User $user, MediaAsset $mediaAsset): bool
    {
        return $user->can('media.attach');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra admin được detach asset khỏi model nghiệp vụ
     * =====================================================================
     *
     * INPUT: $user và $mediaAsset được detach.
     * OUTPUT: bool theo permission media.attach.
     */
    public function detach(User $user, MediaAsset $mediaAsset): bool
    {
        return $user->can('media.attach');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra admin được retry scan hoặc conversion
     * =====================================================================
     *
     * INPUT: $user và $mediaAsset cần retry.
     * OUTPUT: bool theo permission media.retry.
     */
    public function retry(User $user, MediaAsset $mediaAsset): bool
    {
        return $user->can('media.retry');
    }
}
