<?php

namespace App\Services\Ai\VisualStyle;

use App\Models\AiImageProfile;
use App\Models\VisualStyleShot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: CRUD Shot Recipe của một Visual Style.
 * =====================================================================
 * Shot recipe chỉ override phần composition/camera cần đổi so với Style DNA;
 * service đảm bảo override chỉ chứa field hợp lệ theo enum của
 * VisualStyleDnaDefinition và shot luôn thuộc đúng style trên route
 * (nested resource {style}/shots/{shot}).
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận definition để validate override.
 * - list(): danh sách shot của style đã sắp sort_order.
 * - create(): tạo shot mới, code unique trong style.
 * - update(): sửa shot thuộc đúng style.
 * - delete(): xóa shot thuộc đúng style.
 * - reorder(): cập nhật sort_order theo danh sách id client gửi.
 * - normalize(): chuẩn hóa override qua Definition trước khi ghi.
 * - assertShotOf(): chặn thao tác shot thuộc style khác.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : style + values/shot đã qua FormRequest.
 * - OUTPUT: VisualStyleShot/Collection cho controller wrap Resource.
 * - SIDE EFFECT: INSERT/UPDATE/DELETE visual_style_shots; reorder là một
 *   transaction duy nhất.
 * =====================================================================
 */
final class VisualStyleShotService
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận definition DNA để validate override composition/camera.
     * =====================================================================
     * Input: VisualStyleDnaDefinition. Output: service có dependency.
     * Side effect: không ghi DB.
     * =====================================================================
     */
    public function __construct(private readonly VisualStyleDnaDefinition $definition) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Liệt kê shot của style theo thứ tự hiển thị.
     * =====================================================================
     * Input: style. Output: collection VisualStyleShot sắp sort_order, id.
     * Side effect: chỉ đọc DB.
     * =====================================================================
     */
    public function list(AiImageProfile $style): Collection
    {
        return $style->shots()->get();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo shot recipe mới cho style.
     * =====================================================================
     * Input: style và values (code/name/description/overrides/suffix/ratio/
     * is_active). Output: shot đã tạo; sort_order mặc định nối tiếp cuối.
     * Side effect: INSERT; ValidationException khi code trùng hoặc override sai.
     * =====================================================================
     */
    public function create(AiImageProfile $style, array $values): VisualStyleShot
    {
        $values = $this->normalize($values);
        if ($style->shots()->where('code', $values['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'Mã shot đã tồn tại trong style này.']);
        }
        if (! array_key_exists('sort_order', $values) || $values['sort_order'] === null) {
            $values['sort_order'] = ((int) $style->shots()->max('sort_order')) + 1;
        }

        return $style->shots()->create($values);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cập nhật shot recipe thuộc đúng style.
     * =====================================================================
     * Input: style, shot, values partial. Output: shot đã refresh.
     * Side effect: UPDATE; ValidationException khi shot khác style, code đổi
     * trùng shot khác hoặc override sai enum.
     * =====================================================================
     */
    public function update(AiImageProfile $style, VisualStyleShot $shot, array $values): VisualStyleShot
    {
        $this->assertShotOf($style, $shot);
        $values = $this->normalize($values, partial: true);
        if (isset($values['code'])
            && $style->shots()->where('code', $values['code'])->whereKeyNot($shot->id)->exists()) {
            throw ValidationException::withMessages(['code' => 'Mã shot đã tồn tại trong style này.']);
        }
        $shot->fill($values)->save();

        return $shot->refresh();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xóa shot recipe thuộc đúng style.
     * =====================================================================
     * Input: style, shot. Output: void.
     * Side effect: DELETE visual_style_shots.
     * =====================================================================
     */
    public function delete(AiImageProfile $style, VisualStyleShot $shot): void
    {
        $this->assertShotOf($style, $shot);
        $shot->delete();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cập nhật sort_order theo thứ tự danh sách id client gửi.
     * =====================================================================
     * Input: style và ordered_ids (mảng id theo thứ tự mong muốn).
     * Output: collection shot sau khi sắp lại; id không thuộc style hoặc thiếu
     * shot nào thì reject toàn bộ.
     * Side effect: một transaction UPDATE nhiều dòng sort_order.
     * =====================================================================
     */
    public function reorder(AiImageProfile $style, array $orderedIds): Collection
    {
        $existing = $style->shots()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $requested = array_map('intval', $orderedIds);
        sort($existing);
        $sortedRequested = $requested;
        sort($sortedRequested);
        if ($existing !== $sortedRequested) {
            throw ValidationException::withMessages(['ordered_ids' => 'Danh sách id phải chứa đúng tất cả shot của style.']);
        }

        return DB::transaction(function () use ($style, $requested): Collection {
            foreach ($requested as $position => $id) {
                VisualStyleShot::query()
                    ->where('visual_style_id', $style->id)
                    ->whereKey($id)
                    ->update(['sort_order' => $position + 1]);
            }

            return $style->shots()->get();
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn thao tác lên shot không thuộc style trên route.
     * =====================================================================
     * Input: style, shot. Output: void hoặc 404.
     * Side effect: không ghi DB; abort 404 để không lộ shot của style khác.
     * =====================================================================
     */
    private function assertShotOf(AiImageProfile $style, VisualStyleShot $shot): void
    {
        abort_unless((int) $shot->visual_style_id === (int) $style->id, 404);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa values shot, validate override theo enum Definition.
     * =====================================================================
     * Input: values thô; partial=true khi update (chỉ validate field có gửi).
     * Output: values an toàn để fill; key override map sang cột *_override_json.
     * Side effect: ValidationException khi override chứa field lạ/enum sai.
     * =====================================================================
     */
    private function normalize(array $values, bool $partial = false): array
    {
        $normalized = [];
        $map = [
            'code' => 'code', 'name' => 'name', 'description' => 'description',
            'prompt_suffix' => 'prompt_suffix', 'suggested_aspect_ratio' => 'suggested_aspect_ratio',
            'sort_order' => 'sort_order', 'is_active' => 'is_active',
        ];
        foreach ($map as $input => $column) {
            if (array_key_exists($input, $values)) {
                $normalized[$column] = $values[$input];
            }
        }
        if (array_key_exists('composition_override', $values)) {
            $normalized['composition_override_json'] = $this->definition->validateOverride('composition', $values['composition_override']);
        }
        if (array_key_exists('camera_override', $values)) {
            $normalized['camera_override_json'] = $this->definition->validateOverride('camera', $values['camera_override']);
        }
        if (! $partial && ! isset($normalized['is_active'])) {
            $normalized['is_active'] = true;
        }

        return $normalized;
    }
}
