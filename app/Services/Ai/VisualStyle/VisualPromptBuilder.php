<?php

namespace App\Services\Ai\VisualStyle;

use App\Services\Ai\VisualStyle\PromptLayers\NegativeLayer;
use App\Services\Ai\VisualStyle\PromptLayers\PromptLayerContract;
use App\Services\Ai\VisualStyle\PromptLayers\VisualPromptContext;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Build prompt ảnh cuối cùng bằng cách chạy các layer đã
 * đăng ký trong `config/ai/visual_style_prompt_layers.php` theo đúng thứ tự.
 * =====================================================================
 *
 * Builder resolve từng layer class qua container, gọi `render()` với cùng
 * `VisualPromptContext`, bỏ qua layer trả `null`, rồi ghép các đoạn lại thành
 * prompt tiếng Anh duy nhất — phân tách bằng khoảng trắng, chuẩn hoá chấm câu
 * để không lặp cụm. Negative prompt được lấy riêng qua `negative()` gọi
 * `NegativeLayer::negativePrompt()` — vì negative không nằm trong prompt chính
 * mà gửi ở field riêng của image provider.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(Container): nhận container để resolve layer theo config.
 * - build(VisualPromptContext): render prompt chính từ toàn bộ layer.
 * - negative(VisualPromptContext): render negative prompt (có thể '').
 * - layers(): internal — đọc + validate config layers, trả instances.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : VisualPromptContext + config `ai.visual_style_prompt_layers.layers`.
 * - OUTPUT: string prompt tiếng Anh cho build(), string cho negative().
 * - SIDE EFFECT: resolve layer qua container; không gọi provider, không ghi DB.
 * =====================================================================
 */
final class VisualPromptBuilder
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo builder với container để resolve layer.
     * =====================================================================
     * INPUT:
     * - $container: Laravel container dùng make() layer class.
     * OUTPUT: builder instance.
     * SIDE EFFECT: giữ reference container; không resolve gì ngay.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function __construct(private readonly Container $container) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Build prompt chính từ toàn bộ layer theo thứ tự config.
     * =====================================================================
     * Duyệt `layers()`, gọi `render($context)`, bỏ null/rỗng, chuẩn hoá dấu
     * câu cuối (mỗi đoạn kết thúc đúng một '.') rồi nối bằng ' '. Trả '' khi
     * tất cả layer đều trả null (style chưa có DNA, không product/recipe).
     *
     * INPUT:
     * - $context: VisualPromptContext đầu vào cho mọi layer.
     * OUTPUT:
     * - string: prompt tiếng Anh; có thể '' khi không có dữ liệu nào render.
     * SIDE EFFECT: resolve layer instances qua container; không gọi provider,
     * không ghi DB.
     * EXCEPTION/TRANSACTION: InvalidArgumentException khi config layer không
     * hợp lệ; không mở transaction.
     * =====================================================================
     */
    public function build(VisualPromptContext $context): string
    {
        $parts = [];
        foreach ($this->layers() as $layer) {
            $rendered = $layer->render($context);
            if (! is_string($rendered) || trim($rendered) === '') {
                continue;
            }
            $parts[] = rtrim(trim($rendered), '.').'.';
        }

        return implode(' ', $parts);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Render negative prompt (avoid_* + negative_prompt của style).
     * =====================================================================
     * Tìm `NegativeLayer` trong config bằng `key() === 'negative'`; nếu layer
     * đó tồn tại thì gọi `NegativeLayer::negativePrompt($context)`. Trả ''
     * khi không khai layer negative hoặc kết quả rỗng — provider sẽ bỏ qua
     * field này khi chuỗi rỗng.
     *
     * INPUT:
     * - $context: VisualPromptContext đầu vào.
     * OUTPUT:
     * - string: negative prompt tiếng Anh; có thể '' khi không có hạn chế.
     * SIDE EFFECT: resolve layer qua container; không gọi provider/DB.
     * EXCEPTION/TRANSACTION: InvalidArgumentException khi config layer không
     *   hợp lệ; không mở transaction.
     * =====================================================================
     */
    public function negative(VisualPromptContext $context): string
    {
        foreach ($this->layers() as $layer) {
            if ($layer->key() !== 'negative') {
                continue;
            }
            if ($layer instanceof NegativeLayer) {
                return NegativeLayer::negativePrompt($context);
            }
            // Layer tự khai key 'negative' nhưng không phải NegativeLayer —
            // vẫn tôn trọng contract chung và gọi render() bình thường.
            $rendered = $layer->render($context);

            return is_string($rendered) ? trim($rendered) : '';
        }

        return '';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc config `ai.visual_style_prompt_layers.layers` và resolve
     * từng entry thành instance PromptLayerContract qua container.
     * =====================================================================
     * Mỗi entry phải có `key` (string) và `class` (string implement
     * `PromptLayerContract`). Entry sai cấu hình → InvalidArgumentException
     * để lỗi lộ rõ khi dev thêm layer mới, không nuốt lỗi.
     *
     * INPUT: không có (đọc config).
     * OUTPUT:
     * - array<PromptLayerContract>: instances theo đúng thứ tự config; mảng
     *   rỗng nếu config trống.
     * SIDE EFFECT: resolve layer qua container (mỗi lần gọi tạo instance mới).
     * EXCEPTION/TRANSACTION: InvalidArgumentException khi entry thiếu key/class
     *   hoặc class không implement contract; không transaction.
     * =====================================================================
     *
     * @return array<int, PromptLayerContract>
     */
    private function layers(): array
    {
        $configured = config('ai.visual_style_prompt_layers.layers', []);
        if (! is_array($configured)) {
            return [];
        }

        $instances = [];
        foreach ($configured as $index => $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException(
                    "Layer entry #{$index} trong ai.visual_style_prompt_layers.layers phải là mảng."
                );
            }
            $key = $entry['key'] ?? null;
            $class = $entry['class'] ?? null;
            if (! is_string($key) || trim($key) === '' || ! is_string($class) || $class === '') {
                throw new InvalidArgumentException(
                    "Layer entry #{$index} thiếu 'key' hoặc 'class' hợp lệ."
                );
            }
            if (! is_a($class, PromptLayerContract::class, true)) {
                throw new InvalidArgumentException(
                    "Layer [{$key}] class {$class} chưa implement PromptLayerContract."
                );
            }
            $instance = $this->container->make($class);
            if (! $instance instanceof PromptLayerContract) {
                throw new InvalidArgumentException(
                    "Layer [{$key}] {$class} resolve ra không phải PromptLayerContract."
                );
            }
            $instances[] = $instance;
        }

        return $instances;
    }
}
