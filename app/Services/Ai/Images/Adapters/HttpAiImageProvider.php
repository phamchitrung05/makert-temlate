<?php

namespace App\Services\Ai\Images\Adapters;

use App\Exceptions\AiImportException;
use App\Services\Ai\Content\ArticleSourceFetcher;
use App\Services\Ai\Contracts\AiImageProviderContract;
use App\Services\Ai\Providers\Transport\AiConnection;
use App\Services\Ai\Providers\Transport\AiProviderClient;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Image adapter cho Gemini native và OpenAI-compatible.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: __construct(), generate(), decodeImage().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
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
    public function generate(AiConnection $connection, string $prompt, array $images = []): string
    {
        $model = (string) ($connection->snapshot['model'] ?? '');
        if ($model === '') {
            throw new AiImportException('Image model chưa được cấu hình.', 'AI_IMAGE_MODEL_MISSING');
        }
        if ($connection->snapshot['driver'] === 'gemini') {
            $parts = $this->buildGeminiParts($prompt, $images);
            $response = $this->client->send($connection, 'POST', 'models/'.rawurlencode($model).':generateContent', [
                'contents' => [['parts' => $parts]],
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
        $payload = [
            'model' => $model, 'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024',
        ];
        if ($images !== []) {
            // OpenAI-compatible gateway (apikey.fan) yêu cầu multipart `image` file
            // ở `images/edits` khi có reference image; `images/generations` chỉ
            // nhận URL public nên private asset phải upload multipart.
            // multipart upload
            return $this->generateWithImages($connection, $model, $prompt, $images);
        }
        // no images
        $response = $this->client->send($connection, 'POST', 'images/generations', $payload);
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
     * CHỨC NĂNG: POST multipart `images/edits` cho ảnh reference.
     * =====================================================================
     * INPUT: connection, model, prompt và mảng `{url|base64, media_type}`.
     * OUTPUT: bytes ảnh từ response `b64_json` hoặc URL.
     * SIDE EFFECT: upload bytes file trực tiếp (không cần URL public).
     * =====================================================================
     */
    private function generateWithImages(AiConnection $connection, string $model, string $prompt, array $images): string
    {
        $base = rtrim((string) ($connection->snapshot['base_url'] ?? ''), '/');
        if ($base === '') {
            throw new AiImportException('OpenAI-compatible base_url chưa được cấu hình.', 'AI_IMAGE_ENDPOINT_MISSING');
        }

        $multipart = [
            ['name' => 'model', 'contents' => $model],
            ['name' => 'prompt', 'contents' => $prompt],
            ['name' => 'n', 'contents' => '1'],
            ['name' => 'size', 'contents' => '1024x1024'],
        ];
        foreach ($images as $image) {
            $multipart[] = $this->toMultipartImage($image);
        }

        $response = \Illuminate\Support\Facades\Http::asMultipart()
            ->timeout((int) ($connection->snapshot['timeout'] ?? 120))
            ->withToken($connection->apiKey)
            ->post("{$base}/images/edits", $multipart);

        if (! $response->successful()) {
            throw new AiImportException("Provider trả HTTP {$response->status()}.", 'AI_IMAGE_PROVIDER_'. $response->status());
        }
        $body = $response->json();
        $item = $body['data'][0] ?? null;
        if (is_array($item) && is_string($item['b64_json'] ?? null) && $item['b64_json'] !== '') {
            return $this->decodeImage($item['b64_json']);
        }
        if (is_array($item) && is_string($item['url'] ?? null) && $item['url'] !== '') {
            // Provider trả URL ảnh public — tải trực tiếp; fetcher chặn SSRF/private
            // nên không dùng fetcher cho URL do provider vừa trả về.
            $imageResponse = \Illuminate\Support\Facades\Http::timeout((int) ($connection->snapshot['timeout'] ?? 120))->get($item['url']);
            if (! $imageResponse->successful()) {
                throw new AiImportException("Không tải được ảnh kết quả từ provider (HTTP {$imageResponse->status()}).", 'AI_IMAGE_DOWNLOAD_FAILED');
            }

            return $imageResponse->body();
        }

        throw new AiImportException('Provider không trả URL hoặc base64 ảnh.', 'AI_IMAGE_EMPTY');
    }

    /** Input: `{url|base64, media_type}`. Output: multipart part cho image[] field. */
    private function toMultipartImage(array $image): array
    {
        $contents = null;
        $filename = 'reference.png';
        if (is_string($image['base64'] ?? null) && $image['base64'] !== '') {
            $contents = base64_decode($image['base64'], true);
            $filename = 'reference.'.str_replace('image/', '', (string) ($image['media_type'] ?? 'image/png'));
        }
        elseif (is_string($image['url'] ?? null) && $image['url'] !== '') {
            $contents = @file_get_contents($image['url']);
            $path = parse_url($image['url'], PHP_URL_PATH);
            $filename = $path ? basename($path) : $filename;
        }
        if ($contents === false || $contents === null) {
            throw new AiImportException('Không đọc được ảnh reference để gửi multipart.', 'AI_IMAGE_READ_FAILED');
        }

        return ['name' => 'image', 'contents' => $contents, 'filename' => $filename, 'headers' => ['Content-Type' => (string) ($image['media_type'] ?? 'application/octet-stream')]];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dựng parts Gemini: text prompt + inline image data.
     * =====================================================================
     * INPUT: prompt và mảng `{base64, media_type}` của từng ảnh.
     * OUTPUT: contents.parts theo contract Gemini (text trước, inlineData sau).
     * =====================================================================
     */
    private function buildGeminiParts(string $prompt, array $images): array
    {
        $parts = [['text' => $prompt]];
        foreach ($images as $image) {
            $data = is_string($image['base64'] ?? null) ? $image['base64'] : '';
            if ($data === '') {
                continue;
            }
            $parts[] = ['inlineData' => [
                'mimeType' => (string) ($image['media_type'] ?? 'image/png'),
                'data' => $data,
            ]];
        }

        return $parts;
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
        $maxBytes = (int) config('ai.import.max_image_bytes', 10 * 1024 * 1024);
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
