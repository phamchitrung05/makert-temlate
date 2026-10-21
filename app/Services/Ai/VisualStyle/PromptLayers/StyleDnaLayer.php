<?php

namespace App\Services\Ai\VisualStyle\PromptLayers;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Layer render Style DNA của profile thành mô tả tự nhiên.
 * =====================================================================
 *
 * Layer cốt lõi — đọc `style->dna_json` (schema định nghĩa trong
 * `config/ai/visual_style_dna.php` / plan mục 3) và render 5 nhóm chính thành
 * ngôn ngữ tự nhiên ngắn gọn: composition, camera, lighting, environment,
 * color_mood. Không gửi JSON thô cho image model — phải render ra câu.
 *
 * Chỉ render các field có giá trị (string non-empty, array non-empty, số khác
 * 0, bool true) — field rỗng/0/false bị bỏ qua để prompt không bị "noise".
 * Trả `null` khi toàn bộ DNA render được rỗng (profile chưa analyze).
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - key(): 'style_dna'.
 * - render(VisualPromptContext): render DNA → chuỗi prompt hoặc null.
 * - renderComposition/Camera/Lighting/Environment/ColorMood(): sub-render từng
 *   nhóm DNA, trả ?string cho phần đó.
 * - str()/arr()/num(): helper lọc giá trị scalar/array/number có nghĩa.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : VisualPromptContext->style->dna_json (array).
 * - OUTPUT: chuỗi prompt tiếng Anh hoặc null; không gọi provider/DB.
 * =====================================================================
 */
