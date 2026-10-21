<?php

namespace App\Services\Ai\VisualStyle\PromptLayers;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Layer render override composition/camera của Shot Recipe
 * cùng `prompt_suffix`, theo quy tắc "recipe field có giá trị → thắng DNA".
 * =====================================================================
 *
 * Shot Recipe không sao chép nguyên DNA — chỉ chứa các field cần đổi cho góc
 * chụp cụ thể. `composition_override_json`/`camera_override_json` là map
 * field-override; layer này merge với DNA ở cùng nhóm theo rule: **nếu recipe
 * có giá trị cho field thì dùng giá trị recipe, ngược lại giữ DNA** (đã render
 * bởi `StyleDnaLayer` trước đó). Layer này chỉ render PHẦN OVERRIDE — DNA đã
 * được StyleDnaLayer lo; không lặp lại toàn bộ DNA.
 *
 * Ngoài override, layer còn render `prompt_suffix` (chuỗi tự do admin gắn vào
 * cuối recipe, ví dụ "close-up on the label") và gợi ý `suggested_aspect_ratio`
 * nếu `context->aspectRatio` chưa được set (recipe là nguồn gợi ý mặc định).
 *
 * Trả `null` khi recipe === null hoặc recipe không có override/suffix nào.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - key(): 'shot_recipe'.
 * - render(VisualPromptContext): render override + suffix hoặc null.
 * - renderOverrideGroup(): render map field → câu "field: value".
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : VisualPromptContext->recipe (composition_override_json,
 *   camera_override_json, prompt_suffix, suggested_aspect_ratio) và
 *   ->aspectRatio.
 * - OUTPUT: chuỗi prompt tiếng Anh hoặc null; không gọi provider/DB.
 * =====================================================================
 */
final class ShotRecipeLayer implements PromptLayerContract
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả key registry 'shot_recipe'.
     * =====================================================================
     * INPUT: không có. OUTPUT: string 'shot_recipe'.
     * SIDE EFFECT/EXCEPTION: không có.
     * =====================================================================
     */
    public function key(): string
    {
        return 'shot_recipe';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Render override của recipe và prompt_suffix.
     * =====================================================================
     * Merge rule (ghi trong docblock class): recipe field có giá trị → thắng
     * DNA. Layer KHÔNG render lại field DNA không bị override (đó là việc của
     * StyleDnaLayer); chỉ liệt kê các field recipe có giá trị để image model
     * thấy "cho góc này, ưu tiên giá trị sau". `suggested_aspect_ratio` chỉ
     * được render khi `context->aspectRatio` rỗng — caller truyền aspectRatio
     * tường minh sẽ override recipe.
     *
     * INPUT:
     * - $context: VisualPromptContext; đọc `$context->recipe` và
     *   `$context->aspectRatio`.
     * OUTPUT:
     * - ?string: "Shot recipe: <overrides>. <suffix>." hoặc null khi recipe
     *   null/trống.
     * SIDE EFFECT: không gọi provider, không ghi DB.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function render(VisualPromptContext $context): ?string
    {
        $recipe = $context->recipe;
        if ($recipe === null) {
            return null;
        }

        $parts = [];

        $composition = $this->renderOverrideGroup($recipe->composition_override_json ?? null);
        if ($composition !== null) {
            $parts[] = "Composition override: {$composition}";
        }

        $camera = $this->renderOverrideGroup($recipe->camera_override_json ?? null);
        if ($camera !== null) {
            $parts[] = "Camera override: {$camera}";
        }

        // suggested_aspect_ratio chỉ là gợi ý; aspectRatio của caller thắng.
        $aspectRatio = $context->aspectRatio;
        if ((! is_string($aspectRatio) || trim($aspectRatio) === '')
            && is_string($recipe->suggested_aspect_ratio)
            && trim((string) $recipe->suggested_aspect_ratio) !== '') {
            $parts[] = 'Suggested aspect ratio: '.trim((string) $recipe->suggested_aspect_ratio);
        }

        $suffix = $recipe->prompt_suffix;
        if (is_string($suffix) && trim($suffix) !== '') {
            $parts[] = trim($suffix);
        }

        if ($parts === []) {
            return null;
        }

        // Ghép override group thành một mệnh đề; suffix nối sau cùng.
        $body = implode('. ', array_map(
            static fn (string $part): string => rtrim($part, '.'),
            $parts
        ));

        return "Shot recipe: {$body}.";
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Render một map override thành chuỗi "field value, field value".
     * =====================================================================
     * Chỉ lấy field có giá trị scalar/array non-empty — đúng rule "recipe có
     * giá trị thì mới override". Field null/rỗng bị bỏ để DNA giữ nguyên.
     *
     * INPUT: $data — mảng override từ recipe JSON column.
     * OUTPUT: ?string danh sách override ngăn cách ', '; null khi trống.
     * =====================================================================
     */
    private function renderOverrideGroup(mixed $data): ?string
    {
        if (! is_array($data) || $data === []) {
            return null;
        }

        $parts = [];
        foreach ($data as $field => $value) {
            if (! is_string($field)) {
                continue;
            }
            $label = str_replace('_', ' ', trim($field));
            if ($label === '') {
                continue;
            }

            if (is_string($value) && trim($value) !== '') {
                $parts[] = "{$label}: ".trim($value);
            } elseif (is_numeric($value) && $value != 0) {
                $parts[] = "{$label}: ".($value + 0);
            } elseif (is_bool($value)) {
                $parts[] = "{$label}: ".($value ? 'yes' : 'no');
            } elseif (is_array($value)) {
                $items = array_values(array_filter(array_map(
                    static fn (mixed $item): ?string => is_string($item) && trim($item) !== '' ? trim($item) : null,
                    $value
                )));
                if ($items !== []) {
                    $parts[] = "{$label}: ".implode(', ', $items);
                }
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }
}
