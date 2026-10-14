<?php

namespace App\Services\Resources;

use App\Enums\ResourceStatus;
use App\Enums\ResourceVisibility;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\ResourceVersion;
use App\Models\TemplateShareLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối download template theo version và capability
 * =====================================================================
 *
 * Service dùng chung policy public/customer/share. Download record được tạo
 * trước khi cấp bearer grant; khi stream chỉ chuyển started một lần thành
 * completed để retry không tăng counter nhiều lần.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - startCustomer(): kiểm policy/quota và cấp grant cho customer đã verify.
 * - startShare(): cấp grant download từ share link capability download.
 * - stream(): resolve grant, mark download và stream ZIP private.
 * - resolveVersion(): resolve latest/default hoặc version cố định.
 * - assertCustomer(): kiểm tra tài khoản active/email verified.
 * - createDownload(): ghi lịch sử và idempotency key.
 * - complete(): chốt lịch sử một lần và tăng resource counter.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể): ResourceVersion/capability -> URL hoặc
 * BinaryFileResponse; không trả đường dẫn private cho client.
 * =====================================================================
 */
final class TemplateDownloadService
{
    public function __construct(
        private readonly TemplateAccessService $access,
        private readonly TemplatePreviewService $preview,
    ) {}

    /** Input: public Resource, version tùy chọn, customer và request. Output: grant URL ghim version. */
    public function startCustomer(Resource $resource, ?string $version, Customer $customer, Request $request): array
    {
        $this->assertCustomer($customer);
        abort_unless($resource->status === ResourceStatus::Published
            && $resource->visibility === ResourceVisibility::Public
            && $resource->published_at?->lte(now()), 404);

        $candidate = $this->resolveVersion($resource, $version);
        abort_unless($candidate, 404);
        $this->preview->template($candidate, true);
        $this->assertDailyQuota($customer);
        $download = $this->createDownload($candidate, $customer, $request);
        [, $token] = $this->access->issue($candidate, 'download', 'customer', [
            'customer_id' => $customer->getKey(),
            'download_id' => $download->id,
            'ip_hash' => $this->ipHash($request),
        ]);

        return $this->grantPayload($candidate, $token);
    }

    /** Input: share link capability download và request. Output: grant URL ghim version. */
    public function startShare(TemplateShareLink $share, Request $request): array
    {
        abort_unless($share->capability === 'download', 403);
        abort_if($share->revoked_at || $share->expires_at->lte(now()), 410);
        $candidate = ResourceVersion::query()->with('resource')->findOrFail($share->resource_version_id);
        $this->preview->template($candidate, true);
        $download = $this->createDownload($candidate, null, $request);
        [, $token] = $this->access->issue($candidate, 'download', 'share', [
            'share_link_id' => $share->getKey(),
            'download_id' => $download->id,
            'ip_hash' => $this->ipHash($request),
        ]);

        return $this->grantPayload($candidate, $token);
    }

    /** Input: raw bearer token. Output: private ZIP download response. */
    public function stream(string $token, Request $request): BinaryFileResponse
    {
        [$grant, $version] = $this->access->resolve($token, 'download');
        abort_unless($grant->download_id, 410);
        if ($grant->ip_hash) {
            abort_unless(hash_equals((string) $grant->ip_hash, $this->ipHash($request)), 403);
        }

        $package = app(\App\Services\ResourceVersionPackageGuard::class)->cleanPackageFor($version);
        $media = $package->getFirstMedia('library');
        abort_unless($media && is_file($media->getPath()), 404);
        $this->complete((int) $grant->download_id, $version);

        return response()->download($media->getPath(), $this->safeFilename($version), [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => 'application/zip',
        ]);
    }

    /** Input: resource/version. Output: ready default or explicitly requested version. */
    private function resolveVersion(Resource $resource, ?string $version): ?ResourceVersion
    {
        $query = $resource->versions()->with('resource');
        if ($version === null || $version === '') {
            $query->where('is_default', true);
        } else {
            $query->where('version', $version);
        }

        return $query->where('status', 'ready')->first();
    }

    /** Input: customer. Output: void; throws 403 if policy is not satisfied. */
    private function assertCustomer(Customer $customer): void
    {
        abort_unless($customer->isActive(), 403);
        if (config('resource-templates.download_auth') === 'verified_customer') {
            abort_unless($customer->email_verified_at !== null, 403, 'Cần xác minh email trước khi tải template.');
        }
    }

    /** Input: customer. Output: void; rejects daily quota before a new record. */
    private function assertDailyQuota(Customer $customer): void
    {
        $count = DB::table('downloads')
            ->where('customer_id', $customer->getKey())
            ->where('downloaded_at', '>=', now()->startOfDay())
            ->whereIn('status', ['started', 'completed'])
            ->count();
        abort_if($count >= (int) config('resource-templates.daily_download_limit', 20), 429, 'Đã vượt quota download trong ngày.');
    }

    /** Input: candidate/customer/request. Output: download row; transaction-safe idempotency. */
    private function createDownload(ResourceVersion $version, ?Customer $customer, Request $request): object
    {
        $requestKey = (string) $request->header('Idempotency-Key');
        $requestKey = Str::isUuid($requestKey) ? $requestKey : (string) Str::uuid();

        return DB::transaction(function () use ($version, $customer, $request, $requestKey): object {
            $existing = DB::table('downloads')->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->resource_version_id === (int) $version->getKey(), 409, 'Idempotency key đã dùng cho version khác.');

                return (object) ['id' => $existing->id];
            }
            $id = DB::table('downloads')->insertGetId([
                'customer_id' => $customer?->getKey(),
                'resource_id' => $version->resource_id,
                'resource_version_id' => $version->getKey(),
                'media_id' => $this->previewMediaId($version),
                'ip_hash' => $this->ipHash($request),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000),
                'status' => 'started',
                'downloaded_at' => now(),
                'request_key' => $requestKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return (object) ['id' => $id];
        });
    }

    /** Input: ready version. Output: physical media primary key used in downloads history. */
    private function previewMediaId(ResourceVersion $version): int
    {
        $package = app(\App\Services\ResourceVersionPackageGuard::class)->cleanPackageFor($version);

        return (int) $package->getFirstMedia('library')->getKey();
    }

    /** Input: download id/version. Output: void; complete/counter update happens once. */
    private function complete(int $downloadId, ResourceVersion $version): void
    {
        DB::transaction(function () use ($downloadId, $version): void {
            $updated = DB::table('downloads')->where('id', $downloadId)->where('status', 'started')->update([
                'status' => 'completed', 'downloaded_at' => now(), 'updated_at' => now(),
            ]);
            if ($updated === 1) {
                DB::table('resources')->where('id', $version->resource_id)->increment('download_count');
            }
        });
    }

    /** Input: version/token. Output: bounded response payload without private path. */
    private function grantPayload(ResourceVersion $version, string $token): array
    {
        return [
            'version_id' => $version->getKey(),
            'version' => $version->version,
            'url' => url('/template-download/'.$token),
            'expires_at' => now()->addMinutes((int) config('resource-templates.download_minutes', 5))->toIso8601String(),
        ];
    }

    /** Input: HTTP request. Output: stable keyed hash, never raw IP. */
    private function ipHash(Request $request): string
    {
        return hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
    }

    /** Input: version. Output: safe archive filename. */
    private function safeFilename(ResourceVersion $version): string
    {
        $title = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $version->resource?->title) ?: 'template';

        return trim($title, '-').'-v'.$version->version.'.zip';
    }
}
