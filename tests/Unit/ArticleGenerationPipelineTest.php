<?php

namespace Tests\Unit;

use App\Exceptions\AiImportException;
use App\Models\AiImport;
use App\Services\Ai\Content\AiContentSanitizer;
use App\Services\Ai\Content\ArticleImportService;
use App\Services\Ai\Content\ArticleSourceExtractor;
use App\Services\Ai\Content\Data\ArticleInputHasher;
use App\Services\Ai\Content\Pipelines\ArticleGenerationPipeline;
use App\Services\Ai\Content\Prompts\ArticlePromptBuilder;
use App\Services\Ai\Content\Quality\ArticleEvidenceValidator;
use App\Services\Ai\Content\Quality\ArticleQualityGate;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Data\AiTaskRequest;
use App\Services\Ai\Data\AiTaskResponse;
use App\Services\Ai\Providers\Adapters\OpenAiProvider;
use App\Services\Ai\Providers\Diagnostics\AiResponseDiagnostics;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Regression Analyze–Write–Edit, nguồn, anchors, budget và quality gates.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - test_three_stages_receive_distinct_prompts_and_validated_previous_outputs().
 * - test_checkpoint_hash_ignores_object_key_order_but_preserves_list_order().
 * - test_twenty_controlled_sources_keep_required_source_information().
 * - test_invalid_source_anchor_stops_before_writer().
 * - test_fabricated_evidence_is_rejected_even_with_existing_block().
 * - test_blank_whitespace_or_entity_evidence_cannot_match_every_source_block().
 * - test_plan_missing_important_fact_is_rejected().
 * - test_editor_missing_selected_content_cannot_be_hidden_by_source_merge().
 * - test_changed_numeric_limit_fails_after_editor().
 * - test_numeric_gate_keeps_table_boundaries_and_allows_grouped_integer_counts().
 * - test_numeric_gate_still_rejects_changed_count_and_version().
 * - test_analysis_prompt_specifies_plain_text_and_legacy_schema_stays_unchanged().
 * - test_reference_failure_survives_public_diagnostics_redaction().
 * - test_model_cannot_invent_taxonomy_even_in_nested_final().
 * - test_model_cannot_invent_image_url().
 * - test_existing_inline_asset_is_restored_at_placeholder().
 * - test_inline_figure_keeps_caption_and_position_when_restored_after_regenerate().
 * - test_extractor_preserves_form_content_code_whitespace_and_main_instead_of_empty_first_article().
 * - test_oversize_source_is_rejected_instead_of_silent_truncation().
 * - test_request_budget_is_checked_before_first_paid_call().
 * - test_source_image_metadata_resolves_lazy_and_relative_urls_without_auto_import().
 * - test_safe_inline_images_survive_sanitizer_and_obfuscated_javascript_is_removed().
 * - test_copy_gate_excludes_code_and_quotes_but_rejects_long_prose_copy().
 * - test_copy_gate_cannot_be_bypassed_by_dropping_heading_and_adding_filler().
 * - test_wrong_language_is_detected_using_multiple_lexical_signals().
 * - test_short_or_technical_language_is_undetermined().
 * - test_title_only_uses_one_generation_call_and_profile_guidance().
 * - test_openai_execute_accepts_nested_task_schema_and_preserves_strict_finish_guard().
 * - source().
 * - responses().
 * - generateFixture().
 * - __construct().
 * - configured().
 * - providerName().
 * - modelName().
 * - execute().
 * - generate().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : fixtures source/JSON và cấu hình test đã cô lập.
 * - OUTPUT: assertions contract; không gọi AI thật hoặc ghi database development.
 * =====================================================================
 */
