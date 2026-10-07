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
 *
 * PHPUnit kiểm allowlist nhóm field và merge đúng snapshot parent đã chụp lúc enqueue.
 * Provider structured/raw giả trong file trả output rộng để kiểm boundary service
 * vẫn lọc field; schema SQLite in-memory riêng, không gọi AI hoặc DB development.
 *
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
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Fixtures, HTTP request và dependency test đã cô lập.
 * - OUTPUT: Assertions cho output, quyền, validation và lifecycle hiện có.
 * - SIDE EFFECT: Tạo/đọc/sửa dữ liệu ở DB test; setup/teardown quản lý schema và connection riêng.
 * - EXCEPTION/TRANSACTION: Lỗi assertion/dependency truyền ra PHPUnit; transaction code nghiệp vụ chạy trong môi trường test.
 * =====================================================================
 */
final class AiSelectedOutputsTest extends TestCase
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị môi trường cô lập trước mỗi ca kiểm thử
     * =====================================================================
     *
     * INPUT:
     * - Không có; PHPUnit gọi trước mỗi ca.
     *
     * OUTPUT:
     * - Khởi tạo dependency/database test riêng; không thay dữ liệu development.
     *
     * SIDE EFFECT:
     * - chỉ xử lý fixtures hoặc database test đã cô lập.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi setup/schema truyền ra PHPUnit; không mở transaction nghiệp vụ bao toàn bộ ca test.
     *
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
            $table->unsignedInteger('generation_no')->default(1);
            $table->text('result_json')->nullable();
            $table->timestamps();
        });
        Http::preventStrayRequests();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn môi trường cô lập sau mỗi ca kiểm thử
     * =====================================================================
     *
     * INPUT:
     * - Không có; PHPUnit gọi sau mỗi ca.
     *
     * OUTPUT:
     * - Dọn dependency/connection test, không thay dữ liệu development.
     *
     * SIDE EFFECT:
     * - chỉ xử lý fixtures hoặc database test đã cô lập.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi teardown truyền ra PHPUnit; không gọi provider hoặc mở transaction nghiệp vụ mới.
     *
     * =====================================================================
     */
    protected function tearDown(): void
    {
        DB::purge('selected_outputs_test');
        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử fixture nguồn/output/cấu hình và dependencies fake của ca này
     * =====================================================================
     *
     * INPUT:
     * - Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     *
     * OUTPUT:
     * - Assertions contract/hành vi mong đợi; không gọi API AI thật.
     *
     * SIDE EFFECT:
     * - chỉ xử lý fixtures hoặc database test đã cô lập.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
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
     * CHỨC NĂNG: Kiểm thử fixture nguồn/output/cấu hình và dependencies fake của ca này
     * =====================================================================
     *
     * INPUT:
     * - Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     *
     * OUTPUT:
     * - Assertions contract/hành vi mong đợi; không gọi API AI thật.
     *
     * SIDE EFFECT:
     * - chỉ xử lý fixtures hoặc database test đã cô lập.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
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
     * CHỨC NĂNG: Kiểm thử fixture nguồn/output/cấu hình và dependencies fake của ca này
     * =====================================================================
     *
     * INPUT:
     * - Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     *
     * OUTPUT:
     * - Assertions contract/hành vi mong đợi; không gọi API AI thật.
     *
     * SIDE EFFECT:
     * - chỉ xử lý fixtures hoặc database test đã cô lập.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
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
     * CHỨC NĂNG: Kiểm thử fixture nguồn/output/cấu hình và dependencies fake của ca này
     * =====================================================================
     *
     * INPUT:
     * - Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     *
     * OUTPUT:
     * - Assertions contract/hành vi mong đợi; không gọi API AI thật.
     *
     * SIDE EFFECT:
     * - chỉ xử lý fixtures hoặc database test đã cô lập.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
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
     * CHỨC NĂNG: Kiểm thử fixture nguồn/output/cấu hình và dependencies fake của ca này
     * =====================================================================
     *
     * INPUT:
     * - Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     *
     * OUTPUT:
     * - Assertions contract/hành vi mong đợi; không gọi API AI thật.
     *
     * SIDE EFFECT:
     * - chỉ xử lý fixtures hoặc database test đã cô lập.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
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
     * CHỨC NĂNG: Kiểm thử fixture nguồn/output/cấu hình và dependencies fake của ca này
     * =====================================================================
     *
     * INPUT:
     * - Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     *
     * OUTPUT:
     * - Assertions contract/hành vi mong đợi; không gọi API AI thật.
     *
     * SIDE EFFECT:
     * - chỉ xử lý fixtures hoặc database test đã cô lập.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
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
     * CHỨC NĂNG: Kiểm thử fixture nguồn/output/cấu hình và dependencies fake của ca này
     * =====================================================================
     *
     * INPUT:
     * - Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     *
     * OUTPUT:
     * - Assertions contract/hành vi mong đợi; không gọi API AI thật.
     *
     * SIDE EFFECT:
     * - chỉ xử lý fixtures hoặc database test đã cô lập.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
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
     * CHỨC NĂNG: Kiểm thử fixture nguồn/output/cấu hình và dependencies fake của ca này
     * =====================================================================
     *
     * INPUT:
     * - Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     *
     * OUTPUT:
     * - Assertions contract/hành vi mong đợi; không gọi API AI thật.
     *
     * SIDE EFFECT:
     * - chỉ xử lý fixtures hoặc database test đã cô lập.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
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
     * CHỨC NĂNG: Kiểm thử fixture nguồn/output/cấu hình và dependencies fake của ca này
     * =====================================================================
     *
     * INPUT:
     * - Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     *
     * OUTPUT:
     * - Assertions contract/hành vi mong đợi; không gọi API AI thật.
     *
     * SIDE EFFECT:
     * - chỉ xử lý fixtures hoặc database test đã cô lập.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
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
     * CHỨC NĂNG: Kiểm thử fixture nguồn/output/cấu hình và dependencies fake của ca này
     * =====================================================================
     *
     * INPUT:
     * - Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     *
     * OUTPUT:
     * - Assertions contract/hành vi mong đợi; không gọi API AI thật.
     *
     * SIDE EFFECT:
     * - chỉ xử lý fixtures hoặc database test đã cô lập.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
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
     * CHỨC NĂNG: Kiểm thử parent snapshot có ảnh MediaLibrary và baseline AI trả content mới
     * =====================================================================
     *
     * INPUT:
     * - parent snapshot có ảnh MediaLibrary và baseline AI trả content mới.
     *
     * OUTPUT:
     * - content giữ asset ref cùng title cũ; không cần model biết URL ảnh.
     *
     * SIDE EFFECT:
     * - database SQLite/HTTP provider fixture đã cô lập.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
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
     * CHỨC NĂNG: Dựng AiImport chỉ trong memory với nguồn inline fixture
     * =====================================================================
     *
     * INPUT:
     * - Tham số fixture hoặc dữ liệu test được caller chuẩn bị.
     *
     * OUTPUT:
     * - Kết quả/exception giả lập để kiểm contract; HTTP/queue dùng fake trong ca test.
     *
     * SIDE EFFECT:
     * - Chỉ dựng array/model trong memory; không persist hoặc gọi HTTP.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; caller thực thi pipeline trên fixture này.
     *
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

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Provider structured giả dành cho kiểm output chọn lọc.
 * =====================================================================
 *
 * Provider structured giả ghi lại input để kiểm allowlist/schema ở boundary service. Fixture trả output cố định hoặc DTO pipeline, không gửi HTTP thật.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct().
 * - configured().
 * - providerName().
 * - modelName().
 * - requestPayload().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Response fixture và input/task do pipeline chuẩn bị.
 * - OUTPUT: Output fixture và captured input dùng assertions.
 * - SIDE EFFECT: Chỉ cập nhật captured trong memory.
 * - EXCEPTION/TRANSACTION: Không mở transaction; lỗi fixture/schema truyền ra ca test.
 * =====================================================================
 */
final class SelectedOutputsStructuredProvider extends AbstractStructuredAiProvider
{
    public object $captured;

    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo provider fixture cho ca kiểm thử
     * =====================================================================
     *
     * INPUT:
     * - Dữ liệu responses/context để khởi tạo helper fixture.
     *
     * OUTPUT:
     * - Helper test sẵn sàng, không gọi HTTP hoặc ghi database.
     *
     * SIDE EFFECT:
     * - Chỉ giữ response/captured state trong memory; không DB/HTTP.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction hoặc gọi AI thật.
     *
     * =====================================================================
     */
    public function __construct(private readonly array $response)
    {
        $this->captured = new \stdClass;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Báo provider fixture sẵn sàng trong kiểm thử
     * =====================================================================
     *
     * INPUT:
     * - Không có; provider chỉ dùng trong test.
     *
     * OUTPUT:
     * - bool true.
     *
     * SIDE EFFECT:
     * - Chỉ trả cấu hình fixture; không DB/HTTP.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction hoặc gọi AI thật.
     *
     * =====================================================================
     */
    public function configured(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả định danh provider fixture dùng đối chiếu provenance
     * =====================================================================
     *
     * INPUT:
     * - Không có.
     *
     * OUTPUT:
     * - Tên provider cố định cho fixture của class.
     *
     * SIDE EFFECT:
     * - Chỉ đọc identity trong memory; không I/O.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction hoặc tra catalog thật.
     *
     * =====================================================================
     */
    public function providerName(): string
    {
        return 'fixture';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả định danh model fixture dùng đối chiếu metadata
     * =====================================================================
     *
     * INPUT:
     * - Không có.
     *
     * OUTPUT:
     * - Tên model cố định cho fixture của class.
     *
     * SIDE EFFECT:
     * - Chỉ đọc identity trong memory; không I/O.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction hoặc gọi provider thật.
     *
     * =====================================================================
     */
    public function modelName(): string
    {
        return 'fixture-model';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi captured input và trả output structured fixture
     * =====================================================================
     *
     * INPUT:
     * - Tham số fixture hoặc dữ liệu test được caller chuẩn bị.
     *
     * OUTPUT:
     * - Kết quả/exception giả lập để kiểm contract; HTTP/queue dùng fake trong ca test.
     *
     * SIDE EFFECT:
     * - Ghi captured input trong memory và dựng output từ ArticlePipelineFixture; không HTTP/DB.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi fixture/schema truyền lên ca test; không mở transaction.
     *
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

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Provider giả bỏ qua nhóm output để kiểm boundary service.
 * =====================================================================
 *
 * Provider cố tình bỏ qua requested groups để kiểm boundary service vẫn lọc/merge output đúng. Trả response fixture hoặc DTO ba bước, không gọi AI thật.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct().
 * - configured().
 * - execute().
 * - providerName().
 * - modelName().
 * - generate().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Response fixture, task request hoặc tham số generate legacy.
 * - OUTPUT: Output giả lập chưa tự lọc nhóm field ở provider.
 * - SIDE EFFECT: Chỉ xử lý memory; không DB/HTTP.
 * - EXCEPTION/TRANSACTION: Không mở transaction; ca test kiểm lỗi schema hoặc selection ở service.
 * =====================================================================
 */
final class SelectedOutputsRawProvider implements AiProviderContract
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo provider fixture cho ca kiểm thử
     * =====================================================================
     *
     * INPUT:
     * - Dữ liệu responses/context để khởi tạo helper fixture.
     *
     * OUTPUT:
     * - Helper test sẵn sàng, không gọi HTTP hoặc ghi database.
     *
     * SIDE EFFECT:
     * - Chỉ giữ response/captured state trong memory; không DB/HTTP.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction hoặc gọi AI thật.
     *
     * =====================================================================
     */
    public function __construct(private readonly array $response) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Báo provider fixture sẵn sàng trong kiểm thử
     * =====================================================================
     *
     * INPUT:
     * - Không có; provider chỉ dùng trong test.
     *
     * OUTPUT:
     * - bool true.
     *
     * SIDE EFFECT:
     * - Chỉ trả cấu hình fixture; không DB/HTTP.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction hoặc gọi AI thật.
     *
     * =====================================================================
     */
    public function configured(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả DTO output fixture theo task pipeline
     * =====================================================================
     *
     * INPUT:
     * - Tham số fixture hoặc dữ liệu test được caller chuẩn bị.
     *
     * OUTPUT:
     * - Kết quả/exception giả lập để kiểm contract; HTTP/queue dùng fake trong ca test.
     *
     * SIDE EFFECT:
     * - Chỉ dựng output/DTO từ response fixture; không HTTP/DB.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi fixture/schema truyền lên ca test; không mở transaction.
     *
     * =====================================================================
     */
    public function execute(AiTaskRequest $request): AiTaskResponse
    {
        return new AiTaskResponse(ArticlePipelineFixture::output($request->task, $request->input, $this->response, $request->schema));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả định danh provider fixture dùng đối chiếu provenance
     * =====================================================================
     *
     * INPUT:
     * - Không có.
     *
     * OUTPUT:
     * - Tên provider cố định cho fixture của class.
     *
     * SIDE EFFECT:
     * - Chỉ đọc identity trong memory; không I/O.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction hoặc tra catalog thật.
     *
     * =====================================================================
     */
    public function providerName(): string
    {
        return 'fixture';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả định danh model fixture dùng đối chiếu metadata
     * =====================================================================
     *
     * INPUT:
     * - Không có.
     *
     * OUTPUT:
     * - Tên model cố định cho fixture của class.
     *
     * SIDE EFFECT:
     * - Chỉ đọc identity trong memory; không I/O.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction hoặc gọi provider thật.
     *
     * =====================================================================
     */
    public function modelName(): string
    {
        return 'fixture-model';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả response fixture theo contract generate legacy
     * =====================================================================
     *
     * INPUT:
     * - Tham số fixture hoặc dữ liệu test được caller chuẩn bị.
     *
     * OUTPUT:
     * - Kết quả/exception giả lập để kiểm contract; HTTP/queue dùng fake trong ca test.
     *
     * SIDE EFFECT:
     * - Chỉ dựng output/DTO từ response fixture; không HTTP/DB.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi fixture/schema truyền lên ca test; không mở transaction.
     *
     * =====================================================================
     */
    public function generate(string $title, string $content, string $language = 'vi', string $rewriteStyle = 'informative', string $promptKey = 'post.create.from_url', string $instructions = ''): array
    {
        return $this->response;
    }
}
