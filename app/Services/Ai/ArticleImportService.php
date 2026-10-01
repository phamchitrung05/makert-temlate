<?php

namespace App\Services\Ai;

use App\Actions\Media\UploadMediaAssetAction;
use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Exceptions\AiImportException;
use App\Models\AiImport;
use App\Models\Category;
use App\Models\Tag;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Registries\PromptRegistry;
use App\Services\Ai\Registries\ProviderRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

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
 * - extract(), sanitize(), inlineSource(), mergeRequestedFields(), existingIds(),
 *   createThumbnail(), providerFor().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : AiImport cùng URL/options, provider contract và uploader media tùy chọn.
 * - OUTPUT: source metadata và draft canonical chưa publish.
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
        $this->progress($import, 'fetching', 15);
        $source = $sourceType === 'text'
            ? $this->inlineSource((string) $import->source_text, $import)
            : $fetcher->fetch($url);
        $this->progress($import, 'extracting', 35);

        $html = $source['html'];
        $title = $this->meta($html, 'og:title') ?: $this->title($html) ?: 'Bài viết mới';
        $description = $this->meta($html, 'description');
        $content = $this->extract($html);
        if ($content === '') {
            throw new AiImportException('Không tìm thấy nội dung bài viết trong URL.', 'SOURCE_EMPTY');
        }

        $provider = $this->providerFor($input);
        $prompt = (new PromptRegistry)->select(
            isset($input['prompt_key']) ? (string) $input['prompt_key'] : null,
            'post',
            'create',
            ['source_type' => $sourceType, 'language' => (string) ($input['language'] ?? 'vi')],
        );
        $promptKey = (string) $prompt['key'];
        $this->progress($import, 'rewriting', 55);
        $draft = $this->fallbackDraft($url, $title, $description, $content, $html);
        if ($provider->configured()) {
            $draft = array_replace($draft, $provider->generate($title, $content, (string) ($input['language'] ?? 'vi'), (string) ($input['rewrite_style'] ?? 'informative'), $promptKey, (string) ($input['instructions'] ?? '')));
            $draft['content_html'] = $this->sanitize((string) ($draft['content_html'] ?? $draft['content'] ?? ''));
            $draft['content'] = $draft['content_html'];
        }
        $draft['suggested_category_ids'] = $this->existingIds($draft['suggested_category_ids'] ?? $draft['category_ids'] ?? [], Category::class);
        $draft['suggested_tag_ids'] = $this->existingIds($draft['suggested_tag_ids'] ?? $draft['tag_ids'] ?? [], Tag::class);
        $draft['category_ids'] = $draft['suggested_category_ids'];
        $draft['tag_ids'] = $draft['suggested_tag_ids'];

        $requestedFields = array_values(array_unique(array_map('strval', (array) ($input['fields'] ?? []))));
        if ($requestedFields !== [] && $import->parent_id) {
            $parentDraft = (array) data_get(AiImport::query()->find($import->parent_id)?->result_json, 'draft', []);
            $draft = $this->mergeRequestedFields($parentDraft, $draft, $requestedFields);
        }

        $this->progress($import, 'seo', 72);
        $thumbnail = $draft['thumbnail'];
        if (! empty($draft['thumbnail_alt_text'])) {
            $thumbnail['alt_text'] = (string) $draft['thumbnail_alt_text'];
        }
        $thumbnailRequested = $requestedFields === [] || in_array('thumbnail', $requestedFields, true);
        if ($thumbnailRequested && ($input['generate_thumbnail'] ?? true) && ($thumbnail['source_url'] ?? null) && $this->uploader && $import->exists) {
            $this->progress($import, 'thumbnail', 86);
            $asset = $this->createThumbnail($fetcher, (string) $thumbnail['source_url'], (string) $title, (int) $import->created_by, (string) ($thumbnail['alt_text'] ?? $title));
            if ($asset) {
                $thumbnail['media_asset_id'] = $asset->getKey();
            }
        }
        $draft['thumbnail'] = $thumbnail;
        $this->progress($import, 'ready', 100);

        if ($import->exists) {
            $import->forceFill([
                'normalized_url' => $source['url'],
                'source_meta_json' => ['content_type' => $source['content_type'], 'title' => $title],
            ])->save();
        }

        return [
            'source' => ['url' => $source['url'], 'title' => $title, 'canonical_url' => $this->meta($html, 'canonical') ?: $source['url']],
            'draft' => $draft,
            'provider' => $provider->providerName(),
            'model' => $provider->modelName(),
            'prompt_key' => $promptKey,
            'prompt_version' => (string) $prompt['version'],
            'schema_version' => (string) $prompt['schema'],
            'requested_fields' => $requestedFields,
        ];
    }

    /**
     * INPUT: URL, metadata title/description, HTML đã extract.
     * OUTPUT: draft deterministic gồm content/SEO/taxonomy/thumbnail source.
     * SIDE EFFECT: không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: không mở transaction; không ném lỗi nghiệp vụ.
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
            'suggested_category_ids' => [], 'suggested_tag_ids' => [], 'thumbnail_prompt' => $title,
            'thumbnail' => ['media_asset_id' => null, 'source_url' => $this->meta($html, 'og:image'), 'alt_text' => $title],
        ];
    }

    /**
     * Dựng source HTML an toàn từ text inline để dùng chung extractor/sanitizer.
     *
     * Input: text do admin gửi và AiImport hiện tại.
     * Output: source map tương thích ArticleSourceFetcher::fetch(), không gọi HTTP.
     * Side effect: không ghi database; chỉ escape nội dung trong memory.
     * Exception/transaction: ném AiImportException nếu text rỗng; không transaction.
     *
     * @return array{url:string,html:string,content_type:string}
     */
    private function inlineSource(string $text, AiImport $import): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new AiImportException('Nội dung nguồn không được để trống.', 'SOURCE_EMPTY');
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
     * Giữ field không được chọn từ parent khi regenerate từng phần.
     *
     * Input: draft parent, draft mới và field selection allowlist.
     * Output: draft mới chỉ thay nhóm field được yêu cầu; không mutate input.
     * Side effect: không gọi database/provider; thumbnail được xử lý ở caller.
     */
    private function mergeRequestedFields(array $parent, array $fresh, array $fields): array
    {
        $merged = array_replace($fresh, $parent);
        foreach ($fields as $field) {
            match ($field) {
                'content' => $merged = array_replace($merged, [
                    'content_html' => $fresh['content_html'] ?? $fresh['content'] ?? $parent['content_html'] ?? '',
                    'content' => $fresh['content'] ?? $fresh['content_html'] ?? $parent['content'] ?? '',
                ]),
                'seo' => $merged = array_replace($merged, array_intersect_key($fresh, array_flip([
                    'focus_keyword', 'seo_title', 'seo_description', 'canonical_url',
                    'robots_index', 'robots_follow', 'og_title', 'og_description',
                ]))),
                'taxonomy' => $merged = array_replace($merged, array_intersect_key($fresh, array_flip([
                    'suggested_category_ids', 'suggested_tag_ids', 'category_ids', 'tag_ids',
                ]))),
                'thumbnail' => $merged['thumbnail'] = $fresh['thumbnail'] ?? ($parent['thumbnail'] ?? []),
                default => $merged[$field] = $fresh[$field] ?? ($parent[$field] ?? null),
            };
        }

        return $merged;
    }

    /**
     * INPUT: AiImport và step/progress mới.
     * OUTPUT: không trả giá trị.
     * SIDE EFFECT: cập nhật lifecycle và dừng pipeline nếu import đã bị hủy.
     * EXCEPTION/TRANSACTION: ném AiImportException(CANCELLED); không mở transaction.
     */
    private function progress(AiImport $import, string $step, int $progress): void
    {
        if ($import->exists) {
            $import->advance($step, $progress);
            if ($import->isCancelled()) {
                throw new AiImportException('Import đã bị hủy.', 'CANCELLED');
            }
        }
    }

    /**
     * INPUT: HTML nguồn không tin cậy.
     * OUTPUT: title đã decode entity hoặc chuỗi rỗng.
     * SIDE EFFECT: không có.
     * EXCEPTION/TRANSACTION: không mở transaction; regex không ném lỗi nghiệp vụ.
     */
    private function title(string $html): string
    {
        preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match);

        return isset($match[1]) ? trim(html_entity_decode(strip_tags($match[1]))) : '';
    }

    /**
     * INPUT: HTML nguồn và tên meta/property cần đọc.
     * OUTPUT: giá trị metadata đã decode hoặc chuỗi rỗng.
     * SIDE EFFECT: không có.
     * EXCEPTION/TRANSACTION: không mở transaction.
     */
    private function meta(string $html, string $name): string
    {
        $pattern = $name === 'canonical' ? '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)/i' : '/<meta[^>]+(?:property|name)=["\']'.preg_quote($name, '/').'["\'][^>]+content=["\']([^"\']*)/i';
        preg_match($pattern, $html, $match);

        return isset($match[1]) ? html_entity_decode(trim($match[1])) : '';
    }

    /**
     * INPUT: HTML nguồn đã fetch.
     * OUTPUT: article HTML tối giản, bỏ script/style/nav/ads/tracking.
     * SIDE EFFECT: chỉ tạo DOM trong memory.
     * EXCEPTION/TRANSACTION: không mở transaction; lỗi parse trả content rỗng.
     */
    private function extract(string $html): string
    {
        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        foreach (iterator_to_array($document->getElementsByTagName('*')) as $node) {
            if (in_array(strtolower($node->nodeName), ['script', 'style', 'noscript', 'nav', 'footer', 'header', 'form', 'iframe'], true)) {
                $node->parentNode?->removeChild($node);

                continue;
            }
            $tag = strtolower($node->nodeName);
            $safeAttributes = match ($tag) {
                'a' => ['href', 'target', 'rel', 'title'],
                'td', 'th' => ['colspan', 'rowspan', 'scope'],
                default => [],
            };
            for ($i = $node->attributes->length - 1; $i >= 0; $i--) {
                $attribute = $node->attributes->item($i);
                if (! in_array(strtolower($attribute->name), $safeAttributes, true) || ($tag === 'a' && preg_match('/^(javascript|data|vbscript):/i', trim($attribute->value)))) {
                    $node->removeAttributeNode($attribute);
                }
            }
        }
        $container = $document->getElementsByTagName('article')->item(0) ?: $document->getElementsByTagName('body')->item(0);
        if (! $container) {
            return '';
        }
        $output = '';
        foreach ($container->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim(preg_replace('/\s{2,}/', ' ', $this->sanitize($output)) ?? $output);
    }

    /**
     * INPUT: HTML từ nguồn hoặc provider.
     * OUTPUT: HTML an toàn giữ tag/attribute được allowlist.
     * SIDE EFFECT: không ghi database.
     * EXCEPTION/TRANSACTION: không mở transaction; DOM parse lỗi được xử lý an toàn.
     */
    private function sanitize(string $html): string
    {
        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        // Keep semantic article markup, code examples and simple data tables;
        // attributes remain allowlisted below so copied HTML cannot execute JS.
        $allowed = [
            'html', 'body', 'div', 'p', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li',
            'blockquote', 'strong', 'em', 'a', 'br', 'hr', 'pre', 'code', 'span',
            'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
        ];
        foreach (iterator_to_array($document->getElementsByTagName('*')) as $node) {
            if (! in_array(strtolower($node->nodeName), $allowed, true)) {
                $node->parentNode?->removeChild($node);

                continue;
            }
            $tag = strtolower($node->nodeName);
            $safeAttributes = match ($tag) {
                'a' => ['href', 'target', 'rel', 'title'],
                'td', 'th' => ['colspan', 'rowspan', 'scope'],
                default => [],
            };
            for ($i = $node->attributes->length - 1; $i >= 0; $i--) {
                $attribute = $node->attributes->item($i);
                if (! in_array(strtolower($attribute->name), $safeAttributes, true) || ($tag === 'a' && preg_match('/^(javascript|data|vbscript):/i', trim($attribute->value)))) {
                    $node->removeAttributeNode($attribute);
                }
            }
        }
        $root = $document->getElementsByTagName('div')->item(0);
        $output = '';
        if ($root) {
            foreach ($root->childNodes as $child) {
                $output .= $document->saveHTML($child);
            }
        }

        return trim($output);
    }

    /**
     * INPUT: danh sách ID provider gợi ý và class taxonomy allowlist.
     * OUTPUT: ID integer tồn tại, distinct và giữ thứ tự.
     * SIDE EFFECT: truy vấn read-only model taxonomy.
     * EXCEPTION/TRANSACTION: không mở transaction; query exception truyền lên caller.
     */
    private function existingIds(mixed $ids, string $model): array
    {
        $ids = collect(is_array($ids) ? $ids : [])->map(fn ($id) => is_numeric($id) ? (int) $id : null)->filter(fn ($id) => $id > 0)->unique()->values();

        return $ids->isEmpty() ? [] : $model::query()->whereKey($ids->all())->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Chọn provider từ registry bằng input đã được controller allowlist.
     *
     * Input: input_json provider/model.
     * Output: provider contract; fallback về provider mặc định khi test legacy.
     * Exception: AiImportException nếu key provider bị giả mạo hoặc adapter lỗi.
     *
     * @param array<string, mixed> $input
     */
    private function providerFor(array $input): AiProviderContract
    {
        if (! $this->providers || empty($input['provider'])) {
            return $this->provider;
        }

        try {
            $provider = $this->providers->resolve((string) $input['provider']);
            if (method_exists($provider, 'withModel')) {
                $provider->withModel(isset($input['model']) ? (string) $input['model'] : null);
            }

            return $provider;
        } catch (\Throwable $exception) {
            throw new AiImportException('Provider AI không được phép sử dụng.', 'AI_PROVIDER_NOT_ALLOWED');
        }
    }

    /**
     * INPUT: fetcher, source image URL, title/alt và actor admin.
     * OUTPUT: MediaAsset public đã upload hoặc null nếu ảnh không hợp lệ.
     * SIDE EFFECT: download ảnh, tạo file tạm và gọi UploadMediaAssetAction.
     * EXCEPTION/TRANSACTION: lỗi fetch/validation được ghi nhận rồi trả null;
     *   action media tự quản lý transaction/security boundary.
     */
    private function createThumbnail(ArticleSourceFetcher $fetcher, string $url, string $title, int $actorId, string $altText): ?\App\Models\MediaAsset
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
