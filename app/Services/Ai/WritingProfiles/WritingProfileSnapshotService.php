<?php

namespace App\Services\Ai\WritingProfiles;

use App\Models\AiWritingProfile;
use App\Services\Ai\Settings\AiSettingsService;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chụp profile đã bật để article run không phụ thuộc chỉnh sửa sau.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận AiSettingsService để resolve default profile.
 * - snapshot(): đọc profile active/enabled và tạo snapshot immutable.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ID profile rõ ràng hoặc null để dùng mặc định website.
 * - OUTPUT: snapshot immutable theo dữ liệu, hoặc null khi không có mặc định.
 * - SIDE EFFECT: chỉ đọc Settings/profile; không phân tích lại hoặc ghi DB.
 * =====================================================================
 */
final class WritingProfileSnapshotService
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận service typed Settings.
     * =====================================================================
     * Input: AiSettingsService. Output: dependency lưu trong service.
     * Side effect: không đọc/ghi database.
     * =====================================================================
     */
    public function __construct(private readonly AiSettingsService $settings) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve profile bật và đóng băng toàn bộ hướng dẫn đã duyệt.
     * =====================================================================
     * Input: profileId nullable; null dùng website default.
     * Output: id/version/name/rules/style_instructions/evidence hoặc null.
     * Side effect: chỉ đọc; ValidationException nếu ID không hợp lệ/bị tắt.
     * =====================================================================
     */
    public function snapshot(?int $profileId = null): ?array
    {
        $profileId ??= $this->settings->all()['default_writing_profile_id'] ?? null;
        if ($profileId === null) {
            return null;
        }
        $profile = AiWritingProfile::query()->where('status', 'active')->where('is_enabled', true)->find($profileId);
        if (! $profile) {
            throw ValidationException::withMessages(['writing_profile_id' => 'Mẫu văn phong không tồn tại hoặc đã bị tắt.']);
        }

        return [
            'id' => $profile->id, 'version' => $profile->version, 'name' => $profile->name,
            'rules' => $profile->rules_json, 'style_instructions' => $profile->style_instructions,
            'evidence' => $profile->evidence_json ?? [],
        ];
    }
}