final class StyleDnaLayer implements PromptLayerContract
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả key registry 'style_dna'.
     * =====================================================================
     * INPUT: không có. OUTPUT: string 'style_dna'.
     * SIDE EFFECT/EXCEPTION: không có.
     * =====================================================================
     */
    public function key(): string
    {
        return 'style_dna';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Render toàn bộ Style DNA thành prompt tự nhiên.
     * =====================================================================
     * Duyệt 5 nhóm composition/camera/lighting/environment/color_mood theo thứ
     * tự schema; mỗi nhóm render ra một mệnh đề, ghép bằng '. '. Bỏ qua nhóm
     * render rỗng.
     *
     * INPUT:
     * - $context: VisualPromptContext; đọc `$context->style->dna_json`.
     * OUTPUT:
     * - ?string: prompt tổng hợp các nhóm có giá trị; null khi dna_json rỗng
     *   hoặc tất cả nhóm đều trống.
     * SIDE EFFECT: không gọi provider, không ghi DB.
     * EXCEPTION/TRANSACTION: không có; an toàn với dna_json null/malformed.
     * =====================================================================
     */
    public function render(VisualPromptContext $context): ?string
    {
        $dna = $context->style->dna_json;
        if (! is_array($dna) || $dna === []) {
            return null;
        }

        $groups = [
            $this->renderComposition($dna['composition'] ?? null),
            $this->renderCamera($dna['camera'] ?? null),
            $this->renderLighting($dna['lighting'] ?? null),
            $this->renderEnvironment($dna['environment'] ?? null),
            $this->renderColorMood($dna['color_mood'] ?? null),
        ];

        $groups = array_values(array_filter(
            $groups,
            static fn (?string $part): bool => $part !== null && $part !== ''
        ));

        return $groups === [] ? null : implode(' ', $groups);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Render nhóm composition (framing/position/scale/orientation/
     * balance/negative_space/depth_layers).
     * =====================================================================
     * INPUT: $data — mảng composition từ dna_json.
     * OUTPUT: ?string mệnh đề composition; null khi không có field nào.
     * =====================================================================
     */
    private function renderComposition(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        $parts = [];
        if ($framing = $this->str($data['framing'] ?? null)) {
            $parts[] = "{$framing} framing";
        }
        if ($position = $this->str($data['subject_position'] ?? null)) {
            $parts[] = "subject positioned {$position}";
        }
        if ($scale = $this->num($data['subject_scale'] ?? null)) {
            // subject_scale 0.0-1.0 → độ lớn của subject trong khung.
            $parts[] = 'subject scale '.round((float) $scale, 2).' of frame';
        }
        if ($orientation = $this->str($data['orientation'] ?? null)) {
            $parts[] = "{$orientation} orientation";
        }
        if ($balance = $this->str($data['visual_balance'] ?? null)) {
            $parts[] = "{$balance} visual balance";
        }
        if ($negative = $this->str($data['negative_space'] ?? null)) {
            $parts[] = "{$negative} negative space";
        }
        if ($depth = $this->arr($data['depth_layers'] ?? null)) {
            $parts[] = 'depth layers: '.implode(', ', $depth);
        }

        return $parts === [] ? null : 'Composition: '.implode(', ', $parts).'.';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Render nhóm camera (shot_type/angle/lens/dof/focus/perspective).
     * =====================================================================
     * INPUT: $data — mảng camera từ dna_json.
     * OUTPUT: ?string mệnh đề camera; null khi trống.
     * =====================================================================
     */
    private function renderCamera(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        $parts = [];
        if ($shot = $this->str($data['shot_type'] ?? null)) {
            $parts[] = "{$shot} shot";
        }
        if ($angle = $this->str($data['angle'] ?? null)) {
            $parts[] = "{$angle} angle";
        }
        if ($lens = $this->str($data['lens_look'] ?? null)) {
            $parts[] = "{$lens} lens look";
        }
        if ($dof = $this->str($data['depth_of_field'] ?? null)) {
            $parts[] = "{$dof} depth of field";
        }
        if ($focus = $this->str($data['focus'] ?? null)) {
            $parts[] = "focus on {$focus}";
        }
        if ($perspective = $this->str($data['perspective'] ?? null)) {
            $parts[] = "{$perspective} perspective";
        }

        return $parts === [] ? null : 'Camera: '.implode(', ', $parts).'.';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Render nhóm lighting (type/direction/quality/intensity/
     * temperature/shadows/highlights).
     * =====================================================================
     * INPUT: $data — mảng lighting từ dna_json.
     * OUTPUT: ?string mệnh đề lighting; null khi trống.
     * =====================================================================
     */
    private function renderLighting(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        $parts = [];
        if ($type = $this->str($data['type'] ?? null)) {
            $parts[] = "{$type} lighting";
        }
        if ($direction = $this->str($data['direction'] ?? null)) {
            $parts[] = "light from {$direction}";
        }
        if ($quality = $this->str($data['quality'] ?? null)) {
            $parts[] = "{$quality} light quality";
        }
        if ($intensity = $this->str($data['intensity'] ?? null)) {
            $parts[] = "{$intensity} intensity";
        }
        if ($temperature = $this->str($data['temperature'] ?? null)) {
            $parts[] = "{$temperature} color temperature";
        }
        if ($shadows = $this->str($data['shadows'] ?? null)) {
            $parts[] = "{$shadows} shadows";
        }
        if ($highlights = $this->str($data['highlights'] ?? null)) {
            $parts[] = "{$highlights} highlights";
        }

        return $parts === [] ? null : 'Lighting: '.implode(', ', $parts).'.';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Render nhóm environment (type/background/props).
     * =====================================================================
     * INPUT: $data — mảng environment từ dna_json.
     * OUTPUT: ?string mệnh đề environment; null khi trống.
     * =====================================================================
     */
    private function renderEnvironment(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        $parts = [];
        if ($type = $this->str($data['type'] ?? null)) {
            $parts[] = "{$type} environment";
        }
        if ($bg = $this->str($data['background_style'] ?? null)) {
            $parts[] = "{$bg} background";
        }
        if ($bgDetail = $this->str($data['background_detail'] ?? null)) {
            $parts[] = "background detail: {$bgDetail}";
        }
        if ($blur = $this->str($data['background_blur'] ?? null)) {
            $parts[] = "{$blur} background blur";
        }
        if ($props = $this->str($data['props'] ?? null)) {
            $parts[] = "props: {$props}";
        }

        return $parts === [] ? null : 'Environment: '.implode(', ', $parts).'.';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Render nhóm color_mood (palette/saturation/contrast/mood).
     * =====================================================================
     * INPUT: $data — mảng color_mood từ dna_json.
     * OUTPUT: ?string mệnh đề color/mood; null khi trống.
     * =====================================================================
     */
    private function renderColorMood(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        $parts = [];
        if ($palette = $this->arr($data['palette'] ?? null)) {
            $parts[] = 'color palette: '.implode(', ', $palette);
        }
        if ($sat = $this->str($data['saturation'] ?? null)) {
            $parts[] = "{$sat} saturation";
        }
        if ($contrast = $this->str($data['contrast'] ?? null)) {
            $parts[] = "{$contrast} contrast";
        }
        if ($mood = $this->arr($data['mood'] ?? null)) {
            $parts[] = 'mood: '.implode(', ', $mood);
        }

        return $parts === [] ? null : 'Color and mood: '.implode(', ', $parts).'.';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc giá trị string có nghĩa (non-empty sau trim).
     * =====================================================================
     * INPUT: mixed value. OUTPUT: ?string đã trim; null khi rỗng/không phải string.
     * =====================================================================
     */
    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc giá trị số khác 0 (0 coi như không có giá trị).
     * =====================================================================
     * INPUT: mixed value. OUTPUT: float|int|null; null khi không phải số hoặc = 0.
     * =====================================================================
     */
    private function num(mixed $value): float|int|null
    {
        if (! is_numeric($value)) {
            return null;
        }
        $value = $value + 0;

        return $value == 0 ? null : $value;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc mảng string non-empty sau trim, loại phần tử rỗng.
     * =====================================================================
     * INPUT: mixed value. OUTPUT: ?array<string>; null khi không phải mảng hoặc
     * sau lọc rỗng.
     * =====================================================================
     */
    private function arr(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $items = array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_string($item) && trim($item) !== '' ? trim($item) : null,
            $value
        )));

        return $items === [] ? null : $items;
    }
}
