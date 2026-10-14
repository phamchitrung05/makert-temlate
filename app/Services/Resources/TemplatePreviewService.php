<?php

namespace App\Services\Resources;

use App\Enums\ResourceType;
use App\Enums\ResourceVersionStatus;
use App\Enums\ResourceVersionTemplateProcessingStatus;
use App\Models\ResourceVersion;
use App\Models\ResourceVersionTemplate;
use App\Services\ResourceVersionPackageGuard;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Resolve private artifact và file allowlist cho preview
 * CÁC HÀM/METHOD TRONG FILE:
 * - template(): recheck package/status/checksum/build mỗi request.
 * - root(): đối chiếu pointer với version/token, chặn symlink.
 * - file(): chỉ resolve file có trong manifest, MIME allowlist.
 * - headers(): sandbox/CSP, private no-store và nosniff.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): version/path -> private file/headers hoặc 404/409.
 * =====================================================================
 */
final class TemplatePreviewService
{
    /** Input: package guard. Output: resolver không thay đổi lifecycle. */
    public function __construct(private readonly ResourceVersionPackageGuard $packages) {}

    /** Input: version, requireReleased. Output: template đã recheck hoặc abort 404/409. */
    public function template(ResourceVersion $version, bool $requireReleased = false): ResourceVersionTemplate
    {
        $version->loadMissing('resource');
        abort_unless($version->resource?->type === ResourceType::Template, 404);
        abort_if($version->status === ResourceVersionStatus::Archived, 404);
        abort_if($requireReleased && $version->status !== ResourceVersionStatus::Ready, 404);
        $package = $this->packages->cleanPackageFor($version);
        $template = $version->template()->with('screens')->first();
        abort_unless($template && $template->processing_status === ResourceVersionTemplateProcessingStatus::Ready, 409, 'Template chưa xử lý xong.');
        $media = $package->getFirstMedia('library');
        abort_unless($media && is_file($media->getPath()), 409, 'Package không khả dụng.');
        $checksum = hash_file('sha256', $media->getPath());
        abort_unless((int) $package->id === (int) $template->source_media_id && is_string($checksum)
            && hash_equals((string) $template->package_sha256, $checksum), 409, 'Package đã thay đổi; cần xử lý lại.');
        $this->root($template);

        return $template;
    }

    /** Input: template snapshot. Output: absolute root nội bộ; abort nếu token/pointer/symlink sai. */
    public function root(ResourceVersionTemplate $template): string
    {
        abort_unless(Str::isUuid((string) $template->processing_token), 409, 'Artifact không khả dụng.');
        $relative = trim((string) config('media-assets.template_artifact_directory'), '/').'/'.$template->resource_version_id.'/'.$template->processing_token;
        abort_unless($relative === $template->preview_path, 409, 'Artifact không khả dụng.');
        $disk = Storage::disk((string) config('resource-templates.artifact_disk', 'media_private'));
        $root = $disk->path($relative);
        $cursor = $disk->path('');
        foreach (explode('/', $relative) as $segment) {
            $cursor = rtrim($cursor, '/\\').DIRECTORY_SEPARATOR.$segment;
            abort_if(is_link($cursor), 404);
        }
        abort_unless(is_dir($root) && realpath($root), 409, 'Artifact không khả dụng; hãy rebuild.');

        return realpath($root);
    }

    /** Input: template, logical path đã URL-decode một lần bởi router. Output: [file, MIME] hoặc 404. */
    public function file(ResourceVersionTemplate $template, string $path): array
    {
        // Router đã decode; không decode lần hai các chuỗi %2e/%2f.
        abort_if(str_contains($path, '%') || str_contains($path, '\\') || str_contains($path, "\0"), 404);
        abort_unless(app(TemplateManifestValidator::class)->normalizeRelativePath($path) !== null, 404);
        $files = (array) data_get($template->manifest_json, 'files', []);
        abort_unless(in_array($path, $files, true), 404);
        $mime = config('resource-templates.mime_types.'.strtolower(pathinfo($path, PATHINFO_EXTENSION)));
        abort_unless(is_string($mime), 404);
        $root = $this->root($template);
        $cursor = $root;
        foreach (explode('/', $path) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR.$segment;
            abort_if(is_link($cursor), 404);
        }
        $resolved = realpath($cursor);
        abort_unless($resolved && is_file($resolved) && str_starts_with($resolved, $root.DIRECTORY_SEPARATOR), 404);

        return [$resolved, $mime];
    }

    /** Input: không có. Output: CSP allowlist do ứng dụng cấp, không tin package. */
    public function headers(): array
    {
        $hosts = static fn (string $key): string => implode(' ', array_filter(
            (array) config('resource-templates.'.$key, []),
            static fn ($host): bool => is_string($host) && preg_match('~^https://[a-z0-9.-]+$~i', $host) === 1,
        ));
        $csp = "sandbox allow-scripts; default-src 'none'; base-uri 'none'; object-src 'none'; connect-src 'none'; form-action 'none'; frame-src 'none'; "
            ."script-src 'self' 'unsafe-inline' ".$hosts('script_hosts').'; '
            ."style-src 'self' 'unsafe-inline' ".$hosts('style_hosts').'; '
            ."img-src 'self' data: ".$hosts('image_hosts').'; '
            ."font-src 'self' ".$hosts('font_hosts').';';

        return [
            'Content-Security-Policy' => $csp,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        ];
    }
}
