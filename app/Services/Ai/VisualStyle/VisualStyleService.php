<?php

namespace App\Services\Ai\VisualStyle;

use App\Models\AiImageProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Nghiệp vụ CRUD/lifecycle của Visual Style (ai_image_profiles).
 * =====================================================================
 * Service phục vụ VSE-3: danh sách có filter, tạo style thủ công (draft),
 * cập nhật DNA đã chỉnh (bắt buộc expected_version, validate bằng
 * VisualStyleDnaDefinition rồi version++), activate/archive/duplicate.
 * Phân tích ảnh → DNA nằm ở VisualStyleAnalyzerService; file này không gọi AI.
 * CÁC HÀM/METHOD TRONG FILE:
 * - index(): paginate theo owner + search name/category + filter status.
 * - store(): tạo style thủ công status=draft (không có reference image).
 * - update(): đổi name/category/is_enabled của style.
 * - updateDna(): validate DNA, optimistic lock theo expected_version, version++.
 * - activate(): bật style khi đã analyzed/ready và dna_json hợp lệ.
 * - archive(): chuyển status archived, tắt is_enabled.
 * - duplicate(): copy style + DNA + shots sang bản draft mới.
 * - destroy(): xóa style + file tham chiếu khi không còn queued/analyzing.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : filters/values/DNA đã qua FormRequest, actor id.
 * - OUTPUT: AiImageProfile/paginator cho controller wrap Resource.
 * - SIDE EFFECT: DB + xóa file local khi destroy; không gọi provider AI.
 * - EXCEPTION/TRANSACTION: updateDna/duplicate dùng transaction; activate kiểm
 *   lifecycle và ném ValidationException khi style chưa đủ điều kiện.
 * =====================================================================
 */
