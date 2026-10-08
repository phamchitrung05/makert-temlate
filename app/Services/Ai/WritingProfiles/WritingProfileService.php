<?php

namespace App\Services\Ai\WritingProfiles;

use App\Models\AiWritingProfile;
use App\Models\AiWritingProfileAnalysis;
use App\Services\Ai\Settings\AiSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: CRUD profile đã duyệt, giữ phiên bản và metadata nguồn phía server.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: __construct(), create(), update(), delete(), clearDefault(), analysis().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload FormRequest, actor ID và version optimistic lock.
 * - OUTPUT: profile đã lưu hoặc lỗi xung đột 409; chỉ lưu khi người dùng yêu cầu.
 * - SIDE EFFECT: transaction ghi profile/Settings; không gọi model.
 * =====================================================================
 */
final class WritingProfileService
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận validator contract và Settings.
     * =====================================================================
     * Input: các service typed. Output: dependencies của CRUD.
     * Side effect: không đọc/ghi database.
     * =====================================================================
     */
    public function __construct(private readonly WritingProfileDefinition $definition, private readonly AiSettingsService $settings) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Lưu mẫu thủ công hoặc mẫu được duyệt từ analysis ready.
     * =====================================================================
     * Input: values whitelist và actorId. Output: AiWritingProfile version 1.
     * Side effect: tạo profile trong transaction; không phân tích/lưu tự động.
     * =====================================================================
     */
    public function create(array $values, int $actorId): AiWritingProfile
    {
        $this->definition->validateRules($values['rules_json']);

        return DB::transaction(function () use ($values, $actorId): AiWritingProfile {
            $analysis = isset($values['analysis_id']) ? $this->analysis($values['analysis_id'], $actorId) : null;
            $evidence = $values['evidence_json'] ?? [];
            if ($analysis) {
                $this->definition->validateEvidence($evidence, $analysis->reference_text);
            } elseif ($evidence !== []) {
                throw ValidationException::withMessages(['evidence_json' => 'Mẫu thủ công không có bài tham khảo để kiểm tra trích đoạn.']);
            }

            return AiWritingProfile::query()->create([
                'name' => $values['name'], 'description' => $values['description'] ?? null,
                'rules_json' => $values['rules_json'], 'evidence_json' => $evidence,
                'style_instructions' => $values['style_instructions'], 'is_enabled' => $values['is_enabled'] ?? true,
                'version' => 1, 'created_by' => $actorId, 'origin' => $analysis ? 'reference' : 'manual', 'status' => 'active',
                'source_hash' => $analysis?->source_hash,
                'analysis_metadata_json' => $analysis ? [
                    'analysis_id' => $analysis->id, 'provider' => $analysis->connection_snapshot_json['provider'] ?? null,
                    'model' => $analysis->connection_snapshot_json['model'] ?? null,
                    'prompt_version' => $analysis->prompt_version, 'schema_version' => $analysis->schema_version,
                ] : null,
            ]);
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Sửa/bật/tắt profile bằng optimistic version và row lock.
     * =====================================================================
     * Input: profile identity, values và actorId. Output: profile tăng version.
     * Side effect: ghi transaction; 409 khi version cũ; tắt mẫu sẽ gỡ mặc định.
     * =====================================================================
     */
    public function update(AiWritingProfile $profile, array $values, int $actorId): AiWritingProfile
    {
        return DB::transaction(function () use ($profile, $values, $actorId): AiWritingProfile {
            // =====================================================================
            // Thứ tự lock thống nhất với Settings: Settings group rồi profile.
            // =====================================================================
            DB::table('settings')->where('group', 'ai')->lockForUpdate()->get();
            $current = AiWritingProfile::query()->lockForUpdate()->findOrFail($profile->id);
            abort_if($current->version !== (int) $values['version'], 409, 'Mẫu văn phong đã được chỉnh sửa. Hãy tải lại trước khi lưu.');
            if (isset($values['rules_json'])) {
                $this->definition->validateRules($values['rules_json']);
            }
            if (array_key_exists('evidence_json', $values)) {
                $evidence = $values['evidence_json'];
                $analysisId = $current->analysis_metadata_json['analysis_id'] ?? null;
                $source = $analysisId ? AiWritingProfileAnalysis::query()->find($analysisId) : null;
                if ($source) {
                    $this->definition->validateEvidence($evidence, $source->reference_text);
                } elseif (array_diff(array_map('json_encode', $evidence), array_map('json_encode', $current->evidence_json ?? [])) !== []) {
                    throw ValidationException::withMessages(['evidence_json' => 'Bài mẫu đã hết hạn; chỉ có thể giữ hoặc xóa bằng chứng đã duyệt.']);
                }
            }
            unset($values['version'], $values['analysis_id']);
            $current->fill($values)->forceFill([
                'version' => $current->version + 1,
                // Bấm lưu trên trang edit là hành động duyệt bản nháp.
                'status' => 'active',
            ])->save();
            if (! $current->is_enabled) {
                $this->clearDefault($current->id, $actorId);
            }

            return $current;
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xóa profile bằng version; snapshot run lịch sử tồn tại độc lập.
     * =====================================================================
     * Input: profile, expectedVersion và actorId. Output: void.
     * Side effect: xóa profile và gỡ mặc định; 409 khi version cũ.
     * =====================================================================
     */
    public function delete(AiWritingProfile $profile, int $expectedVersion, int $actorId): void
    {
        DB::transaction(function () use ($profile, $expectedVersion, $actorId): void {
            DB::table('settings')->where('group', 'ai')->lockForUpdate()->get();
            $current = AiWritingProfile::query()->lockForUpdate()->findOrFail($profile->id);
            abort_if($current->version !== $expectedVersion, 409, 'Mẫu văn phong đã được chỉnh sửa. Hãy tải lại trước khi xóa.');
            $this->clearDefault($current->id, $actorId);
            $current->delete();
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gỡ default khi profile bị tắt hoặc xóa.
     * =====================================================================
     * Input: profileId và actor. Output: void; Settings cập nhật khi cần.
     * Side effect: ghi typed Settings/audit; không đổi run snapshot.
     * =====================================================================
     */
    private function clearDefault(int $profileId, int $actorId): void
    {
        if (($this->settings->all()['default_writing_profile_id'] ?? null) === $profileId) {
            $this->settings->update(['default_writing_profile_id' => null], $actorId);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy analysis của actor đã ready và chưa hết hạn để duyệt.
     * =====================================================================
     * Input: UUID/actor. Output: analysis locked hoặc validation error.
     * Side effect: đọc và khóa row trong transaction; không gửi provider.
     * =====================================================================
     */
    private function analysis(string $id, int $actorId): AiWritingProfileAnalysis
    {
        $analysis = AiWritingProfileAnalysis::query()->lockForUpdate()->find($id);
        if (! $analysis || (int) $analysis->created_by !== $actorId || $analysis->status !== 'ready' || $analysis->expires_at->isPast()) {
            throw ValidationException::withMessages(['analysis_id' => 'Chỉ được lưu phân tích của bạn đã hoàn thành và còn thời hạn.']);
        }

        return $analysis;
    }
}
