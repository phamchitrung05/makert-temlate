<?php

namespace App\Services\Ai\Content;

use App\Actions\Media\UploadMediaAssetAction;
use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Exceptions\AiImportException;
use App\Models\AiImport;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Tag;
use App\Models\User;
use App\Services\Ai\Content\Pipelines\ArticleGenerationPipeline;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Contracts\AiResponseMetadataProvider;
use App\Services\Ai\Providers\Diagnostics\AiResponseDiagnostics;
use App\Services\Ai\Registries\PromptRegistry;
use App\Services\Ai\Registries\ProviderRegistry;
use App\Services\Media\ContentMediaReferenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Pipeline trích xuất, sanitize và dựng draft bài viết AI.
 * =====================================================================
 *
 * Service được queue job gọi sau khi URL đã qua FormRequest; nó giữ boundary
 * fetch/extract/provider/sanitize và không tự tạo Post hoặc publish.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(), run(), fallbackDraft(), progress(), title(), meta().
 * - extract(), sanitize(), inlineSource(), mergeRequestedFields(), existingIds(), persistResponseMetadata(),
 *   createThumbnail(), providerFor(), validateMediaReferences().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : AiImport cùng URL/options, provider contract và uploader media tùy chọn.
 * - OUTPUT: source metadata, draft canonical và nguồn gốc field để archive; chưa publish.
 * - SIDE EFFECT: cập nhật progress; tùy chọn tạo MediaAsset thumbnail public.
 * - EXCEPTION/TRANSACTION: AiImportException cho lỗi nguồn/provider; asset được
 *   tạo qua UploadMediaAssetAction để dùng chung transaction/security pipeline.
 */
