<?php

namespace App\Services\Ai;

use App\Enums\AiCapability;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Adapter OpenAI Chat Completions trả structured JSON.
 * =====================================================================
 *
 * Provider chỉ nhận nội dung đã sanitize và trả raw response cho lớp chuẩn
 * hóa chung; API key và endpoint luôn nằm server-side trong config.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - configured(): kiểm tra OpenAI key.
 * - providerName(): trả openai cho provenance.
 * - modelName(): trả model allowlist/config.
 * - requestPayload(): gửi request JSON tới OpenAI.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : OPENAI key/model và context prompt/schema.
 * - OUTPUT: raw Chat Completions JSON hoặc AiImportException.
 * =====================================================================
 */
final class OpenAiProvider extends AbstractStructuredAiProvider
{
    /**
     * =====================================================================
     * CHỨC NĂNG: true khi có API key và provider bật.
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: true khi có API key và provider bật.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function configured(): bool
    {
        return $this->connection() !== null || (string) config('ai-import.openai.key') !== '';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: provider key openai.
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: provider key openai.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function providerName(): string
    {
        return $this->connection()?->snapshot['provider'] ?? 'openai';
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
        return $this->requestedModel() ?: (string) ($this->connection()?->snapshot['model'] ?? config('ai-import.openai.model', 'gpt-4o-mini'));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gửi prompt JSON; dùng JSON mode khi model khai báo structured_output.
     * =====================================================================
     * INPUT: context canonical từ AbstractStructuredAiProvider.
     * OUTPUT: response JSON; retryability tuân theo boundary POST của AiProviderClient.
     * SIDE EFFECT: gọi OpenAI qua AiProviderClient; không ghi domain database.
     * EXCEPTION/TRANSACTION: AiImportException cho HTTP lỗi; không mở transaction.
     * =====================================================================
     * @param  array<string, mixed>  $input
     */
    protected function requestPayload(array $input): mixed
    {
        $payload = [
            'model' => $input['model'],
            'temperature' => (float) ($this->connection()?->snapshot['temperature'] ?? config('ai-import.openai.temperature', 0.2)),
            'messages' => [
                ['role' => 'system', 'content' => $input['instructions']],
                ['role' => 'user', 'content' => $this->canonicalInput($input)],
            ],
        ];
        if ($this->connection() === null || in_array(AiCapability::Structured->value, $this->connection()->snapshot['capabilities'] ?? [], true)) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        $connection = $this->connection() ?? new AiConnection([
            'driver' => 'openai', 'provider' => 'openai',
            'base_url' => preg_replace('#/chat/completions/?$#', '', (string) config('ai-import.openai.endpoint')),
            'model' => $input['model'], 'timeout' => (int) config('ai-import.timeout', 12),
        ], (string) config('ai-import.openai.key'));

        return app(AiProviderClient::class)->send($connection, 'POST', 'chat/completions', $payload);
    }
}
