<?php

namespace Tests\Unit;

use App\Models\ResourceVersionTemplate;
use App\Services\Resources\TemplatePreviewService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm allowlist/path/CSP của live template preview TPL-08
 * =====================================================================
 * INPUT: template snapshot synthetic và private artifact fake.
 * OUTPUT: file hợp lệ hoặc HTTP 404/headers an toàn; không đọc ZIP/network.
 * =====================================================================
 */
final class TemplatePreviewServiceTest extends TestCase
{
    public function test_file_allows_manifest_relative_path_and_rejects_traversal(): void
    {
        Storage::fake('media_private');
        $token = (string) Str::uuid();
        $relative = 'template-previews/901/'.$token;
        File::ensureDirectoryExists(Storage::disk('media_private')->path($relative));
        Storage::disk('media_private')->put($relative.'/index.html', '<h1>ok</h1>');
        Storage::disk('media_private')->put($relative.'/assets/app.css', 'body{}');
        $template = new ResourceVersionTemplate([
            'resource_version_id' => 901,
            'processing_token' => $token,
            'preview_path' => $relative,
            'manifest_json' => ['files' => ['index.html', 'assets/app.css']],
        ]);
        $service = app(TemplatePreviewService::class);

        [$file, $mime] = $service->file($template, 'assets/app.css');
        $this->assertFileExists($file);
        $this->assertSame('text/css; charset=UTF-8', $mime);
        $this->expectException(HttpException::class);
        $service->file($template, '../index.html');
    }

    public function test_headers_disable_cache_and_same_origin_privilege(): void
    {
        $headers = app(TemplatePreviewService::class)->headers();

        $this->assertSame('private, no-store, max-age=0', $headers['Cache-Control']);
        $this->assertStringContainsString('sandbox allow-scripts', $headers['Content-Security-Policy']);
        $this->assertStringNotContainsString('allow-same-origin', $headers['Content-Security-Policy']);
    }
}
