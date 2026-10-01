<?php

namespace App\Services\Ai;

use App\Exceptions\AiImportException;
use App\Services\Ai\Contracts\AiImageProviderContract;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Image adapter cho Gemini native và OpenAI-compatible.
 * =====================================================================
 * CÁC HÀM/METHOD: __construct(), generate(), decodeImage().
 * INPUT: validated connection, remote model ID và prompt.
 * OUTPUT: bytes từ inline image/base64/public URL qua boundary HTTP chung.
 * SIDE EFFECT: gọi provider; không ghi database hoặc tạo MediaAsset.
 * EXCEPTION/TRANSACTION: AiImportException nếu response thiếu/sai ảnh; không transaction.
 * =====================================================================
 */
final class HttpAiImageProvider implements AiImageProviderContract
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận HTTP client chung và downloader ảnh an toàn
     * =====================================================================
     * INPUT: AiProviderClient và ArticleSourceFetcher.
     * OUTPUT: Image adapter đã được inject dependencies.
     * SIDE EFFECT: Chỉ giữ dependencies; không gọi API hoặc ghi DB.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function __construct(
        private readonly AiProviderClient $client,
        private readonly ArticleSourceFetcher $fetcher,
    ) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Gọi giao thức tạo ảnh phù hợp với driver
     * =====================================================================
     * INPUT: Connection snapshot và prompt.
     * OUTPUT: Bytes ảnh từ Gemini inline hoặc OpenAI-compatible base64/URL.
     * SIDE EFFECT: Gọi HTTPS; URL ảnh đi qua source fetcher chống SSRF.
     * EXCEPTION/TRANSACTION: AiImportException khi response thiếu ảnh; không retry POST timeout mơ hồ, không mở transaction.
     * =====================================================================
     */
    public function generate(AiConnection $connection, string $prompt): string
    {
        $model = (string) ($connection->snapshot['model'] ?? '');
        if ($model === '') {
            throw new AiImportException('Image model chưa được cấu hình.', 'AI_IMAGE_MODEL_MISSING');
        }
        if ($connection->snapshot['driver'] === 'gemini') {
            $response = $this->client->send($connection, 'POST', 'models/'.rawurlencode($model).':generateContent', [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']],
            ]);
            foreach ((array) data_get($response, 'candidates.0.content.parts', []) as $part) {
                $data = is_array($part) ? data_get($part, 'inlineData.data') : null;
                if (is_string($data) && $data !== '') {
                    return $this->decodeImage($data);
                }
            }
            throw new AiImportException('Gemini không trả inline image data.', 'AI_IMAGE_EMPTY');
        }
        $response = $this->client->send($connection, 'POST', 'images/generations', [
            'model' => $model, 'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024',
        ]);
        $item = data_get($response, 'data.0');
        if (is_array($item) && is_string($item['b64_json'] ?? null) && $item['b64_json'] !== '') {
            return $this->decodeImage($item['b64_json']);
        }
        if (is_array($item) && is_string($item['url'] ?? null) && $item['url'] !== '') {
            return $this->fetcher->downloadImage($item['url']);
        }

        throw new AiImportException('Provider không trả URL hoặc base64 ảnh.', 'AI_IMAGE_EMPTY');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giải mã base64 ảnh với giới hạn kích thước
     * =====================================================================
     * INPUT: Base64/data URL từ provider chưa được tin cậy.
     * OUTPUT: Binary bytes nằm trong giới hạn cấu hình.
     * SIDE EFFECT: Chỉ xử lý bộ nhớ; không gọi mạng hoặc ghi file.
     * EXCEPTION/TRANSACTION: AiImportException khi encoding sai hoặc quá kích thước; không mở transaction.
     * =====================================================================
     */
    private function decodeImage(string $encoded): string
    {
        $maxBytes = (int) config('ai-import.max_image_bytes', 10 * 1024 * 1024);
        if (strlen($encoded) > (int) ceil($maxBytes * 1.5)) {
            throw new AiImportException('Dữ liệu ảnh provider vượt giới hạn.', 'AI_IMAGE_TOO_LARGE');
        }
        if (str_starts_with($encoded, 'data:')) {
            $encoded = substr($encoded, (int) strpos($encoded, ',') + 1);
        }
        $binary = base64_decode($encoded, true);
        if ($binary === false || strlen($binary) > $maxBytes) {
            throw new AiImportException('Dữ liệu ảnh provider không hợp lệ hoặc vượt giới hạn.', 'AI_IMAGE_INVALID');
        }

        return $binary;
    }
}
