<?php

namespace App\Services\Ai;

use App\Enums\AiCapability;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Adapter Google Gemini trả structured JSON.
 * =====================================================================
 *
 * Adapter chuyển context dùng chung sang generateContent API, giữ key ở
 * backend và để AbstractStructuredAiProvider kiểm tra schema/allowlist.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - configured(): kiểm tra Gemini key.
 * - providerName(): trả gemini cho provenance.
 * - modelName(): trả model request/config.
 * - requestPayload(): gọi generateContent với MIME JSON.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : GEMINI key/model và context prompt/schema.
 * - OUTPUT: raw Gemini JSON hoặc AiImportException.
 * =====================================================================
 */
final class GeminiProvider extends AbstractStructuredAiProvider
{
    protected function responseFormat(): string
    {
        return 'gemini';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: true khi có API key.
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: true khi có API key.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function configured(): bool
    {
        return $this->connection() !== null || (string) config('ai-providers.connections.gemini.key') !== '';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: provider key gemini.
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: provider key gemini.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function providerName(): string
    {
        return $this->connection()?->snapshot['provider'] ?? 'gemini';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: model request hoặc model mặc định.
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: model request hoặc model mặc định.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function modelName(): string
    {
        return $this->requestedModel() ?: (string) ($this->connection()?->snapshot['model'] ?? config('ai-providers.connections.gemini.model', 'gemini-3.6-flash'));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gửi context JSON, bật JSON mode khi model hỗ trợ structured_output.
     * =====================================================================
     * INPUT: context canonical từ AbstractStructuredAiProvider.
     * OUTPUT: response JSON; retryability tuân theo boundary POST của AiProviderClient.
     * SIDE EFFECT: gọi Gemini qua AiProviderClient; không ghi domain database.
     * EXCEPTION/TRANSACTION: AiImportException cho HTTP lỗi; không mở transaction.
     * =====================================================================
     *
     * @param  array<string, mixed>  $input
     */
    protected function requestPayload(array $input): mixed
    {
        $payload = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $input['instructions']."\n".$this->canonicalInput($input)]]]],
            'generationConfig' => [
                'temperature' => (float) ($this->runSettings()['temperature'] ?? config('ai-providers.connections.gemini.temperature', 0.2)),
            ],
        ];
        if ($this->connection() === null || in_array(AiCapability::Structured->value, $this->connection()->snapshot['capabilities'] ?? [], true)) {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
        }
        $connection = $this->connection() ?? new AiConnection([
            'driver' => 'gemini', 'provider' => 'gemini',
            'base_url' => (string) config('ai-providers.connections.gemini.endpoint'),
            'model' => $input['model'], 'timeout' => (int) config('ai-providers.connections.gemini.timeout', 12),
        ], (string) config('ai-providers.connections.gemini.key'));

        return app(AiProviderClient::class)->send(
            $connection, 'POST', 'models/'.rawurlencode((string) $input['model']).':generateContent', $payload,
        );
    }
}
