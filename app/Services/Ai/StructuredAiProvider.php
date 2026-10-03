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
 * - INPUT : connection HTTP JSON từ ai-providers và context canonical từ lớp cha.
 * - OUTPUT: raw JSON provider hoặc AiImportException retryable.
 * =====================================================================
 */
class StructuredAiProvider extends AbstractStructuredAiProvider
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra endpoint và key đã cấu hình.
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: bool; chỉ đọc config.
     * SIDE EFFECT: không gọi network hoặc ghi database.
     * EXCEPTION/TRANSACTION: không có; không mở transaction.
     * =====================================================================
     */
    public function configured(): bool
    {
        return (string) config('ai-providers.connections.http-json.endpoint') !== ''
            && (string) config('ai-providers.connections.http-json.key') !== '';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả provider key dùng cho provenance.
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: http-json hoặc deterministic.
     * SIDE EFFECT: chỉ đọc config.
     * EXCEPTION/TRANSACTION: không có; không mở transaction.
     * =====================================================================
     */
    public function providerName(): string
    {
        return $this->configured() ? 'http-json' : 'deterministic';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả model config dùng cho audit.
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: model string.
     * SIDE EFFECT: chỉ đọc config.
     * EXCEPTION/TRANSACTION: không có; không mở transaction.
     * =====================================================================
     */
    public function modelName(): string
    {
        return $this->configured()
            ? ($this->requestedModel() ?: (string) config('ai-providers.connections.http-json.model', 'default'))
            : 'deterministic';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gửi context structured tới endpoint JSON tương thích.
     * =====================================================================
     * INPUT: context prompt/schema/model từ lớp cha.
     * OUTPUT: JSON decoded; lỗi HTTP 429/5xx được đánh dấu retryable.
     * SIDE EFFECT: gọi endpoint AI server-side; không ghi domain database.
     * EXCEPTION/TRANSACTION: AiImportException cho HTTP lỗi; không mở transaction.
     * =====================================================================
     *
     * @param  array<string, mixed>  $input
     */
    protected function requestPayload(array $input): mixed
    {
        $response = Http::connectTimeout((int) config('ai-import.connect_timeout', 5))
            ->timeout((int) config('ai-providers.connections.http-json.timeout', 12))
            ->withToken((string) config('ai-providers.connections.http-json.key'))
            ->acceptJson()
            ->post((string) config('ai-providers.connections.http-json.endpoint'), [
                'model' => $input['model'],
                'temperature' => (float) ($this->runSettings()['temperature'] ?? config('ai-providers.connections.http-json.temperature', 0.2)),
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

        // Keep the JSON object/list distinction for the shared response parser.
        return $response->body();
    }
}