class ArticleImportService
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận provider, source fetcher và media uploader theo contract
     * =====================================================================
     * INPUT: dependency đã được container resolve; fetcher/uploader có thể null
     * để service tạo fallback tương thích khi chạy unit test.
     * OUTPUT: service sẵn sàng xử lý một AiImport.
     * SIDE EFFECT: không gọi network hoặc database khi khởi tạo.
     * EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function __construct(
        private readonly AiProviderContract $provider,
        private readonly ?ArticleSourceFetcher $fetcher = null,
        private readonly ?UploadMediaAssetAction $uploader = null,
        private readonly ?ProviderRegistry $providers = null,
        private readonly ?AiOutputValidator $validator = null,
    ) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Fetch, extract, rewrite và dựng kết quả candidate cho AiImport
     * =====================================================================
     * INPUT: AiImport queued hoặc model đang chạy; URL/options trong input_json.
     * OUTPUT: array source/draft/provider/prompt metadata.
     * SIDE EFFECT: cập nhật progress và có thể tạo thumbnail MediaAsset.
     * EXCEPTION/TRANSACTION: ném AiImportException; không mở transaction tổng,
     *   uploader tự áp dụng boundary media của nó.
     * =====================================================================
     *
     * @return array<string,mixed>
     */
    public function run(AiImport $import): array
    {
        $fetcher = $this->fetcher ?? new ArticleSourceFetcher;
        $input = is_array($import->input_json) ? $import->input_json : [];
        $sourceType = (string) ($input['source_type'] ?? (filled($import->source_url) ? 'url' : 'text'));
        $url = $sourceType === 'text' ? '' : $fetcher->validateUrl((string) $import->source_url);
        $parent = $import->parent_id ? AiImport::query()->find($import->parent_id) : null;
        $ownSnapshot = data_get($import->source_meta_json, 'article_source');
        $snapshot = $ownSnapshot ?: (($input['refresh_source'] ?? false) ? null : data_get($parent?->source_meta_json, 'article_source'));
        $this->progress($import, 'fetching', 15);
        $source = is_array($snapshot)
            ? ['url' => $snapshot['source_url'] ?? $url, 'html' => $snapshot['content_html'], 'content_type' => $snapshot['content_type'] ?? 'text/html']
            : ($sourceType === 'text'
            ? $this->inlineSource((string) $import->source_text, $import)
            : $fetcher->fetch($url));
        $this->progress($import, 'extracting', 35);

        $html = $source['html'];
        $title = $snapshot['title'] ?? ($this->meta($html, 'og:title') ?: $this->title($html) ?: 'Bài viết mới');
        $description = $snapshot['description'] ?? $this->meta($html, 'description');
        $content = $snapshot['content_html'] ?? $this->extract($html, (string) $source['url']);
        if ($content === '') {
            throw new AiImportException('Không tìm thấy nội dung bài viết trong URL.', 'SOURCE_EMPTY');
        }
        if (! is_array($snapshot)) {
            $snapshot = (new ArticleSourceExtractor)->snapshot($content, [
                'source_url' => $source['url'], 'canonical_url' => $this->meta($html, 'canonical') ?: $source['url'],
                'content_type' => $source['content_type'], 'title' => $title, 'description' => $description,
                'thumbnail_source_url' => $this->meta($html, 'og:image'),
            ], (array) ($input['pipeline_snapshot'] ?? config('ai.content', [])));
            $content = $snapshot['content_html'];
        }
        $parentDraft = array_key_exists('parent_draft_snapshot', $input)
            ? (array) $input['parent_draft_snapshot']
            : (array) data_get($parent?->result_json, 'draft', []);
        if (! is_array($ownSnapshot) || ! array_key_exists('inline_image_refs', $snapshot)
            || ($snapshot['snapshot_run_id'] ?? null) !== (string) $import->getKey()) {
            $parentContent = (string) ($parentDraft['content_html'] ?? '');
            $this->validateMediaReferences($parentContent, $import);
            $snapshot['inline_image_refs'] = (new ArticleSourceExtractor)->imageReferences($parentContent);
        }
        $snapshot['snapshot_run_id'] = (string) $import->getKey();
        $this->validateMediaReferences(implode('', (array) $snapshot['inline_image_refs']), $import);
        if ($import->exists) {
            $import->refresh();
            if ($import->isCancelled()) {
                throw new AiImportException('Import đã bị hủy.', 'CANCELLED');
            }
            $import->forceFill(['source_meta_json' => array_replace((array) $import->source_meta_json, ['article_source' => $snapshot])])->save();
        }

        $provider = $this->providerFor($input);
        $prompt = (new PromptRegistry)->select(
            isset($input['prompt_key']) ? (string) $input['prompt_key'] : null,
            (string) ($input['target_type'] ?? 'post'),
            'create',
            ['source_type' => $sourceType, 'language' => (string) ($input['language'] ?? 'vi')],
        );
        $promptKey = (string) $prompt['key'];
        $requestedFields = array_values(array_unique(array_map('strval', (array) ($input['fields'] ?? $input['requested_outputs'] ?? []))));
        if (in_array('taxonomy', $requestedFields, true)) {
            throw new AiImportException('Run cũ còn yêu cầu taxonomy từ AI. Hãy tạo run mới và chọn danh mục/tag thủ công.', 'AI_LEGACY_TAXONOMY');
        }
        $hasFieldSelection = $requestedFields !== [] || (array_key_exists('requested_outputs', $input) && ! $import->parent_id);
        if ($hasFieldSelection && method_exists($provider, 'withOutputFields')) {
            $provider = $provider->withOutputFields($requestedFields);
        }
        $this->progress($import, 'extracting', 40);
        $draftTitle = filled($input['title'] ?? null) ? (string) $input['title'] : $title;
        $draft = $this->fallbackDraft($url, $draftTitle, $description, $content, $html);
        $draft['thumbnail']['source_url'] = $snapshot['thumbnail_source_url'] ?? $draft['thumbnail']['source_url'];
        $sourceDraft = $draft;
        $inheritedFields = $hasFieldSelection && $import->parent_id ? array_keys($parentDraft) : [];
        if ($hasFieldSelection && $import->parent_id) {
            $sourceThumbnail = $draft['thumbnail'];
            $draft = array_replace($draft, $parentDraft);
            if (in_array('thumbnail', $requestedFields, true)) {
                $draft['thumbnail'] = $sourceThumbnail;
            }
        }
        $validator = $this->validator ?? new AiOutputValidator;
        $validationGroups = $hasFieldSelection ? $requestedFields : null;
        $needsTextGeneration = ! $hasFieldSelection || array_diff($requestedFields, ['thumbnail']) !== [];
        $diagnostics = [
            'requested_groups' => $hasFieldSelection ? $requestedFields : ['title', 'content'],
            'schema_version' => (string) $prompt['schema'],
            'stage' => 'source',
        ];
        $generationCalled = false;
        $generatedFields = [];
        $generationMode = 'source_only';
        try {
            if ($needsTextGeneration && $provider->configured()) {
                $diagnostics['stage'] = 'transport';
                $generationCalled = true;
                $fullPostContent = ($input['target_type'] ?? 'post') === 'post' && (! $hasFieldSelection || in_array('content', $requestedFields, true));
                if ($fullPostContent) {
                    $generated = (new ArticleGenerationPipeline)->run($import, $provider, $snapshot, $hasFieldSelection ? $requestedFields : ['title', 'content']);
                } else {
                    $this->progress($import, 'rewriting', 55);
                    $profileInstructions = (string) data_get($input, 'writing_profile_snapshot.style_instructions', '');
                    $instructions = trim((string) ($input['instructions'] ?? '').($profileInstructions === '' ? '' : "\nStyle guidance (explicit article requirements take precedence): ".$profileInstructions));
                    if (! empty($input['writing_brief'])) {
                        $instructions .= "\nArticle brief: ".json_encode($input['writing_brief'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    }
                    $generated = $provider->generate($draftTitle, $content, (string) ($input['language'] ?? 'vi'), (string) ($input['rewrite_style'] ?? 'informative'), $promptKey, $instructions);
                }
                if ($provider instanceof AiResponseMetadataProvider) {
                    $diagnostics = array_replace($diagnostics, $provider->responseMetadata());
                }
                // Source URLs and uploaded media are pipeline results, never model output.
                unset($generated['thumbnail']);
                $diagnostics['returned_fields'] = array_keys($generated);
                $generated = $validator->validate($generated, $validationGroups, sanitizeContent: true);
                $allowedGeneratedFields = $hasFieldSelection
                    ? array_merge(...array_map(fn (string $group): array => (array) config('ai.agent.output_definitions.'.$group.'.fields', []), $requestedFields))
                    : array_keys($generated);
                $generatedFields = array_values(array_intersect(array_keys($generated), $allowedGeneratedFields));
                if (in_array('content', $generatedFields, true) && ! in_array('content_html', $generatedFields, true)) {
                    $generatedFields[] = 'content_html';
                }
                $generationMode = 'ai';
                $inheritedFields = array_values(array_diff($inheritedFields, $generatedFields));
                $draft = $hasFieldSelection
                    ? $this->mergeRequestedFields($draft, $generated, $requestedFields)
                    : array_replace($draft, $generated);
                $diagnostics['stage'] = 'ready';
            } else {
                $selectedProvider = (string) ($input['provider'] ?? data_get($input, 'ai_connection.provider')
                    ?? data_get($input, 'ai_connection.driver') ?? $provider->providerName());
                if ($needsTextGeneration && ($selectedProvider !== 'deterministic' || $provider->providerName() !== 'deterministic')) {
                    throw new AiImportException('Provider AI đã chọn chưa có cấu hình hợp lệ.', 'AI_PROVIDER_NOT_CONFIGURED');
                }
                if ($needsTextGeneration && $hasFieldSelection) {
                    $draft = $this->mergeRequestedFields($draft, $sourceDraft, $requestedFields);
                    $replacedFields = array_merge(...array_map(fn (string $group): array => (array) config('ai.agent.output_definitions.'.$group.'.fields', []), $requestedFields));
                    $inheritedFields = array_values(array_diff($inheritedFields, $replacedFields));
                }
                $validator->validate($draft, $validationGroups, sanitizeContent: true);
                $diagnostics['stage'] = $needsTextGeneration ? 'deterministic' : 'skipped';
                $generationMode = $needsTextGeneration ? 'deterministic' : 'source_only';
            }
        } catch (AiImportException $exception) {
            $providerMetadata = $generationCalled && $provider instanceof AiResponseMetadataProvider ? $provider->responseMetadata() : [];
            $this->persistResponseMetadata($import, array_replace($diagnostics, $providerMetadata, $exception->diagnostics));
            throw $exception;
        }
        $draft['content_html'] = $draft['content_html'] ?? $draft['content'];
        $draft['content'] = $draft['content_html'];
        $this->validateMediaReferences((string) $draft['content_html'], $import);
        $this->persistResponseMetadata($import, $diagnostics);
        // Taxonomy chỉ lấy lựa chọn thủ công của request; không dùng output hoặc gợi ý legacy AI.
        unset($draft['suggested_category_ids'], $draft['suggested_tag_ids']);
        $draft['category_ids'] = $this->existingIds($input['category_ids'] ?? $parentDraft['category_ids'] ?? [], Category::class);
        $draft['tag_ids'] = $this->existingIds($input['tag_ids'] ?? $parentDraft['tag_ids'] ?? [], Tag::class);
        // Nhãn public đi cùng IDs để editor không nhầm lựa chọn thủ công với gợi ý legacy.
        $draft['taxonomy_origin'] = 'manual';

        if (! $hasFieldSelection && ! ($input['generate_seo'] ?? true) && ! in_array('seo', $requestedFields, true)) {
            foreach (['focus_keyword', 'seo_title', 'seo_description', 'og_title', 'og_description'] as $field) {
                unset($draft[$field]);
            }
        }

        $this->progress($import, 'seo', 82);
        $thumbnail = $draft['thumbnail'];
        if (! empty($draft['thumbnail_alt_text'])) {
            $thumbnail['alt_text'] = (string) $draft['thumbnail_alt_text'];
        }
        $thumbnailRequested = ! $hasFieldSelection || in_array('thumbnail', $requestedFields, true);
        if ($thumbnailRequested && ($input['generate_thumbnail'] ?? true) && ($input['thumbnail_mode'] ?? 'auto') !== 'generate'
            && ($thumbnail['source_url'] ?? null) && $this->uploader && $import->exists) {
            $this->progress($import, 'thumbnail', 86);
            $asset = $this->createThumbnail($fetcher, (string) $thumbnail['source_url'], (string) $title, (int) $import->created_by, (string) ($thumbnail['alt_text'] ?? $title));
            if ($asset) {
                $thumbnail['media_asset_id'] = $asset->getKey();
            }
        }
        $draft['thumbnail'] = $thumbnail;
        // Chỉ job ghi ready cùng result_json; không cho editor/xóa thấy candidate chưa lưu xong.

        if ($import->exists) {
            $import->forceFill([
                'normalized_url' => $source['url'],
                'source_meta_json' => array_replace((array) $import->source_meta_json, ['content_type' => $source['content_type'], 'title' => $title]),
            ])->save();
        }

        return [
            'source' => ['url' => $source['url'], 'title' => $title, 'canonical_url' => $snapshot['canonical_url'] ?? $source['url']],
            'draft' => $draft,
            'provider' => $provider->providerName(),
            'model' => $provider->modelName(),
            'prompt_key' => $promptKey,
            'prompt_version' => (string) $prompt['version'],
            'schema_version' => (string) $prompt['schema'],
            'requested_fields' => $requestedFields,
            'generation_meta' => [
                'mode' => $generationMode, 'generated_fields' => $generatedFields, 'inherited_fields' => $inheritedFields,
                'requested_groups' => $hasFieldSelection ? $requestedFields : ['title', 'content'],
            ],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lưu diagnostics provider đã lọc vào metadata của run
     * =====================================================================
     *
     * INPUT:
     * - AiImport và diagnostics allowlist, kể cả khi validation output thất bại.
     *
     * OUTPUT:
     * - void: run đã persist có source_meta_json.ai_response mới; run chỉ ở memory được bỏ qua.
     *
     * SIDE EFFECT:
     * - Refresh run và lưu diagnostics đã sanitize, không đưa diagnostics vào draft.
     *
     * EXCEPTION/TRANSACTION:
     * - Không tự mở transaction hoặc gọi provider; lỗi DB/Eloquent truyền ra pipeline.
     *
     * =====================================================================
     */
    private function persistResponseMetadata(AiImport $import, array $diagnostics): void
    {
        if (! $import->exists) {
            return;
        }
        $import->refresh();
        $import->forceFill([
            'source_meta_json' => array_replace((array) $import->source_meta_json, [
                'ai_response' => AiResponseDiagnostics::sanitize($diagnostics),
            ]),
        ])->save();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trích draft deterministic gồm content/SEO/thumbnail source.
     * =====================================================================
     * INPUT: URL, metadata title/description, HTML đã extract.
     * OUTPUT: draft deterministic.
     * SIDE EFFECT: không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: không ném lỗi nghiệp vụ; không mở transaction.
     * =====================================================================
     */
    private function fallbackDraft(string $url, string $title, string $description, string $content, string $html): array
    {
        $keyword = Str::of($title)->lower()->explode(' ')->filter(fn ($word) => mb_strlen($word) > 4)->take(3)->implode(' ');
        $seoTitle = Str::limit($title, 60, '');
        $seoDescription = Str::limit($description ?: strip_tags($content), 155);

        return [
            'title' => $title, 'content_html' => $content, 'content' => $content,
            'excerpt' => Str::limit(strip_tags($content), 240), 'focus_keyword' => (string) $keyword,
            'seo_title' => $seoTitle, 'seo_description' => $seoDescription, 'canonical_url' => $url,
            'robots_index' => true, 'robots_follow' => true, 'og_title' => $seoTitle, 'og_description' => $seoDescription,
            'thumbnail_prompt' => $title,
            'thumbnail' => ['media_asset_id' => null, 'source_url' => $this->meta($html, 'og:image'), 'alt_text' => $title],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dựng source HTML an toàn từ text inline dùng chung extractor/sanitizer.
     * =====================================================================
     * INPUT: text do admin gửi và AiImport hiện tại.
     * OUTPUT: source map tương thích ArticleSourceFetcher::fetch(), không gọi HTTP.
     * SIDE EFFECT: không ghi database; chỉ escape nội dung trong memory.
     * EXCEPTION/TRANSACTION: AiImportException nếu text rỗng; không transaction.
     *
     * @return array{url:string,html:string,content_type:string}
     *                                                           =====================================================================
     */
    private function inlineSource(string $text, AiImport $import): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new AiImportException('Nội dung nguồn không được để trống.', 'SOURCE_EMPTY');
        }
        if (data_get($import->input_json, 'source_format') === 'html') {
            return ['url' => 'inline://'.$import->getKey(), 'html' => $text, 'content_type' => 'text/html'];
        }

        $title = trim((string) (preg_split('/\R/u', $text)[0] ?? ''));
        $paragraphs = preg_split('/\R{2,}/u', $text) ?: [$text];
        $html = '<html><head><title>'.e(Str::limit($title ?: 'Bài viết mới', 255, '')).'</title></head><body><article>'.collect($paragraphs)
            ->map(fn (string $paragraph): string => '<p>'.nl2br(e(trim($paragraph))).'</p>')
            ->implode('').'</article></body></html>';

        return [
            'url' => 'inline://'.$import->getKey(),
            'html' => $html,
            'content_type' => 'text/plain',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giữ field không được chọn từ nguồn hoặc parent khi tạo từng phần.
     * =====================================================================
     * INPUT: draft parent, draft mới và field selection allowlist.
     * OUTPUT: draft mới chỉ thay nhóm field được yêu cầu; không mutate input.
     * SIDE EFFECT: không gọi database/provider; thumbnail được xử lý ở caller.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    private function mergeRequestedFields(array $parent, array $fresh, array $fields): array
    {
        $merged = $parent;
        foreach ($fields as $group) {
            $canonicalFields = (array) config('ai.agent.output_definitions.'.$group.'.fields', []);
            $values = array_intersect_key($fresh, array_flip($canonicalFields));
            if ($group === 'content' && (isset($fresh['content_html']) || isset($fresh['content']))) {
                $values['content_html'] = $fresh['content_html'] ?? $fresh['content'];
                $values['content'] = $values['content_html'];
            }
            $merged = array_replace($merged, $values);
        }

        return $merged;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cập nhật progress và dừng pipeline khi import bị hủy.
     * =====================================================================
     * INPUT: AiImport và step/progress mới.
     * OUTPUT: không trả giá trị.
     * SIDE EFFECT: cập nhật lifecycle và dừng pipeline nếu import đã bị hủy.
     * EXCEPTION/TRANSACTION: ném AiImportException(CANCELLED); không mở transaction.
     * =====================================================================
     */
    private function progress(AiImport $import, string $step, int $progress): void
    {
        if ($import->exists) {
            $import->refresh();
            if ($import->status === 'expired' || $import->expires_at?->isPast()) {
                throw new AiImportException('Run đã hết hạn; hãy tạo tác vụ mới.', 'EXPIRED');
            }
            if (in_array($import->status, ['ready', 'applied', 'failed'], true)) {
                throw new AiImportException('Run không còn ở trạng thái đang xử lý.', 'RUN_NOT_ACTIVE');
            }
            $import->advance($step, $progress);
            if ($import->isCancelled()) {
                throw new AiImportException('Import đã bị hủy.', 'CANCELLED');
            }
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trích title an toàn từ HTML nguồn.
     * =====================================================================
     * INPUT: HTML nguồn không tin cậy.
     * OUTPUT: title đã decode entity hoặc chuỗi rỗng.
     * SIDE EFFECT: chỉ xử lý trong memory.
     * EXCEPTION/TRANSACTION: regex không ném lỗi nghiệp vụ; không transaction.
     * =====================================================================
     */
    private function title(string $html): string
    {
        preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match);

        return isset($match[1]) ? trim(html_entity_decode(strip_tags($match[1]))) : '';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trích metadata meta/property từ HTML nguồn.
     * =====================================================================
     * INPUT: HTML nguồn và tên meta/property cần đọc.
     * OUTPUT: giá trị metadata đã decode hoặc chuỗi rỗng.
     * SIDE EFFECT: chỉ xử lý trong memory.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    private function meta(string $html, string $name): string
    {
        $pattern = $name === 'canonical' ? '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)/i' : '/<meta[^>]+(?:property|name)=["\']'.preg_quote($name, '/').'["\'][^>]+content=["\']([^"\']*)/i';
        preg_match($pattern, $html, $match);

        return isset($match[1]) ? html_entity_decode(trim($match[1])) : '';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc article HTML tối giản từ source đã fetch.
     * =====================================================================
     * INPUT: HTML nguồn đã fetch.
     * OUTPUT: article HTML bỏ script/style/nav/ads/tracking.
     * SIDE EFFECT: chỉ tạo DOM trong memory.
     * EXCEPTION/TRANSACTION: lỗi parse trả content rỗng; không mở transaction.
     * =====================================================================
     */
    private function extract(string $html, string $sourceUrl = ''): string
    {
        return (new ArticleSourceExtractor)->extract($html, $sourceUrl);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Sanitize HTML theo tag/attribute allowlist.
     * =====================================================================
     * INPUT: HTML từ nguồn hoặc provider.
     * OUTPUT: HTML an toàn.
     * SIDE EFFECT: không ghi database.
     * EXCEPTION/TRANSACTION: DOM parse lỗi được xử lý an toàn; không transaction.
     * =====================================================================
     */
    private function sanitize(string $html): string
    {
        return (new AiContentSanitizer)->sanitize($html);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác thực quyền và asset ảnh trong nội dung của run
     * =====================================================================
     *
     * INPUT:
     * - HTML chứa refs ảnh và AiImport có actor; run chưa persist hoặc HTML không có img được bỏ qua.
     *
     * OUTPUT:
     * - void: tham chiếu ảnh hợp lệ theo MediaLibrary/policy.
     *
     * SIDE EFFECT:
     * - Đọc actor/media và quyền sử dụng; không tải URL hoặc tự tạo asset.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; thiếu actor ném AI_ACTOR_NOT_FOUND, validation/authorization ảnh đổi thành AI_MEDIA_REFERENCE an toàn.
     *
     * =====================================================================
     */
    private function validateMediaReferences(string $html, AiImport $import): void
    {
        if (! $import->exists || ! preg_match('/<img\b/i', $html)) {
            return;
        }
        $actor = User::query()->find($import->created_by);
        if (! $actor) {
            throw new AiImportException('Người khởi tạo tác vụ không còn tồn tại để kiểm quyền ảnh.', 'AI_ACTOR_NOT_FOUND');
        }
        try {
            app(ContentMediaReferenceService::class)->validate($html, $actor);
        } catch (ValidationException|AuthorizationException) {
            throw new AiImportException('Ảnh trong nội dung không còn hợp lệ hoặc actor không có quyền sử dụng. Hãy kiểm tra MediaLibrary.', 'AI_MEDIA_REFERENCE');
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa danh sách ID taxonomy từ draft.
     * =====================================================================
     * INPUT: danh sách ID do người dùng chọn và class taxonomy allowlist.
     * OUTPUT: ID integer tồn tại, distinct và giữ thứ tự.
     * SIDE EFFECT: truy vấn read-only model taxonomy.
     * EXCEPTION/TRANSACTION: query exception truyền lên caller; không transaction.
     * =====================================================================
     */
    private function existingIds(mixed $ids, string $model): array
    {
        $ids = collect(is_array($ids) ? $ids : [])->map(fn ($id) => is_numeric($id) ? (int) $id : null)->filter(fn ($id) => $id > 0)->unique()->values();

        return $ids->isEmpty() ? [] : $model::query()->whereKey($ids->all())->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chọn provider từ registry bằng input đã được allowlist.
     * =====================================================================
     * INPUT: input_json provider/model.
     * OUTPUT: provider contract; fallback về provider mặc định khi test legacy.
     * SIDE EFFECT: không gọi provider trong bước resolve.
     * EXCEPTION/TRANSACTION: AiImportException nếu key provider bị giả mạo hoặc adapter lỗi; không mở transaction.
     * =====================================================================
     *
     * @param  array<string, mixed>  $input
     */
    private function providerFor(array $input): AiProviderContract
    {
        if (! $this->providers || (empty($input['provider']) && empty($input['ai_connection']))) {
            return $this->provider;
        }

        try {
            $provider = ! empty($input['ai_connection'])
                ? $this->providers->resolveForRun((array) $input['ai_connection'])
                : $this->providers->resolve((string) $input['provider']);
            if (method_exists($provider, 'withModel')) {
                $provider = $provider->withModel(isset($input['model']) ? (string) $input['model'] : null);
            }

            return $provider;
        } catch (\Throwable $exception) {
            throw new AiImportException('Provider AI không được phép sử dụng.', 'AI_PROVIDER_NOT_ALLOWED');
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tải, chuyển đổi và lưu thumbnail từ source image.
     * =====================================================================
     * INPUT: fetcher, source image URL, title/alt và actor admin.
     * OUTPUT: MediaAsset public đã upload hoặc null nếu ảnh không hợp lệ.
     * SIDE EFFECT: download ảnh, tạo file tạm và gọi UploadMediaAssetAction.
     * EXCEPTION/TRANSACTION: lỗi fetch/validation được ghi nhận rồi trả null;
     *   action media tự quản lý transaction/security boundary.
     * =====================================================================
     */
    private function createThumbnail(ArticleSourceFetcher $fetcher, string $url, string $title, int $actorId, string $altText): ?MediaAsset
    {
        $path = null;
        $webpPath = null;
        try {
            $binary = $fetcher->downloadImage($url);
            $source = @imagecreatefromstring($binary);
            if (! $source) {
                return null;
            }
            $width = imagesx($source);
            $height = imagesy($source);
            $scale = min(1, 1600 / max($width, $height));
            $canvas = imagecreatetruecolor(max(1, (int) ($width * $scale)), max(1, (int) ($height * $scale)));
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), $width, $height);
            imagedestroy($source);
            $path = tempnam(sys_get_temp_dir(), 'ai-thumb-');
            if (! $path) {
                imagedestroy($canvas);

                return null;
            }
            $webpPath = $path.'.webp';
            if (! imagewebp($canvas, $webpPath, 85)) {
                imagedestroy($canvas);

                return null;
            }
            imagedestroy($canvas);
            @unlink($path);
            $file = new UploadedFile($webpPath, 'ai-thumbnail.webp', 'image/webp', UPLOAD_ERR_OK, true);

            return $this->uploader?->handle($file, MediaAssetKind::Image, Str::limit($title, 255), $actorId, MediaAssetVisibility::Public, $altText);
        } catch (\Throwable) {
            return null;
        } finally {
            if (isset($path) && is_string($path) && is_file($path)) {
                @unlink($path);
            }
            if (is_string($webpPath) && is_file($webpPath)) {
                @unlink($webpPath);
            }
        }
    }
}