final class VisualStyleService
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận definition DNA để validate trước khi lưu/activate.
     * =====================================================================
     * Input: VisualStyleDnaDefinition. Output: service có dependency.
     * Side effect: không ghi DB.
     * =====================================================================
     */
    public function __construct(private readonly VisualStyleDnaDefinition $definition) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Liệt kê style của actor, hỗ trợ search và filter status.
     * =====================================================================
     * Input: filters (search theo name/category, status, per_page), actorId.
     * Output: paginator style kèm shots_count; sort mới nhất trước.
     * Side effect: chỉ đọc DB; eager count shots cho grid.
     * =====================================================================
     */
    public function index(array $filters, int $actorId): LengthAwarePaginator
    {
        return AiImageProfile::query()
            ->where('created_by', $actorId)
            ->withCount('shots')
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->where(function ($sub) use ($filters): void {
                $search = '%'.trim((string) $filters['search']).'%';
                $sub->where('name', 'like', $search)->orWhere('category', 'like', $search);
            }))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', (string) $filters['status']))
            ->latest('id')
            ->paginate(min(50, max(1, (int) ($filters['per_page'] ?? 20))));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo style thủ công không cần ảnh phân tích.
     * =====================================================================
     * Input: values (name, category tùy chọn, dna tùy chọn đã qua FormRequest),
     * actorId. Output: style status=draft; nếu có dna thì validate + version=1.
     * Side effect: INSERT ai_image_profiles; không dispatch job nào.
     * =====================================================================
     */
    public function store(array $values, int $actorId): AiImageProfile
    {
        $dna = null;
        if (isset($values['dna']) && is_array($values['dna'])) {
            $dna = $this->definition->validate($values['dna']);
            unset($dna['suggested_shots']);
        }

        return AiImageProfile::query()->create([
            'created_by' => $actorId,
            'name' => $values['name'],
            'category' => $values['category'] ?? null,
            'status' => 'draft',
            'is_enabled' => false,
            'reference_path' => '',
            'reference_mime' => '',
            'reference_hash' => str_repeat('0', 64),
            'connection_snapshot_json' => [],
            'analysis_instructions' => '',
            'dna_json' => $dna,
            'schema_version' => $dna ? VisualStyleDnaDefinition::SCHEMA_VERSION : null,
            'version' => 1,
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cập nhật metadata style (name/category/is_enabled).
     * =====================================================================
     * Input: style và values allowlist. Output: style đã refresh.
     * Side effect: UPDATE ai_image_profiles; is_enabled chỉ bật khi status hợp lệ
     * — nếu cố bật style chưa active thì ném ValidationException.
     * =====================================================================
     */
    public function update(AiImageProfile $style, array $values): AiImageProfile
    {
        if (($values['is_enabled'] ?? false) && ! in_array($style->status, ['active'], true)) {
            throw ValidationException::withMessages(['is_enabled' => 'Chỉ bật style đã activate.']);
        }
        $style->fill([
            'name' => $values['name'] ?? $style->name,
            'category' => array_key_exists('category', $values) ? $values['category'] : $style->category,
        ]);
        if (array_key_exists('is_enabled', $values)) {
            $style->is_enabled = (bool) $values['is_enabled'];
        }
        $style->save();

        return $style->refresh();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lưu DNA đã chỉnh với optimistic lock theo expected_version.
     * =====================================================================
     * Input: style, mảng dna thô, expected_version client gửi.
     * Output: style có dna_json mới, schema_version mới nhất, version tăng 1.
     * Side effect: UPDATE trong transaction có lockForUpdate; conflict version
     * ném ValidationException để client tải lại.
     * =====================================================================
     */
    public function updateDna(AiImageProfile $style, array $dna, int $expectedVersion): AiImageProfile
    {
        $normalized = $this->definition->validate($dna);
        unset($normalized['suggested_shots']);

        return DB::transaction(function () use ($style, $normalized, $expectedVersion): AiImageProfile {
            $current = AiImageProfile::query()->lockForUpdate()->findOrFail($style->id);
            if ((int) $current->version !== $expectedVersion) {
                throw ValidationException::withMessages(['expected_version' => 'DNA đã được chỉnh sửa bởi phiên khác; hãy tải lại trước khi lưu.']);
            }
            $current->forceFill([
                'dna_json' => $normalized,
                'schema_version' => VisualStyleDnaDefinition::SCHEMA_VERSION,
                'version' => $current->version + 1,
            ])->save();

            return $current->refresh();
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Activate style — yêu cầu đã phân tích và DNA hợp lệ.
     * =====================================================================
     * Input: style. Output: style status=active + is_enabled=true.
     * Side effect: UPDATE status/is_enabled; ValidationException khi status
     * chưa analyzed/ready hoặc dna_json rỗng/sai schema.
     * =====================================================================
     */
    public function activate(AiImageProfile $style): AiImageProfile
    {
        if (! in_array($style->status, ['analyzed', 'ready', 'draft'], true) || ! is_array($style->dna_json) || $style->dna_json === []) {
            throw ValidationException::withMessages(['style' => 'Style cần có DNA hợp lệ (đã phân tích hoặc nhập tay) trước khi activate.']);
        }
        // Re-validate DNA lưu sẵn để chặn activate dữ liệu cũ/sai schema.
        $this->definition->validate($style->dna_json);
        $style->forceFill(['status' => 'active', 'is_enabled' => true])->save();

        return $style->refresh();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Archive style và tắt cờ dùng cho generation.
     * =====================================================================
     * Input: style. Output: style status=archived + is_enabled=false.
     * Side effect: UPDATE; style queued/analyzing bị chặn ở controller/409.
     * =====================================================================
     */
    public function archive(AiImageProfile $style): AiImageProfile
    {
        abort_if(in_array($style->status, ['queued', 'analyzing'], true), 409, 'Chờ phân tích hoàn tất trước khi archive.');
        $style->forceFill(['status' => 'archived', 'is_enabled' => false])->save();

        return $style->refresh();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Nhân bản style cùng DNA và toàn bộ shot recipes sang bản draft.
     * =====================================================================
     * Input: style nguồn, actorId. Output: style mới status=draft, version=1,
     * is_enabled=false; shots copy nguyên override/sort nhưng giữ is_active gốc.
     * Side effect: transaction INSERT style mới + shots; không copy file ảnh
     * (bản mới không reference_path — coi như style thủ công).
     * =====================================================================
     */
    public function duplicate(AiImageProfile $style, int $actorId): AiImageProfile
    {
        return DB::transaction(function () use ($style, $actorId): AiImageProfile {
            $copy = AiImageProfile::query()->create([
                'created_by' => $actorId,
                'name' => $style->name.' (bản sao)',
                'category' => $style->category,
                'status' => 'draft',
                'is_enabled' => false,
                'reference_path' => '',
                'reference_mime' => '',
                'reference_hash' => str_repeat('0', 64),
                'connection_snapshot_json' => [],
                'analysis_instructions' => '',
                'dna_json' => $style->dna_json,
                'schema_version' => $style->schema_version,
                'summary' => $style->summary,
                'version' => 1,
            ]);
            foreach ($style->shots()->get() as $shot) {
                $copy->shots()->create($shot->only([
                    'code', 'name', 'description', 'composition_override_json',
                    'camera_override_json', 'prompt_suffix', 'suggested_aspect_ratio',
                    'sort_order', 'is_active',
                ]));
            }

            return $copy->refresh()->loadCount('shots');
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xóa style và file tham chiếu local (nếu có).
     * =====================================================================
     * Input: style. Output: void.
     * Side effect: DELETE ai_image_profiles (shots cascade ở DB) + xóa file;
     * style đang queued/analyzing bị chặn 409.
     * =====================================================================
     */
    public function destroy(AiImageProfile $style): void
    {
        abort_if(in_array($style->status, ['queued', 'analyzing'], true), 409, 'Chờ phân tích hoàn tất trước khi xóa.');
        $path = $style->reference_path;
        $style->delete();
        if (is_string($path) && $path !== '') {
            Storage::disk('local')->delete($path);
        }
    }
}