final class ArticleGenerationPipelineTest extends TestCase
{
    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_three_stages_receive_distinct_prompts_and_validated_previous_outputs(): void
    {
        $provider = new PipelineFixtureProvider($this->responses());
        $import = new AiImport(['input_json' => [
            'language' => 'vi', 'instructions' => 'Viết ngắn cho người mới.',
            'writing_profile_snapshot' => ['id' => 7, 'version' => 2, 'style_instructions' => 'Giọng trung tính.', 'rules' => ['tone' => 'neutral'], 'evidence' => ['secret_sample_fact' => 'sample fact must not travel']],
        ]]);
        $result = (new ArticleGenerationPipeline)->run($import, $provider, $this->source(), ['content']);

        $this->assertSame(['article.analysis-plan', 'article.writer', 'article.editor'], array_map(fn ($request) => $request->task, $provider->requests));
        $this->assertSame('<p>Bản 2.0 hỗ trợ tối đa 100 phần tử, yêu cầu gói trả phí.</p>', $result['content_html']);
        $this->assertArrayHasKey('analysis', $provider->requests[1]->input);
        $this->assertArrayHasKey('draft', $provider->requests[2]->input);
        $this->assertArrayNotHasKey('evidence', $provider->requests[0]->input['profile']);
        $this->assertStringNotContainsString('sample fact', json_encode($provider->requests));
        $this->assertStringContainsString('not an article', $provider->requests[0]->systemInstructions);
        $this->assertSame('Viết ngắn cho người mới.', $provider->requests[1]->input['brief']['additional_instructions']);
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_checkpoint_hash_ignores_object_key_order_but_preserves_list_order(): void
    {
        $this->assertSame(ArticleInputHasher::hash(['source' => ['title' => 'a', 'html' => 'b'], 'refs' => ['F1', 'F2']]), ArticleInputHasher::hash(['refs' => ['F1', 'F2'], 'source' => ['html' => 'b', 'title' => 'a']]));
        $this->assertNotSame(ArticleInputHasher::hash(['F1', 'F2']), ArticleInputHasher::hash(['F2', 'F1']));
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_twenty_controlled_sources_keep_required_source_information(): void
    {
        $dataset = json_decode(file_get_contents(base_path('tests/Fixtures/AiContent/evaluation-sources.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(20, $dataset['sources']);
        $extractor = new ArticleSourceExtractor;
        foreach ($dataset['sources'] as $source) {
            $html = $extractor->extract($source['source_html'], $source['source_url']);
            $snapshot = $extractor->snapshot($html, ['source_url' => $source['source_url']]);
            $this->assertNotEmpty($snapshot['blocks'], $source['id']);
            foreach ($source['must_preserve'] as $excerpt) {
                $this->assertStringContainsString($excerpt, html_entity_decode(strip_tags($snapshot['content_html']), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $source['id']);
            }
            $this->assertStringNotContainsString('stealSecret', $snapshot['content_html']);
            $this->assertStringNotContainsString('Quảng cáo cần bỏ', $snapshot['content_html']);
            $this->assertStringNotContainsString('Bài liên quan cần bỏ', $snapshot['content_html']);
        }
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_invalid_source_anchor_stops_before_writer(): void
    {
        $responses = $this->responses();
        $responses[0]['knowledge']['facts'][0]['source_block_ids'] = ['S999'];
        $provider = new PipelineFixtureProvider($responses);
        try {
            $this->generateFixture($provider);
            $this->fail('Unknown anchor must fail.');
        } catch (AiImportException $exception) {
            $this->assertSame('AI_PROVIDER_SCHEMA', $exception->errorCode);
            $this->assertSame('invalid_value', $exception->diagnostics['validation_errors'][0]['reason']);
            $this->assertCount(1, $provider->requests);
        }
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_fabricated_evidence_is_rejected_even_with_existing_block(): void
    {
        $responses = $this->responses();
        $responses[0]['knowledge']['facts'][0]['evidence'] = 'The endpoint is ten times faster.';
        $this->expectException(AiImportException::class);
        $this->expectExceptionMessage('bằng chứng');
        $this->generateFixture(new PipelineFixtureProvider($responses));
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_blank_whitespace_or_entity_evidence_cannot_match_every_source_block(): void
    {
        foreach ([" \n\t ", '&nbsp; &#160;', "\u{200B}"] as $emptyEvidence) {
            $responses = $this->responses();
            $responses[0]['knowledge']['facts'][0]['evidence'] = $emptyEvidence;
            $provider = new PipelineFixtureProvider($responses);
            try {
                $this->generateFixture($provider);
                $this->fail('Blank evidence must fail.');
            } catch (AiImportException $exception) {
                $this->assertSame('AI_SOURCE_REFERENCE', $exception->errorCode);
                $this->assertCount(1, $provider->requests);
            }
        }
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_plan_missing_important_fact_is_rejected(): void
    {
        $responses = $this->responses();
        $responses[0]['writing_plan']['coverage_fact_ids'] = ['F999'];
        $this->expectException(AiImportException::class);
        $this->generateFixture(new PipelineFixtureProvider($responses));
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_editor_missing_selected_content_cannot_be_hidden_by_source_merge(): void
    {
        $responses = $this->responses();
        $responses[2]['final'] = [];
        $this->expectException(AiImportException::class);
        $this->expectExceptionMessage('schema');
        $this->generateFixture(new PipelineFixtureProvider($responses));
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_changed_numeric_limit_fails_after_editor(): void
    {
        $responses = $this->responses();
        $responses[2]['final']['content_html'] = '<p>Bản 2.0 hỗ trợ 500 phần tử, cần gói trả phí.</p>';
        try {
            $this->generateFixture(new PipelineFixtureProvider($responses));
            $this->fail('Missing numeric evidence must fail.');
        } catch (AiImportException $exception) {
            $this->assertSame('AI_QUALITY_GROUNDING', $exception->errorCode);
        }
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_model_cannot_invent_taxonomy_even_in_nested_final(): void
    {
        $responses = $this->responses();
        $responses[2]['final']['category_ids'] = [1];
        $this->expectException(AiImportException::class);
        $this->expectExceptionMessage('schema');
        $this->generateFixture(new PipelineFixtureProvider($responses));
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_model_cannot_invent_image_url(): void
    {
        $responses = $this->responses();
        $responses[2]['final']['content_html'] .= '<img src="https://bad.test/fake.png">';
        $this->expectException(AiImportException::class);
        $this->expectExceptionMessage('URL ảnh');
        $this->generateFixture(new PipelineFixtureProvider($responses));
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_existing_inline_asset_is_restored_at_placeholder(): void
    {
        $responses = $this->responses();
        $responses[1]['used_asset_ids'] = ['I1'];
        $responses[2]['final']['content_html'] .= '<span data-ai-image-ref="I1"></span>';
        $source = $this->source();
        $source['inline_image_refs'] = ['I1' => '<img src="/storage/media/7.webp" data-media-asset-id="7" alt="Ví dụ">'];
        $result = (new ArticleGenerationPipeline)->run(new AiImport(['input_json' => ['language' => 'vi']]), new PipelineFixtureProvider($responses), $source, ['content']);

        $this->assertStringContainsString('data-media-asset-id="7"', $result['content_html']);
        $this->assertStringNotContainsString('data-ai-image-ref', $result['content_html']);
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_extractor_preserves_form_content_code_whitespace_and_main_instead_of_empty_first_article(): void
    {
        $extractor = new ArticleSourceExtractor;
        $content = $extractor->extract('<article></article><main><form><p>Nội dung cần giữ.</p><pre><code>if (true) {'."\n".'    echo "ok";'."\n".'}</code></pre><table><tr><td>100</td></tr></table><blockquote>Điều kiện</blockquote><a href="https://docs.test">Docs</a></form></main><footer>Footer</footer>');
        $snapshot = $extractor->snapshot($content, ['source_url' => 'inline://fixture']);

        $this->assertStringContainsString('Nội dung cần giữ.', $content);
        $this->assertStringContainsString("\n    echo", $content);
        $this->assertStringContainsString('<table>', $content);
        $this->assertStringNotContainsString('Footer', $content);
        $this->assertSame(['S001', 'S002', 'S003', 'S004'], array_column($snapshot['blocks'], 'id'));
        $this->assertSame('pre', $snapshot['blocks'][1]['type']);
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_oversize_source_is_rejected_instead_of_silent_truncation(): void
    {
        $this->expectException(AiImportException::class);
        $this->expectExceptionMessage('không cắt thầm');
        (new ArticleSourceExtractor)->snapshot('<p>'.str_repeat('source ', 30).'</p>', [], ['max_source_characters' => 20]);
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_request_budget_is_checked_before_first_paid_call(): void
    {
        $provider = new PipelineFixtureProvider($this->responses());
        $import = new AiImport(['input_json' => ['pipeline_snapshot' => array_replace(config('ai-content'), ['max_request_characters' => 20])]]);
        try {
            (new ArticleGenerationPipeline)->run($import, $provider, $this->source(), ['content']);
            $this->fail('Request budget must fail.');
        } catch (AiImportException $exception) {
            $this->assertSame('AI_INPUT_BUDGET', $exception->errorCode);
            $this->assertCount(0, $provider->requests);
        }
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_source_image_metadata_resolves_lazy_and_relative_urls_without_auto_import(): void
    {
        $extractor = new ArticleSourceExtractor;
        $html = $extractor->extract('<article><p>Hướng dẫn <a href="../docs">Docs</a></p><figure><img data-src="./shot.png" alt="Giao diện"><figcaption>Minh họa</figcaption></figure></article>', 'https://example.test/posts/start');
        $snapshot = $extractor->snapshot($html, ['source_url' => 'https://example.test/posts/start']);
        $this->assertSame('https://example.test/posts/shot.png', $snapshot['source_images'][0]['source_url']);
        $this->assertStringContainsString('href="https://example.test/docs"', $snapshot['content_html']);
        $this->assertStringNotContainsString('<img', $snapshot['content_html']);
        $this->assertSame('Giao diện', $snapshot['source_images'][0]['alt']);
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_safe_inline_images_survive_sanitizer_and_obfuscated_javascript_is_removed(): void
    {
        $result = (new AiContentSanitizer)->sanitize('<figure><img src="/storage/7.webp" data-media-asset-id="7" alt="Ảnh" onerror="bad()"><figcaption>Chú thích</figcaption></figure><a href="java&#9;script:bad()">bad</a><img src="data:image/png;base64,AA">');
        $this->assertStringContainsString('data-media-asset-id="7"', $result);
        $this->assertStringContainsString('<figcaption>', $result);
        $this->assertStringNotContainsString('onerror', $result);
        $this->assertStringNotContainsString('script:', $result);
        $this->assertStringNotContainsString('base64', $result);
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_copy_gate_excludes_code_and_quotes_but_rejects_long_prose_copy(): void
    {
        $html = '<p>'.str_repeat('Đây là nội dung nguồn cung cấp các thông tin hữu ích cho người đọc. ', 8).'</p><pre><code>echo "ok";</code></pre>';
        $source = (new ArticleSourceExtractor)->snapshot($html, []);
        try {
            (new ArticleQualityGate)->inspect($source, ['content_html' => $html], 'vi');
            $this->fail('Copy must fail.');
        } catch (AiImportException $exception) {
            $this->assertSame('AI_QUALITY_EXACT_COPY', $exception->errorCode);
        }
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_copy_gate_cannot_be_bypassed_by_dropping_heading_and_adding_filler(): void
    {
        $paragraph = str_repeat('Đây là nội dung nguồn cần được viết lại bằng cách diễn đạt phù hợp với bài. ', 5);
        $source = (new ArticleSourceExtractor)->snapshot('<h1>Source heading</h1><p>'.$paragraph.'</p>', []);
        $this->expectException(AiImportException::class);
        $this->expectExceptionMessage('sao chép nguyên văn');
        (new ArticleQualityGate)->inspect($source, ['content_html' => '<p>Mở bài bổ sung.</p><p>'.$paragraph.'</p>'], 'vi');
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_wrong_language_is_detected_using_multiple_lexical_signals(): void
    {
        $source = $this->source();
        $english = '<p>'.str_repeat('The article is for you and your team. You can use this with the source and that is all. ', 8).'Version 2.0 supports 100 items.</p>';
        try {
            (new ArticleQualityGate)->inspect($source, ['content_html' => $english], 'vi');
            $this->fail('Wrong language must fail.');
        } catch (AiImportException $exception) {
            $this->assertSame('AI_QUALITY_LANGUAGE', $exception->errorCode);
        }
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_short_or_technical_language_is_undetermined(): void
    {
        $checks = (new ArticleQualityGate)->inspect($this->source(), ['content_html' => '<p>API 2.0: 100 items.</p>'], 'vi');
        $this->assertSame('undetermined', collect($checks)->firstWhere('check', 'language')['status']);
    }

    /**
     * =====================================================================
     * Input: ảnh MediaLibrary có caption và placeholder giữa hai đoạn văn.
     * Output: giữ cả figure/caption/alt/ID/URL đúng vị trí, không nhân đôi ảnh.
     * =====================================================================
     */
    public function test_inline_figure_keeps_caption_and_position_when_restored_after_regenerate(): void
    {
        $refs = (new ArticleSourceExtractor)->imageReferences('<p>Đầu bài.</p><figure><img src="https://example.test/asset.png" data-media-asset-id="7" alt="Mô tả"><figcaption>Chú thích biên tập.</figcaption></figure><p>Cuối bài.</p>');
        [$html, $warnings] = (new ArticleGenerationPipeline)->restoreImages('<p>Đoạn mới.</p><span data-ai-image-ref="I1"></span><p>Đoạn cuối.</p>', $refs);
        $this->assertSame([], $warnings);
        $this->assertStringContainsString('<figcaption>Chú thích biên tập.</figcaption>', $html);
        $this->assertStringContainsString('data-media-asset-id="7"', $html);
        $this->assertSame(1, substr_count($html, '<img'));
        $this->assertLessThan(strpos($html, 'Đoạn cuối.'), strpos($html, '<figure>'));
        $this->assertGreaterThan(strpos($html, 'Đoạn mới.'), strpos($html, '<figure>'));
    }

    /**
     * =====================================================================
     * Input: hai ô bảng và số nguyên nguồn được viết theo nhóm nghìn.
     * Output: số liệu giữ đúng được chấp nhận; không ghép số của hai ô khác nhau.
     * =====================================================================
     */
    public function test_numeric_gate_keeps_table_boundaries_and_allows_grouped_integer_counts(): void
    {
        $source = (new ArticleSourceExtractor)->snapshot('<p>Tàu có 2090 phòng và 4905 khách.</p><table><tr><td>2017</td><td>5</td><td>20500000</td></tr></table>', []);
        $analysis = ['knowledge' => ['facts' => [['important' => true, 'evidence' => '2090 phòng và 4905 khách; 2017, 5 ngày, 20500000 đồng']]]];
        $checks = (new ArticleQualityGate)->inspect($source, ['content_html' => '<p>Sức chứa 4.905 khách trong 2.090 phòng.</p><table><tr><td>2017</td><td>5</td><td>20.500.000</td></tr></table>'], 'vi', $analysis);
        $this->assertSame('pass', collect($checks)->firstWhere('check', 'important_numbers')['status']);
        $this->assertSame('pass', collect($checks)->firstWhere('check', 'unanchored_numbers')['status']);
    }

    /**
     * =====================================================================
     * Input: count sai hoặc version mất dấu chấm/đổi số.
     * Output: gate vẫn chặn; không dùng normalize số đếm cho version nguồn.
     * =====================================================================
     */
    public function test_numeric_gate_still_rejects_changed_count_and_version(): void
    {
        foreach ([['2090', '2.091'], ['2.10', '2.1'], ['2.090', '2090']] as [$original, $changed]) {
            $source = (new ArticleSourceExtractor)->snapshot('<p>Source '.$original.'</p>', []);
            try {
                (new ArticleQualityGate)->inspect($source, ['content_html' => '<p>Result '.$changed.'</p>'], 'vi', ['knowledge' => ['facts' => [['important' => true, 'evidence' => $original]]]]);
                $this->fail('Changed numeric data must fail.');
            } catch (AiImportException $exception) {
                $this->assertSame('AI_QUALITY_GROUNDING', $exception->errorCode);
            }
        }
    }

    /**
     * =====================================================================
     * Input: schema snapshot 2.0/2.1. Output: plain-text rule rõ ở 2.1, không đổi request 2.0.
     * =====================================================================
     */
    public function test_analysis_prompt_specifies_plain_text_and_legacy_schema_stays_unchanged(): void
    {
        $builder = new ArticlePromptBuilder;
        $old = $builder->schema('article.analysis-plan', ['title', 'content'], ['prompt_version' => '2.0']);
        $new = $builder->schema('article.analysis-plan', ['title', 'content'], config('ai-content'));
        $this->assertArrayNotHasKey('description', data_get($old, 'properties.knowledge.properties.facts.items.properties.evidence'));
        $this->assertStringContainsString('source.blocks[].text', data_get($new, 'properties.knowledge.properties.facts.items.properties.evidence.description'));
        $this->assertStringContainsString('NEVER copy from its html', config('ai-content.prompts')['article.analysis-plan']);
    }

    /**
     * =====================================================================
     * Input: evidence giả/markup. Output: public diagnostics giữ reason an toàn, không excerpt.
     * =====================================================================
     */
    public function test_reference_failure_survives_public_diagnostics_redaction(): void
    {
        $analysis = $this->responses()[0];
        $analysis['knowledge']['facts'][0]['evidence'] = '<strong>fabricated evidence</strong>';
        try {
            (new ArticleEvidenceValidator)->analysis($analysis, $this->source());
            $this->fail('Wrong evidence must fail.');
        } catch (AiImportException $exception) {
            $safe = AiResponseDiagnostics::sanitize($exception->diagnostics);
            $this->assertSame('evidence_not_in_source', $safe['validation_errors'][0]['reason']);
            $this->assertSame('task_output', $safe['validation_errors'][0]['field']);
            $this->assertStringNotContainsString('fabricated evidence', json_encode($safe));
        }
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_title_only_uses_one_generation_call_and_profile_guidance(): void
    {
        $provider = new PipelineFixtureProvider([], ['title' => 'Tiêu đề mới']);
        $result = (new ArticleImportService($provider))->run(new AiImport([
            'source_text' => "Nguồn\n\nNội dung nguồn", 'input_json' => ['source_type' => 'text', 'fields' => ['title'], 'writing_profile_snapshot' => ['style_instructions' => 'Ngắn và trung tính.']],
        ]));
        $this->assertSame('Tiêu đề mới', $result['draft']['title']);
        $this->assertCount(0, $provider->requests);
        $this->assertStringContainsString('Ngắn và trung tính.', $provider->generationInstructions);
    }

    /**
     * =====================================================================
     * INPUT: Fixture nguồn/output/cấu hình và dependencies fake của ca này.
     * OUTPUT: Assertions contract/hành vi mong đợi; không gọi API AI thật.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function test_openai_execute_accepts_nested_task_schema_and_preserves_strict_finish_guard(): void
    {
        config()->set('ai-providers.connections.openai.key', 'fixture-key');
        config()->set('ai-providers.connections.openai.endpoint', 'https://api.openai.com/v1/chat/completions');
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/chat/completions' => Http::sequence()
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode(['profile' => ['tone' => 'neutral']])]]]])
            ->push(['choices' => [['finish_reason' => 'length', 'message' => ['content' => '{}']]]])]);
        $request = new AiTaskRequest('fixture.analysis', 'Analyze only.', ['text' => 'sample'], [
            'type' => 'object', 'properties' => ['profile' => ['type' => 'object', 'properties' => ['tone' => ['type' => 'string']], 'required' => ['tone'], 'additionalProperties' => false]],
            'required' => ['profile'], 'additionalProperties' => false,
        ]);
        $result = (new OpenAiProvider)->execute($request);
        $this->assertSame(['profile' => ['tone' => 'neutral']], $result->output);
        Http::assertSent(fn ($http) => str_contains($http['messages'][1]['content'], 'fixture.analysis') && $http['tool_choice'] === 'none');

        $this->expectException(AiImportException::class);
        $this->expectExceptionMessage('chưa hoàn tất');
        (new OpenAiProvider)->execute($request);
    }

    /**
     * =====================================================================
     * INPUT: Tham số fixture hoặc dữ liệu test được caller chuẩn bị.
     * OUTPUT: Kết quả/exception giả lập để kiểm contract; HTTP/queue dùng fake trong ca test.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    private function source(): array
    {
        return (new ArticleSourceExtractor)->snapshot('<p>Version 2.0 supports 100 items and requires a paid plan.</p>', ['source_url' => 'inline://fixture', 'title' => 'Version 2.0']);
    }

    /**
     * =====================================================================
     * INPUT: Tham số fixture hoặc dữ liệu test được caller chuẩn bị.
     * OUTPUT: Kết quả/exception giả lập để kiểm contract; HTTP/queue dùng fake trong ca test.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    private function responses(): array
    {
        return [
            ['knowledge' => ['facts' => [['id' => 'F1', 'claim' => 'Version 2.0 supports 100 items and requires a paid plan.', 'source_block_ids' => ['S001'], 'evidence' => 'Version 2.0 supports 100 items and requires a paid plan.', 'important' => true]], 'terms' => ['batch'], 'uncertainties' => []], 'writing_plan' => ['article_type' => 'release', 'audience' => 'người mới', 'angle' => 'giới hạn và điều kiện', 'outline' => ['thay đổi'], 'coverage_fact_ids' => ['F1']]],
            ['draft' => ['content_html' => '<p>Bản 2.0 nhận 100 phần tử nếu dùng gói trả phí.</p>'], 'used_fact_ids' => ['F1'], 'used_asset_ids' => []],
            ['final' => ['content_html' => '<p>Bản 2.0 hỗ trợ tối đa 100 phần tử, yêu cầu gói trả phí.</p>'], 'used_fact_ids' => ['F1'], 'issues' => []],
        ];
    }

    /**
     * =====================================================================
     * INPUT: Tham số fixture hoặc dữ liệu test được caller chuẩn bị.
     * OUTPUT: Kết quả/exception giả lập để kiểm contract; HTTP/queue dùng fake trong ca test.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    private function generateFixture(PipelineFixtureProvider $provider): array
    {
        return (new ArticleGenerationPipeline)->run(new AiImport(['input_json' => ['language' => 'vi']]), $provider, $this->source(), ['content']);
    }
}

final class PipelineFixtureProvider implements AiProviderContract
{
    public array $requests = [];

    public string $generationInstructions = '';

    /**
     * =====================================================================
     * INPUT: Dữ liệu responses/context để khởi tạo helper fixture.
     * OUTPUT: Helper test sẵn sàng, không gọi HTTP hoặc ghi database.
     * SIDE EFFECT: chỉ xử lý fixtures hoặc database test đã cô lập.
     * =====================================================================
     */
    public function __construct(private array $responses, private array $generated = []) {}

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
    public function execute(AiTaskRequest $request): AiTaskResponse
    {
        $this->requests[] = $request;

        return new AiTaskResponse(array_shift($this->responses) ?? []);
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
        $this->generationInstructions = $instructions;

        return $this->generated;
    }
}
