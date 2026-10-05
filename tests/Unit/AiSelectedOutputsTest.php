<?php

namespace Tests\Unit;

use App\Exceptions\AiImportException;
use App\Models\AiImport;
use App\Services\Ai\Content\ArticleImportService;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Data\AiTaskRequest;
use App\Services\Ai\Data\AiTaskResponse;
use App\Services\Ai\Providers\Adapters\AbstractStructuredAiProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\ArticlePipelineFixture;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Regression field được chọn, merge parent snapshot và adapter giả lập.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp().
 * - tearDown().
 * - test_excerpt_selection_filters_unselected_fields_even_when_their_types_are_invalid().
 * - test_partial_seo_output_does_not_require_title_or_content_and_selection_overrides_legacy_flag().
 * - test_selected_field_still_rejects_invalid_type().
 * - test_create_preserves_manual_title_source_content_and_source_thumbnail().
 * - test_content_selection_sanitizes_generated_html_and_preserves_other_source_fields().
 * - test_content_selection_accepts_legacy_content_alias_and_prefers_canonical_field().
 * - test_content_selection_rejects_invalid_type_in_legacy_content_alias().
 * - test_regenerate_only_merges_selected_group_into_parent().
 * - test_empty_regenerate_selection_preserves_legacy_complete_generation().
 * - test_regeneration_merges_into_queued_parent_snapshot_even_if_live_parent_was_edited().
 * - test_three_step_regeneration_preserves_approved_parent_inline_images().
 * - inlineImport().
 * - __construct().
 * - configured().
 * - providerName().
 * - modelName().
 * - requestPayload().
 * - __construct().
 * - configured().
 * - execute().
 * - providerName().
 * - modelName().
 * - generate().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : fixtures source/JSON và cấu hình test đã cô lập.
 * - OUTPUT: assertions contract; không gọi AI thật hoặc ghi database development.
 * =====================================================================
 */
