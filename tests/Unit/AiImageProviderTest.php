<?php

namespace Tests\Unit;

use App\Services\Ai\AiConnection;
use App\Services\Ai\HttpAiImageProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khóa image protocol native Gemini/OpenAI-compatible offline.
 * =====================================================================
 * CÁC HÀM/METHOD: test_openai_image_contract(), test_gemini_image_contract().
 * INPUT: fake JSON provider và immutable connection snapshot.
 * OUTPUT: bytes ảnh; API key chỉ xuất hiện trong header fake server-side.
 * SIDE EFFECT: Http::fake; không gọi provider thật, không ghi database.
 * EXCEPTION/TRANSACTION: response thiếu image phải là domain error; không transaction.
 * =====================================================================
 */
final class AiImageProviderTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    /**
     * =====================================================================
     * CHỨC NĂNG: Gửi image request OpenAI-compatible và đọc base64
     * =====================================================================
     * INPUT: fake images/generations response.
     * OUTPUT: binary PNG không làm lộ API key trong URL.
     * SIDE EFFECT: một HTTP fake POST; không mở transaction.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function test_openai_image_contract(): void
    {
        Http::fake(['https://images.example/v1/images/generations' => Http::response([
            'data' => [['b64_json' => self::PNG]],
        ])]);
        $connection = new AiConnection([
            'provider' => 'gateway', 'driver' => 'openai-compatible',
            'base_url' => 'https://images.example/v1', 'model' => 'image-model', 'timeout' => 10,
        ], 'server-only-key');

        $bytes = app(HttpAiImageProvider::class)->generate($connection, 'A clean illustration');

        $this->assertSame("\x89PNG", substr($bytes, 0, 4));
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer server-only-key')
            && ! str_contains($request->url(), 'server-only-key'));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gửi Gemini generateContent với response modality image
     * =====================================================================
     * INPUT: fake inlineData response.
     * OUTPUT: image bytes từ native Gemini response.
     * SIDE EFFECT: một HTTP fake POST; không mở transaction.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function test_gemini_image_contract(): void
    {
        Http::fake(['https://generative.example/v1beta/models/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['inlineData' => [
                'mimeType' => 'image/png', 'data' => self::PNG,
            ]]]]]],
        ])]);
        $connection = new AiConnection([
            'provider' => 'gemini', 'driver' => 'gemini',
            'base_url' => 'https://generative.example/v1beta', 'model' => 'gemini-image', 'timeout' => 10,
        ], 'server-only-key');

        $bytes = app(HttpAiImageProvider::class)->generate($connection, 'A clean illustration');

        $this->assertSame("\x89PNG", substr($bytes, 0, 4));
        Http::assertSent(fn ($request): bool => $request->hasHeader('x-goog-api-key', 'server-only-key')
            && ! str_contains($request->url(), 'key='));
    }
}
