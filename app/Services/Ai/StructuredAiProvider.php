<?php

namespace App\Services\Ai;

use App\Exceptions\AiImportException;
use Illuminate\Support\Facades\Http;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Adapter HTTP JSON tương thích cho provider nội bộ.
 * =====================================================================
 *
 * Adapter giữ endpoint tùy biến đang được project hỗ trợ và dùng chung lớp
 * structured output. Khi endpoint/key rỗng, hệ thống chạy deterministic fallback.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - configured(): kiểm tra endpoint/key.
 * - providerName(): trả identity provenance.
 * - modelName(): trả model cấu hình.
 * - requestPayload(): gửi HTTP JSON tới endpoint nội bộ.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : config AI_IMPORT và context canonical từ lớp cha.
 * - OUTPUT: raw JSON provider hoặc AiImportException retryable.
 * =====================================================================
 */
class StructuredAiProvider extends AbstractStructuredAiProvider
{
    /**
     * Kiểm tra endpoint và key đã cấu hình.
     *
     * Input: Không có.
     * Output: bool; chỉ đọc config.
     */
    public function configured(): bool
    {
        return (string) config('ai-import.endpoint') !== '' && (string) config('ai-import.key') !== '';
    }

    /**
     * Trả provider key dùng cho provenance.
     *
     * Input: Không có.
     * Output: http-json hoặc deterministic.
     */
    public function providerName(): string
    {
        return $this->configured() ? 'http-json' : 'deterministic';
    }

    /**
     * Trả model config dùng cho audit.
     *
     * Input: Không có.
     * Output: model string.
     */
    public function modelName(): string
    {
        return $this->configured() ? ($this->requestedModel() ?: (string) config('ai-import.model', 'default')) : 'deterministic';
    }

    /**
     * Gửi context structured tới endpoint JSON tương thích.
     *
     * Input: context prompt/schema/model từ lớp cha.
     * Output: JSON decoded; lỗi HTTP 429/5xx được đánh dấu retryable.
     *
     * @param  array<string, mixed>  $input
     */
    protected function requestPayload(array $input): mixed
    {
        $response = Http::connectTimeout((int) config('ai-import.connect_timeout', 5))
            ->timeout((int) config('ai-import.timeout', 12))
            ->withToken((string) config('ai-import.key'))
            ->acceptJson()
            ->post((string) config('ai-import.endpoint'), [
                'model' => $input['model'],
                'language' => $input['language'],
                'response_format' => ['type' => 'json_object'],
                'input' => $input,
            ]);

        if (! $response->successful()) {
            throw new AiImportException(
                'AI provider trả HTTP '.$response->status().'.',
                'AI_PROVIDER_HTTP_'.$response->status(),
                $response->status() === 429 || $response->serverError(),
            );
        }

        return $response->json();
    }
}