final class AiSelectedOutputsTest extends TestCase
{
    /**
     * =====================================================================
     * INPUT: Không có; PHPUnit gọi trước mỗi ca.
     * OUTPUT: Khởi tạo dependency/database test riêng; không thay dữ liệu development.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.selected_outputs_test', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        config()->set('database.default', 'selected_outputs_test');
        Schema::create('ai_imports', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->text('result_json')->nullable();
            $table->timestamps();
        });
        Http::preventStrayRequests();
    }

    /**
     * =====================================================================
     * INPUT: Không có; PHPUnit gọi sau mỗi ca.
     * OUTPUT: Dọn dependency/connection test, không thay dữ liệu development.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    protected function tearDown(): void
    {
        DB::purge('selected_outputs_test');
        parent::tearDown();
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_excerpt_selection_filters_unselected_fields_even_when_their_types_are_invalid(): void
    {
        $provider = (new SelectedOutputsStructuredProvider([
            'excerpt' => 'Mô tả do AI tạo', 'title' => ['unselected'], 'seo_title' => 'Không được dùng',
            'thumbnail' => ['media_asset_id' => 999, 'source_url' => 'https://fake.test/image.png'],
        ]))->withRunSettings(['min_word_count' => 500])->withOutputFields(['excerpt']);

        $this->assertSame(['excerpt' => 'Mô tả do AI tạo'], $provider->generate('Nguồn', '<p>Nội dung gốc</p>'));
        $this->assertStringContainsString('Allowed fields: excerpt.', $provider->captured->input['instructions']);
        $this->assertStringNotContainsString('at least 500 words', $provider->captured->input['instructions']);
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_partial_seo_output_does_not_require_title_or_content_and_selection_overrides_legacy_flag(): void
    {
        $provider = (new SelectedOutputsStructuredProvider(['seo_title' => 'Tiêu đề SEO', 'robots_index' => false]))
            ->withRunSettings(['generate_seo' => false])->withOutputFields(['seo']);

        $this->assertSame(['seo_title' => 'Tiêu đề SEO', 'robots_index' => false], $provider->generate('Nguồn', '<p>Nội dung gốc</p>'));
        $this->assertStringNotContainsString('Allowed fields: title', $provider->captured->input['instructions']);
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_selected_field_still_rejects_invalid_type(): void
    {
        $this->expectException(AiImportException::class);
        $this->expectExceptionMessage('sai kiểu dữ liệu cho excerpt');
        (new SelectedOutputsStructuredProvider(['excerpt' => ['invalid']]))
            ->withOutputFields(['excerpt'])->generate('Nguồn', '<p>Nội dung gốc</p>');
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_create_preserves_manual_title_source_content_and_source_thumbnail(): void
    {
        Http::fake(['https://example.test/article' => Http::response(
            '<html><head><title>Tiêu đề nguồn</title><meta property="og:image" content="https://example.test/source.png"></head><body><article><p>Nội dung nguồn.</p></article></body></html>',
        )]);
        $provider = new SelectedOutputsRawProvider([
            'title' => 'Không được thay', 'content_html' => '<p>Không được thay</p>',
            'excerpt' => 'Mô tả mới', 'seo_title' => 'Không được thay SEO',
            'thumbnail' => ['media_asset_id' => 999, 'source_url' => 'https://fake.test/image.png'],
        ]);
        $result = (new ArticleImportService($provider))->run(new AiImport([
            'source_url' => 'https://example.test/article',
            'input_json' => ['title' => 'Tiêu đề nhập tay', 'fields' => ['excerpt', 'thumbnail'], 'generate_seo' => false],
        ]));

        $this->assertSame('Tiêu đề nhập tay', $result['draft']['title']);
        $this->assertSame('Mô tả mới', $result['draft']['excerpt']);
        $this->assertStringContainsString('Nội dung nguồn.', $result['draft']['content_html']);
        $this->assertSame('Tiêu đề nhập tay', $result['draft']['seo_title']);
        $this->assertSame('https://example.test/source.png', $result['draft']['thumbnail']['source_url']);
        $this->assertNull($result['draft']['thumbnail']['media_asset_id']);
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_content_selection_sanitizes_generated_html_and_preserves_other_source_fields(): void
    {
        $provider = new SelectedOutputsStructuredProvider([
            'content_html' => '<p onclick="bad()">Nội dung mới.</p><script>bad()</script>', 'title' => 'Không được thay',
        ]);
        $result = (new ArticleImportService($provider))->run($this->inlineImport(['fields' => ['content']]));

        $this->assertSame('Tiêu đề nguồn', $result['draft']['title']);
        $this->assertStringContainsString('Nội dung mới.', $result['draft']['content_html']);
        $this->assertStringNotContainsString('onclick', $result['draft']['content_html']);
        $this->assertStringNotContainsString('<script', $result['draft']['content_html']);
        $this->assertSame($result['draft']['content_html'], $result['draft']['content']);
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_content_selection_accepts_legacy_content_alias_and_prefers_canonical_field(): void
    {
        $provider = new SelectedOutputsStructuredProvider(['content' => '<p>Nội dung từ alias.</p>']);
        $result = (new ArticleImportService($provider))->run($this->inlineImport(['fields' => ['content']]));

        $this->assertSame('<p>Nội dung từ alias.</p>', $result['draft']['content_html']);
        $this->assertSame($result['draft']['content_html'], $result['draft']['content']);
        $this->assertSame('Tiêu đề nguồn', $result['draft']['title']);

        $canonical = (new SelectedOutputsStructuredProvider([
            'content_html' => '<p>Canonical.</p>', 'content' => '<p>Alias.</p>',
        ]))->withOutputFields(['content'])->generate('Nguồn', '<p>Nội dung gốc</p>');
        $this->assertSame(['content_html' => '<p>Canonical.</p>', 'content' => '<p>Canonical.</p>'], $canonical);
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_content_selection_rejects_invalid_type_in_legacy_content_alias(): void
    {
        $this->expectException(AiImportException::class);
        $this->expectExceptionMessage('sai kiểu dữ liệu cho content_html');
        (new SelectedOutputsStructuredProvider(['content' => ['invalid']]))
            ->withOutputFields(['content'])->generate('Nguồn', '<p>Nội dung gốc</p>');
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_regenerate_only_merges_selected_group_into_parent(): void
    {
        $parentDraft = [
            'title' => 'Tiêu đề đã sửa', 'excerpt' => 'Mô tả đã sửa',
            'content_html' => '<p>Nội dung đã sửa.</p>', 'content' => '<p>Nội dung đã sửa.</p>',
            'seo_title' => 'SEO cũ', 'thumbnail' => ['media_asset_id' => 42, 'source_url' => 'https://example.test/old.png', 'alt_text' => 'Ảnh cũ'],
        ];
        $parent = AiImport::query()->create(['result_json' => ['draft' => $parentDraft]]);
        $import = $this->inlineImport(['fields' => ['seo']]);
        $import->parent_id = $parent->getKey();
        $result = (new ArticleImportService(new SelectedOutputsRawProvider([
            'seo_title' => 'SEO mới', 'title' => 'Không được thay', 'excerpt' => 'Không được thay',
        ])))->run($import);

        foreach (['title', 'excerpt', 'content_html', 'content', 'thumbnail'] as $field) {
            $this->assertSame($parentDraft[$field], $result['draft'][$field]);
        }
        $this->assertSame('SEO mới', $result['draft']['seo_title']);
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_empty_regenerate_selection_preserves_legacy_complete_generation(): void
    {
        $parent = AiImport::query()->create(['result_json' => ['draft' => ['title' => 'Tiêu đề cũ']]]);
        $provider = new SelectedOutputsStructuredProvider(['title' => 'Tiêu đề mới', 'content_html' => '<p>Nội dung mới.</p>']);
        $import = $this->inlineImport(['fields' => [], 'requested_outputs' => ['excerpt']]);
        $import->parent_id = $parent->getKey();
        $result = (new ArticleImportService($provider))->run($import);

        $this->assertSame('Tiêu đề mới', $result['draft']['title']);
        $this->assertStringContainsString('Nội dung mới.', $result['draft']['content_html']);
        $this->assertSame([], $result['requested_fields']);
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_regeneration_merges_into_queued_parent_snapshot_even_if_live_parent_was_edited(): void
    {
        $queuedDraft = ['title' => 'Title lúc enqueue', 'content_html' => '<p>Content lúc enqueue.</p>', 'content' => '<p>Content lúc enqueue.</p>', 'excerpt' => 'Excerpt lúc enqueue', 'seo_title' => 'SEO cũ'];
        $parent = AiImport::query()->create(['result_json' => ['draft' => ['title' => 'Live title sau enqueue', 'content_html' => '<p>Live content sau enqueue.</p>']]]);
        $import = $this->inlineImport(['fields' => ['seo'], 'parent_draft_snapshot' => $queuedDraft]);
        $import->parent_id = $parent->getKey();
        $result = (new ArticleImportService(new SelectedOutputsRawProvider(['seo_title' => 'SEO mới'])))->run($import);

        $this->assertSame($queuedDraft['title'], $result['draft']['title']);
        $this->assertSame($queuedDraft['content_html'], $result['draft']['content_html']);
        $this->assertSame($queuedDraft['excerpt'], $result['draft']['excerpt']);
        $this->assertSame('SEO mới', $result['draft']['seo_title']);
        $this->assertSame('Live title sau enqueue', $parent->fresh()->result_json['draft']['title']);
    }

    /**
     * =====================================================================
     * INPUT: parent snapshot có ảnh MediaLibrary và baseline AI trả content mới.
     * OUTPUT: content giữ asset ref cùng title cũ; không cần model biết URL ảnh.
     * SIDE EFFECT: database SQLite/HTTP provider fixture đã cô lập.
     * =====================================================================
     */
    public function test_three_step_regeneration_preserves_approved_parent_inline_images(): void
    {
        $draft = ['title' => 'Parent', 'content_html' => '<p>Parent content.</p><img src="/storage/media/7.webp" data-media-asset-id="7" alt="Ảnh">'];
        $parent = AiImport::query()->create(['result_json' => ['draft' => $draft]]);
        $import = $this->inlineImport(['fields' => ['content'], 'parent_draft_snapshot' => $draft]);
        $import->parent_id = $parent->getKey();
        $result = (new ArticleImportService(new SelectedOutputsRawProvider(['content_html' => '<p>Nội dung mới.</p>'])))->run($import);

        $this->assertStringContainsString('Nội dung mới.', $result['draft']['content_html']);
        $this->assertStringContainsString('data-media-asset-id="7"', $result['draft']['content_html']);
        $this->assertSame('Parent', $result['draft']['title']);
    }

