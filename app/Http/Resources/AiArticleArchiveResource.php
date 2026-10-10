<?php

namespace App\Http\Resources;

use App\Services\Content\ContentHtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: DTO chi tiết bản AI gốc đã duyệt trong kho lâu dài.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: toArray(), safeSourceHtml().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : archive approved từ API read-only.
 * - OUTPUT: source/draft/context đã allowlist và sanitize; không lộ diagnostics/credential.
 * =====================================================================
 */
final class AiArticleArchiveResource extends JsonResource
{
    /**
     * Input: archive approved. Output: chi tiết snapshot gốc và liên kết Post.
     * Side effect: sanitize HTML trong memory; không sửa payload đã lưu.
     */
    public function toArray(Request $request): array
    {
        $draft = (array) $this->draft_snapshot_json;
        $source = (array) $this->source_snapshot_json;
        $context = (array) $this->context_snapshot_json;
        $lifecycle = (array) $this->lifecycle_json;
        $post = $this->relationLoaded('post') ? $this->post : null;
        $isPost = $this->target_type === 'post';
        $originalAvailable = (bool) $this->has_generated_content && $draft !== [];

        return [
            'id' => $this->id,
            'run_id' => $this->run_id,
            'generation_no' => (int) $this->generation_no,
            'snapshot_version' => (int) $this->snapshot_version,
            'target_type' => $this->target_type,
            'target_id' => $this->applied_target_id,
            'title' => mb_substr((string) ($draft['title'] ?? $post?->title ?? 'Bản AI #'.$this->id), 0, 255),
            'content_origin' => $this->content_origin,
            'has_generated_content' => (bool) $this->has_generated_content,
            'approved_at' => $lifecycle['reviewed_at'] ?? $this->created_at?->toIso8601String(),
            'archived_at' => $this->created_at?->toIso8601String(),
            'lifecycle' => [
                'review_status' => $lifecycle['review_status'] ?? 'approved',
                'reviewed_by' => $lifecycle['reviewed_by'] ?? null,
                'reviewed_at' => $lifecycle['reviewed_at'] ?? null,
                'applied_at' => $lifecycle['applied_at'] ?? null,
                'applied_fields' => array_values((array) ($lifecycle['applied_fields'] ?? [])),
                'quality_evaluation' => $lifecycle['quality_evaluation'] ?? null,
            ],
            'source' => [
                'available' => $originalAvailable && $source !== [],
                'title' => (string) ($source['title'] ?? ''),
                'content_html' => $this->safeSourceHtml($source),
                'source_url' => $this->safeUrl($source['source_url'] ?? null),
            ],
            'draft' => $originalAvailable ? [
                'title' => (string) ($draft['title'] ?? ''),
                'content_html' => app(ContentHtmlSanitizer::class)->sanitize((string) ($draft['content_html'] ?? '')),
                'excerpt' => $draft['excerpt'] ?? null,
                'focus_keyword' => $draft['focus_keyword'] ?? null,
                'seo_title' => $draft['seo_title'] ?? null,
                'seo_description' => $draft['seo_description'] ?? null,
                'canonical_url' => $this->safeUrl($draft['canonical_url'] ?? null),
                'robots_index' => $draft['robots_index'] ?? null,
                'robots_follow' => $draft['robots_follow'] ?? null,
                'og_title' => $draft['og_title'] ?? null,
                'og_description' => $draft['og_description'] ?? null,
            ] : null,
            'original_available' => $originalAvailable,
            'original_missing_reason' => data_get($context, 'missing_original_reason'),
            'context' => [
                'language' => $context['language'] ?? null,
                'rewrite_style' => $context['rewrite_style'] ?? null,
                'provider' => $context['provider'] ?? null,
                'model' => $context['model'] ?? null,
                'prompt_key' => $context['prompt_key'] ?? null,
                'prompt_version' => $context['prompt_version'] ?? null,
                'generation_mode' => $context['generation_mode'] ?? null,
                'writing_profile' => [
                    'id' => data_get($context, 'writing_profile.id'),
                    'name' => data_get($context, 'writing_profile.name'),
                    'version' => data_get($context, 'writing_profile.version'),
                ],
            ],
            'target' => [
                'type' => $this->target_type,
                'id' => $this->applied_target_id,
                'label' => $isPost ? ($post?->title ?? 'Post đã xóa') : null,
            ],
            'post' => $isPost && $post ? [
                'id' => $post->id,
                'title' => $post->title,
                'status' => $post->status?->value ?? $post->status,
            ] : null,
        ];
    }

    /** Input: source snapshot allowlist. Output: sanitized HTML or escaped text paragraphs. */
    private function safeSourceHtml(array $source): string
    {
        $html = (string) ($source['content_html'] ?? '');
        if ($html === '') {
            $text = (string) ($source['text'] ?? $source['content_text'] ?? $source['source_text'] ?? '');
            $html = $text === '' ? '' : '<p>'.nl2br(e($text)).'</p>';
        }

        return app(ContentHtmlSanitizer::class)->sanitize($html);
    }

    /** Input: URL từ snapshot đã redact. Output: chỉ URL http/https hợp lệ. */
    private function safeUrl(mixed $value): ?string
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $value : null;
    }
}
