<?php

namespace App\Services\Resources;

use App\Enums\ResourceStatus;
use App\Enums\ResourceVisibility;
use App\Models\Resource;
use App\Models\ResourceVersion;
use App\Models\TemplateAccessGrant;
use App\Models\TemplateShareLink;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cấp/kiểm grant preview và share ghim version/build
 * CÁC HÀM/METHOD TRONG FILE:
 * - publiclyVisible(): cổng published/public/time/primary slug.
 * - issue(): cấp token ngẫu nhiên, chỉ lưu hash và build identity.
 * - resolve(): xác minh expiry/revoke/actor/share/scan mỗi request.
 * - share(): cấp share capability explicit cho ready version.
 * - resolveShare(): xác minh share hash/expiry/revoke.
 * - previewUrl(): build URL trên preview origin được cấu hình.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): version/actor/capability -> URL hoặc 403/404/410.
 * =====================================================================
 */
final class TemplateAccessService
{
    /** Input: preview service. Output: capability service sẵn sàng. */
    public function __construct(private readonly TemplatePreviewService $preview) {}

    /** Input: Resource. Output: true nếu public và có URL primary locale hiện tại. */
    public function publiclyVisible(Resource $resource): bool
    {
        return $resource->status === ResourceStatus::Published
            && $resource->visibility === ResourceVisibility::Public
            && $resource->published_at && $resource->published_at->lte(now())
            && $resource->slugs()->primary()->exists();
    }

    /** Input: version/capability/audience/bindings. Output: [grant, raw token duy nhất lần cấp]. */
    public function issue(ResourceVersion $version, string $capability, string $audience, array $bindings = []): array
    {
        abort_unless(in_array($capability, ['preview', 'download'], true), 422);
        abort_unless(in_array($audience, ['admin', 'public', 'share', 'customer'], true), 422);
        $template = $this->preview->template($version, $audience !== 'admin');
        if (in_array($audience, ['public', 'customer'], true)) {
            abort_unless($this->publiclyVisible($version->resource), 404);
        }
        $token = Str::random(64);
        $grant = TemplateAccessGrant::query()->create([
            'token_hash' => hash('sha256', $token),
            'resource_version_id' => $version->id,
            'processing_token' => $template->processing_token,
            'capability' => $capability,
            'audience' => $audience,
            ...array_intersect_key($bindings, array_flip(['user_id', 'customer_id', 'share_link_id', 'download_id', 'ip_hash'])),
            'expires_at' => now()->addMinutes((int) config('resource-templates.'.($capability === 'download' ? 'download_minutes' : 'grant_minutes'))),
        ]);

        return [$grant, $token];
    }

    /** Input: bearer token và capability. Output: [grant, version, template] đã kiểm quyền lại. */
    public function resolve(string $token, string $capability): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/D', $token), 404);
        $grant = TemplateAccessGrant::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_unless($grant->capability === $capability, 403);
        abort_if($grant->revoked_at || $grant->expires_at->lte(now()), 410);
        $version = ResourceVersion::query()->with('resource')->findOrFail($grant->resource_version_id);
        if ($grant->audience === 'admin') {
            $actor = User::query()->find($grant->user_id);
            abort_unless($actor && $actor->status === 'active' && $actor->can('resource_versions.manage'), 403);
        } elseif ($grant->audience === 'share') {
            $share = TemplateShareLink::query()->findOrFail($grant->share_link_id);
            abort_if($share->revoked_at || $share->expires_at->lte(now()), 410);
            abort_unless((int) $share->resource_version_id === (int) $version->id && $share->capability === $capability, 403);
        } else {
            abort_unless($this->publiclyVisible($version->resource), 404);
        }
        abort_if($version->resource->status === ResourceStatus::Archived, 404);
        $template = $this->preview->template($version, $grant->audience !== 'admin');
        abort_unless(hash_equals((string) $grant->processing_token, (string) $template->processing_token), 410);

        return [$grant, $version, $template];
    }

    /** Input: ready version/admin/capability/expiry. Output: raw token chỉ trả một lần, id/url/expiry. */
    public function share(ResourceVersion $version, User $actor, string $capability, int $days): array
    {
        abort_unless($actor->can('resource_versions.manage'), 403);
        abort_unless(in_array($capability, ['preview', 'download'], true), 422);
        $this->preview->template($version, true);
        $token = Str::random(64);
        $share = TemplateShareLink::query()->create([
            'token_hash' => hash('sha256', $token),
            'resource_version_id' => $version->id,
            'capability' => $capability,
            'created_by' => $actor->id,
            'expires_at' => now()->addDays(max(1, min((int) config('resource-templates.share_max_days'), $days))),
        ]);
        activity('resources')->performedOn($version)->causedBy($actor)->event('template.share.created')
            ->withProperties(['share_id' => $share->id, 'capability' => $capability])->log('Template share created.');

        return ['id' => $share->id, 'url' => url('/template-shares/'.$token), 'expires_at' => $share->expires_at->toIso8601String(), 'capability' => $capability];
    }

    /** Input: raw share token. Output: share chưa hết hạn/revoke. */
    public function resolveShare(string $token): TemplateShareLink
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/D', $token), 404);
        $share = TemplateShareLink::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_if($share->revoked_at || $share->expires_at->lte(now()), 410);

        return $share;
    }

    /** Input: raw grant. Output: host URL, không log token. */
    public function previewUrl(string $token): string
    {
        $origin = rtrim((string) config('resource-templates.preview_origin'), '/');

        return ($origin !== '' ? $origin : url('')).'/template-preview/'.$token;
    }
}