    /**
     * =====================================================================
     * INPUT: Tham số fixture hoặc dữ liệu test được caller chuẩn bị.
     * OUTPUT: Kết quả/exception giả lập để kiểm contract; HTTP/queue dùng fake trong ca test.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    private function inlineImport(array $input): AiImport
    {
        return new AiImport([
            'source_text' => "Tiêu đề nguồn\n\nNội dung nguồn.",
            'input_json' => ['source_type' => 'text'] + $input,
        ]);
    }
}

final class SelectedOutputsStructuredProvider extends AbstractStructuredAiProvider
{
    public object $captured;

    /**
     * =====================================================================
     * INPUT: Dữ liệu responses/context để khởi tạo helper fixture.
     * OUTPUT: Helper test sẵn sàng, không gọi HTTP hoặc ghi database.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function __construct(private readonly array $response)
    {
        $this->captured = new \stdClass;
    }

    /**
     * =====================================================================
     * INPUT: Không có; provider fixture chỉ dùng trong test.
     * OUTPUT: Trạng thái hoặc identity provider giả lập; không gọi HTTP hay ghi database.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function configured(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * INPUT: Không có; provider fixture chỉ dùng trong test.
     * OUTPUT: Trạng thái hoặc identity provider giả lập; không gọi HTTP hay ghi database.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function providerName(): string
    {
        return 'fixture';
    }

    /**
     * =====================================================================
     * INPUT: Không có; provider fixture chỉ dùng trong test.
     * OUTPUT: Trạng thái hoặc identity provider giả lập; không gọi HTTP hay ghi database.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function modelName(): string
    {
        return 'fixture-model';
    }

    /**
     * =====================================================================
     * INPUT: Tham số fixture hoặc dữ liệu test được caller chuẩn bị.
     * OUTPUT: Kết quả/exception giả lập để kiểm contract; HTTP/queue dùng fake trong ca test.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    protected function requestPayload(array $input): mixed
    {
        $this->captured->input = $input;

        return isset($input['task'])
            ? ArticlePipelineFixture::output($input['task'], $input['task_input'], $this->response)
            : $this->response;
    }
}

/** Deliberately ignores requested groups to verify the service boundary too. */
final class SelectedOutputsRawProvider implements AiProviderContract
{
    /**
     * =====================================================================
     * INPUT: Dữ liệu responses/context để khởi tạo helper fixture.
     * OUTPUT: Helper test sẵn sàng, không gọi HTTP hoặc ghi database.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function __construct(private readonly array $response) {}

    /**
     * =====================================================================
     * INPUT: Không có; provider fixture chỉ dùng trong test.
     * OUTPUT: Trạng thái hoặc identity provider giả lập; không gọi HTTP hay ghi database.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function configured(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * INPUT: Tham số fixture hoặc dữ liệu test được caller chuẩn bị.
     * OUTPUT: Kết quả/exception giả lập để kiểm contract; HTTP/queue dùng fake trong ca test.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function execute(AiTaskRequest $request): AiTaskResponse
    {
        return new AiTaskResponse(ArticlePipelineFixture::output($request->task, $request->input, $this->response, $request->schema));
    }

    /**
     * =====================================================================
     * INPUT: Không có; provider fixture chỉ dùng trong test.
     * OUTPUT: Trạng thái hoặc identity provider giả lập; không gọi HTTP hay ghi database.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function providerName(): string
    {
        return 'fixture';
    }

    /**
     * =====================================================================
     * INPUT: Không có; provider fixture chỉ dùng trong test.
     * OUTPUT: Trạng thái hoặc identity provider giả lập; không gọi HTTP hay ghi database.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function modelName(): string
    {
        return 'fixture-model';
    }

    /**
     * =====================================================================
     * INPUT: Tham số fixture hoặc dữ liệu test được caller chuẩn bị.
     * OUTPUT: Kết quả/exception giả lập để kiểm contract; HTTP/queue dùng fake trong ca test.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function generate(string $title, string $content, string $language = 'vi', string $rewriteStyle = 'informative', string $promptKey = 'post.create.from_url', string $instructions = ''): array
    {
        return $this->response;
    }
}
