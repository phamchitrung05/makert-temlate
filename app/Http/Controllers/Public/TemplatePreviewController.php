<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\Resources\TemplateAccessService;
use App\Services\Resources\TemplatePreviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Host iframe và static files cho capability preview
 * CÁC HÀM/METHOD TRONG FILE: host(): menu/iframe; file(): byte stream allowlist.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): grant/path -> HTML host hoặc static file.
 * =====================================================================
 */
final class TemplatePreviewController extends Controller
{
    /** Input: bearer grant, screen query. Output: HTML host/menu và iframe sandbox. */
    public function host(Request $request, string $token, TemplateAccessService $access)
    {
        [, $version, $template] = $access->resolve($token, 'preview');
        $screen = $template->screens->firstWhere('slug', $request->query('screen'))
            ?? $template->screens->firstWhere('slug', $template->entry_screen_slug)
            ?? $template->screens->first();
        abort_unless($screen, 409);
        $prefix = '/template-preview/'.$token;
        $src = $prefix.'/site/'.implode('/', array_map('rawurlencode', explode('/', $screen->source_path)));
        $candidate = data_get($screen->metadata, 'screenshot');
        $screenshot = null;
        if (is_string($candidate)) {
            try {
                [$file] = app(TemplatePreviewService::class)->file($template, $candidate);
                if (@getimagesize($file) !== false) {
                    $screenshot = $prefix.'/site/'.implode('/', array_map('rawurlencode', explode('/', $candidate)));
                }
            } catch (\Throwable) {
                // Screenshot là fallback tùy chọn, không làm hỏng HTML preview.
            }
        }
        Log::info('Template preview opened.', ['version_id' => $version->id, 'screen_id' => $screen->id]);

        return response()->view('public.templates.preview', [
            'title' => $version->resource->title, 'version' => $version->version,
            'screens' => $template->screens->map(fn ($item) => ['slug' => $item->slug, 'title' => $item->title])->all(),
            'prefix' => $prefix, 'src' => $src, 'screenshot' => $screenshot,
            'warnings' => array_slice((array) data_get($template->manifest_json, 'warnings', []), 0, 20),
        ])->header('Content-Security-Policy', "default-src 'none'; frame-src 'self'; img-src 'self'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; object-src 'none'");
    }

    /** Input: grant/path. Output: file có MIME/CSP sandbox; không include/execute code upload. */
    public function file(string $token, string $path, TemplateAccessService $access, TemplatePreviewService $preview)
    {
        [, , $template] = $access->resolve($token, 'preview');
        [$file, $mime] = $preview->file($template, $path);

        return response()->file($file, [
            ...$preview->headers(),
            'Content-Type' => $mime,
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}
