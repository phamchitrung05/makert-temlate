<?php

namespace Tests\Unit;

use App\Exceptions\AiImportException;
use App\Models\AiImport;
use App\Services\Ai\Content\AiTaskSchemaValidator;
use App\Services\Ai\Content\ArticleSourceExtractor;
use App\Services\Ai\Content\Data\ArticleInputHasher;
use App\Services\Ai\Content\Pipelines\ArticleGenerationPipeline;
use App\Services\Ai\Content\Prompts\ArticlePromptBuilder;
use App\Services\Ai\Content\Quality\ArticleEvidenceValidator;
use App\Services\Ai\Content\Quality\ArticleQualityGate;
use App\Services\Ai\Content\Quality\ArticleSourceLinkPolicy;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Data\AiTaskRequest;
use App\Services\Ai\Data\AiTaskResponse;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Regression các lỗi pilot thật về link, quý và namespace fact IDs.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: artifact(), context(), provider(),
 * test_saved_sources_distinguish_navigation_from_reference_links(),
 * test_saved_candidates_pass_with_their_original_facts_and_html(),
 * test_saved_corrupted_navigation_url_remains_blocked_with_safe_diagnostics(),
 * test_missing_or_changed_body_references_are_still_blocked(),
 * test_reference_lists_and_body_fragments_are_not_exempted(),
 * test_same_site_blog_taxonomy_links_are_optional_but_article_links_are_required(),
 * test_href_parser_decodes_entities_and_handles_unquoted_attributes(),
 * test_roman_quarters_are_equivalent_but_changed_quarters_fail(),
 * test_storey_word_counts_keep_value_and_reject_partial_large_numbers(),
 * test_new_q18_candidate_keeps_two_storeys_and_passes_without_rewriting_it(),
 * test_saved_writer_source_block_id_is_still_an_invalid_fact_reference(),
 * test_new_writer_schema_stops_unknown_ids_before_editor(),
 * test_analysis_cannot_name_facts_with_source_or_image_ids(),
 * test_reference_schema_accepts_known_facts_and_only_supplied_images(),
 * test_link_contract_reaches_analyze_write_and_edit_without_mutating_source(),
 * test_removed_branch_is_rejected_by_cli_before_execution(),
 * test_saved_single_step_output_remains_readable_offline(),
 * test_preflight_accepts_checkout_newlines_but_rejects_changed_source(),
 * test_old_snapshot_requests_keep_their_original_hashes().
 * INPUT/OUTPUT: frozen sources/calls và fixtures -> assertions; 0 HTTP/model call.
 * SIDE EFFECT: không sửa artifacts, Settings, DB development hoặc Apply Post.
 * =====================================================================
 */
final class ArticleQualityPilotRegressionTest extends TestCase
{
    /** Input: timeout ngoài giới hạn hoặc export. Output: CLI chặn trước provider/DB. */
    public function test_evaluation_timeout_override_is_bounded_and_cannot_change_archives(): void
    {
        foreach ([['--request-timeout=4', '--run'], ['--request-timeout=601', '--run'], ['--request-timeout=invalid', '--run'], ['--request-timeout=600', '--export-only']] as $flags) {
            $process = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', base_path('scripts/ai-quality/evaluate.php'), ...$flags], base_path());
            $process->run();
            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('--request-timeout', $process->getErrorOutput());
        }
        $process = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', base_path('scripts/ai-quality/evaluate.php'), '--manifest=docs/qa/task2-quality/corpus-v2/manifest.json', '--case=Q33', '--request-timeout=600'], base_path());
        $process->run();
        $this->assertSame(0, $process->getExitCode());
        $preflight = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('preflight', $preflight['mode']);
        $this->assertSame(['C'], $preflight['arms']);
        $this->assertSame(3, $preflight['planned_calls']);
    }

    /** Input: đường dẫn artifact. Output: JSON đã lưu, chỉ đọc. */
    private function artifact(string $path): array
    {
        return json_decode(file_get_contents(base_path('docs/qa/task2-quality/'.$path)), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Input: không có. Output: context ổn định dùng kiểm bytes/hash request cũ. */
    private function context(): array
    {
        return [
            'source' => ['content_html' => '<p>Version 2.0 supports 100 items.</p>', 'blocks' => [['id' => 'S001', 'text' => 'Version 2.0 supports 100 items.', 'html' => '<p>Version 2.0 supports 100 items.</p>', 'type' => 'p']]],
            'brief' => ['language' => 'vi'], 'profile' => [],
            'images' => [['id' => 'I1', 'placeholder' => '<span data-ai-image-ref="I1"></span>']],
        ];
    }

    /** Input: outputs đã lưu. Output: provider offline bắt đúng số task, không network. */
    private function provider(array $outputs, array &$requests): AiProviderContract
    {
        $provider = Mockery::mock(AiProviderContract::class);
        $provider->shouldReceive('configured')->andReturn(true);
        $provider->shouldReceive('providerName')->andReturn('saved-pilot-fixture');
        $provider->shouldReceive('modelName')->andReturn('offline');
        $provider->shouldReceive('execute')->times(count($outputs))->andReturnUsing(function (AiTaskRequest $request) use ($outputs, &$requests): AiTaskResponse {
            $requests[] = $request;

            return new AiTaskResponse($outputs[count($requests) - 1]);
        });

        return $provider;
    }

    /** Input: Q02/Q07 frozen. Output: chỉ TOC tùy chọn; body URL và hashes giữ nguyên. */
    public function test_saved_sources_distinguish_navigation_from_reference_links(): void
    {
        $manifest = $this->artifact('corpus-v1/manifest.json');
        foreach (['Q02' => [2, 3], 'Q07' => [0, 11]] as $id => [$required, $navigation]) {
            $source = $this->artifact('corpus-v1/'.$id.'.json');
            $links = (new ArticleSourceLinkPolicy)->manifest($source);
            $this->assertCount($required, $links['required_hrefs']);
            $this->assertCount($navigation, $links['optional_navigation_hrefs']);
            $case = array_values(array_filter($manifest['cases'], fn (array $case): bool => $case['case_id'] === $id))[0];
            // Git autocrlf đổi newline ngoài JSON; nội dung vẫn phải đúng bản freeze.
            $bytes = file_get_contents(base_path('docs/qa/task2-quality/corpus-v1/'.$id.'.json'));
            $this->assertSame($case['source_sha256'], hash('sha256', str_replace("\r\n", "\n", $bytes)));
        }
        $this->assertSame(['https://semver.org', 'https://www.php.net/manual/en/functions.arguments.php#functions.named-arguments'], (new ArticleSourceLinkPolicy)->manifest($this->artifact('corpus-v1/Q02.json'))['required_hrefs']);
    }

    /** Input: candidate thật Q02-C/Q07-C. Output: qua gate mà không sửa HTML/facts. */
    public function test_saved_candidates_pass_with_their_original_facts_and_html(): void
    {
        foreach (['Q02', 'Q07'] as $id) {
            $artifact = $this->artifact('pilot-2026-10-05-five-cases/'.$id.'-C.json');
            $this->assertSame('failed', $artifact['status']);
            $checks = (new ArticleQualityGate)->inspect($this->artifact('corpus-v1/'.$id.'.json'), $artifact['candidate_for_review'], 'vi', $artifact['calls'][0]['output']);
            $this->assertSame('pass', array_column($checks, 'status', 'check')['source_code_links']);
            $this->assertSame('pass', array_column($checks, 'status', 'check')['important_numbers']);
            $this->assertSame('undetermined', array_column($checks, 'status', 'check')['semantic_grounding']);
        }
    }

    /** Input: Q02-B sai commit URL. Output: vẫn fail, public diagnostics không lộ URL. */
    public function test_saved_corrupted_navigation_url_remains_blocked_with_safe_diagnostics(): void
    {
        $artifact = $this->artifact('pilot-2026-10-05-five-cases/Q02-B.json');
        try {
            (new ArticleQualityGate)->inspect($this->artifact('corpus-v1/Q02.json'), $artifact['candidate_for_review'], 'vi');
            $this->fail('A corrupted optional URL must not become an allowed reference.');
        } catch (AiImportException $exception) {
            $this->assertSame('AI_QUALITY_GROUNDING', $exception->errorCode);
            $this->assertSame('source_link_unknown', $exception->diagnostics['validation_errors'][0]['reason']);
            $this->assertStringNotContainsString('github.com', json_encode($exception->diagnostics));
        }
    }

    /** Input: body href bị xóa/sai hash/query/fragment. Output: gate không tự sửa hoặc nới. */
    public function test_missing_or_changed_body_references_are_still_blocked(): void
    {
        $url = 'https://docs.test/commit/abcdef?mode=full&lang=vi#rules';
        $source = (new ArticleSourceExtractor)->snapshot('<p>Read <a href="'.htmlspecialchars($url, ENT_QUOTES).'">the rules</a>.</p>', ['source_url' => 'https://source.test/article']);
        foreach (['', str_replace('abcdef', 'abcdf', $url), str_replace('full', 'short', $url), str_replace('#rules', '#other', $url)] as $changed) {
            try {
                (new ArticleQualityGate)->inspect($source, ['content_html' => '<p>Xem quy định '.($changed === '' ? '' : '<a href="'.htmlspecialchars($changed, ENT_QUOTES).'">tại đây</a>').'.</p>'], 'vi');
                $this->fail('A body reference cannot be omitted or approximated.');
            } catch (AiImportException $exception) {
                $this->assertSame('source_link_missing', $exception->diagnostics['validation_errors'][0]['reason']);
            }
        }
    }

    /** Input: các list không đủ dấu hiệu TOC và link body trùng TOC. Output: vẫn bắt buộc. */
    public function test_reference_lists_and_body_fragments_are_not_exempted(): void
    {
        $toc = '<ul><li><a href="#one">One</a></li><li><a href="#two">Two</a></li></ul>';
        foreach ([$toc, '<h1>Title</h1><p>Conditions apply.</p>'.$toc, '<h1>Title</h1><ul><li>Read <a href="#one">One</a> first.</li><li><a href="#two">Two</a></li></ul>', '<h1>Title</h1>'.str_replace('#two', 'https://other.test/page#two', $toc)] as $html) {
            $manifest = (new ArticleSourceLinkPolicy)->manifest(['content_html' => $html, 'source_url' => 'https://source.test/page']);
            $this->assertCount(2, $manifest['required_hrefs']);
            $this->assertSame([], $manifest['optional_navigation_hrefs']);
        }
        $manifest = (new ArticleSourceLinkPolicy)->manifest(['content_html' => '<h1>Title</h1>'.$toc.'<p>See <a href="#one">the condition</a>.</p>']);
        $this->assertSame(['#one'], $manifest['required_hrefs']);
        $this->assertSame(['#two'], $manifest['optional_navigation_hrefs']);
    }

    /** Input: nguồn có link taxonomy cùng site và link bài viết. Output: chỉ taxonomy được tùy chọn. */
    public function test_same_site_blog_taxonomy_links_are_optional_but_article_links_are_required(): void
    {
        $source = [
            'source_url' => 'https://example.test/blog/posts/travel/',
            'content_html' => '<p><a href="https://example.test/blog/tag/travel/">Travel</a></p>'
                .'<p><a href="https://example.test/blog/category/guides/">Guides</a></p>'
                .'<p><a href="https://example.test/blog/">Blog</a></p>'
                .'<p><a href="https://example.test/blog/posts/other/">Related article</a></p>'
                .'<p><a href="https://other.test/blog/tag/travel/">External taxonomy</a></p>',
        ];

        $manifest = (new ArticleSourceLinkPolicy)->manifest($source);

        $this->assertSame([
            'https://example.test/blog/posts/other/',
            'https://other.test/blog/tag/travel/',
        ], $manifest['required_hrefs']);
        $this->assertSame([
            'https://example.test/blog/tag/travel/',
            'https://example.test/blog/category/guides/',
            'https://example.test/blog/',
        ], $manifest['optional_navigation_hrefs']);
    }

    /** Input: entity/quote/unquoted/code. Output: parser DOM nhận href thực, không code mẫu. */
    public function test_href_parser_decodes_entities_and_handles_unquoted_attributes(): void
    {
        $this->assertSame(['https://docs.test/?a=1&b=2', 'https://example.test'], (new ArticleSourceLinkPolicy)->hrefs('<a href="https://docs.test/?a=1&amp;b=2">A</a><a href=https://example.test>B</a><pre>&lt;a href="https://code.test"&gt;</pre>'));
    }

    /** Input: Q1–Q4 và quý I–IV. Output: tương đương được phép, đổi quý vẫn bị chặn. */
    public function test_roman_quarters_are_equivalent_but_changed_quarters_fail(): void
    {
        foreach ([1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV'] as $number => $roman) {
            $text = 'The release is planned for Q'.$number.'.';
            $source = (new ArticleSourceExtractor)->snapshot('<p>'.$text.'</p>', []);
            $analysis = ['knowledge' => ['facts' => [['important' => true, 'evidence' => $text]]]];
            $checks = (new ArticleQualityGate)->inspect($source, ['content_html' => '<p>Dự kiến phát hành vào quý '.$roman.'.</p>'], 'vi', $analysis);
            $this->assertSame('pass', array_column($checks, 'status', 'check')['important_numbers']);
            try {
                (new ArticleQualityGate)->inspect($source, ['content_html' => '<p>Dự kiến phát hành vào quý '.($roman === 'I' ? 'II' : 'I').'.</p>'], 'vi', $analysis);
                $this->fail('A changed quarter must fail.');
            } catch (AiImportException $exception) {
                $this->assertSame('important_number_missing', $exception->diagnostics['validation_errors'][0]['reason']);
            }
        }
    }

    /** Input: Writer Q18-C trả S029 ngoài ledger. Output: không đổi/xóa ID để cho qua. */
    public function test_saved_writer_source_block_id_is_still_an_invalid_fact_reference(): void
    {
        $artifact = $this->artifact('pilot-2026-10-05-five-cases/Q18-C.json');
        $analysis = $artifact['calls'][0]['output'];
        (new ArticleEvidenceValidator)->analysis($analysis, $this->artifact('corpus-v1/Q18.json'));
        $this->assertSame(['S029'], array_values(array_diff($artifact['calls'][1]['output']['used_fact_ids'], array_column($analysis['knowledge']['facts'], 'id'))));
        try {
            (new ArticleEvidenceValidator)->references($artifact['calls'][1]['output']['used_fact_ids'], $analysis);
            $this->fail('Source block IDs cannot silently become fact IDs.');
        } catch (AiImportException $exception) {
            $this->assertSame('unknown_fact_reference', $exception->diagnostics['validation_errors'][0]['reason']);
        }
    }

    /** Input: tầng 1–9 dạng số/chữ. Output: tương đương đúng, số khác/số lớn vẫn fail. */
    public function test_storey_word_counts_keep_value_and_reject_partial_large_numbers(): void
    {
        foreach ([1 => 'một', 2 => 'hai', 3 => 'ba', 4 => 'bốn', 5 => 'năm', 6 => 'sáu', 7 => 'bảy', 8 => 'tám', 9 => 'chín'] as $number => $word) {
            $text = 'The suite has '.$number.' floors.';
            $source = (new ArticleSourceExtractor)->snapshot('<p>'.$text.'</p>', []);
            $analysis = ['knowledge' => ['facts' => [['important' => true, 'evidence' => $text]]]];
            $checks = (new ArticleQualityGate)->inspect($source, ['content_html' => '<p>Phòng suite có '.$word.' tầng.</p>'], 'vi', $analysis);
            $this->assertSame('pass', array_column($checks, 'status', 'check')['important_numbers']);
            $changed = $word === 'hai' ? 'ba' : 'hai';
            foreach ([$changed.' tầng', 'mười '.$word.' tầng', 'hai mươi '.$word.' tầng', 'một trăm lẻ '.$word.' tầng', 'hai phẩy '.$word.' tầng', 'hai chấm '.$word.' tầng', $word.' tầng rưỡi', $word.' tầng và một nửa'] as $wrong) {
                try {
                    (new ArticleQualityGate)->inspect($source, ['content_html' => '<p>Phòng suite có '.$wrong.'.</p>'], 'vi', $analysis);
                    $this->fail('A changed value or component of a large word number must not match.');
                } catch (AiImportException $exception) {
                    $this->assertSame('important_number_missing', $exception->diagnostics['validation_errors'][0]['reason']);
                }
            }
        }
    }

    /** Input: Q18-C 2.2 mới, có hai tầng và LED 80-inch. Output: giữ nguyên, alias đúng. */
    public function test_new_q18_candidate_keeps_two_storeys_and_passes_without_rewriting_it(): void
    {
        $artifact = $this->artifact('pilot-2026-10-05-prompt22/Q18-C.json');
        $this->assertSame('failed', $artifact['status']);
        $this->assertSame('important_number_missing', $artifact['diagnostics']['validation_errors'][0]['reason']);
        $this->assertStringContainsString('hai tầng', $artifact['candidate_for_review']['content_html']);
        $this->assertStringContainsString('80-inch', $artifact['candidate_for_review']['content_html']);
        $checks = (new ArticleQualityGate)->inspect($this->artifact('corpus-v1/Q18.json'), $artifact['candidate_for_review'], 'vi', $artifact['calls'][0]['output']);
        $this->assertSame('pass', array_column($checks, 'status', 'check')['important_numbers']);
        $this->assertSame('pass', array_column($checks, 'status', 'check')['source_code_links']);
    }

    /** Input: replay Analyzer/Writer thật dưới 2.2. Output: schema chặn S029 trước Editor. */
    public function test_new_writer_schema_stops_unknown_ids_before_editor(): void
    {
        $artifact = $this->artifact('pilot-2026-10-05-five-cases/Q18-C.json');
        $requests = [];
        $provider = $this->provider(array_column($artifact['calls'], 'output'), $requests);
        try {
            (new ArticleGenerationPipeline)->run(new AiImport(['input_json' => ['language' => 'vi', 'pipeline_snapshot' => config('ai.content')]]), $provider, $this->artifact('corpus-v1/Q18.json'), ['title', 'content']);
            $this->fail('An unknown fact ID must stop before the editor call.');
        } catch (AiImportException $exception) {
            $this->assertSame('AI_PROVIDER_SCHEMA', $exception->errorCode);
            $this->assertSame('invalid_value', $exception->diagnostics['validation_errors'][0]['reason']);
            $this->assertCount(2, $requests);
            $this->assertSame(array_column($artifact['calls'][0]['output']['knowledge']['facts'], 'id'), $requests[1]->schema['properties']['used_fact_ids']['items']['enum']);
        }
    }

    /** Input: facts/images cho Writer/Editor. Output: enum đúng namespace, không cho ảnh lạ. */
    public function test_reference_schema_accepts_known_facts_and_only_supplied_images(): void
    {
        $context = $this->context();
        $context['analysis'] = ['knowledge' => ['facts' => [['id' => 'F1']]]];
        $builder = new ArticlePromptBuilder;
        $schema = $builder->schema('article.writer', ['content'], config('ai.content'), $context);
        $output = ['draft' => ['content_html' => '<p>Nội dung.</p>'], 'used_fact_ids' => ['F1'], 'used_asset_ids' => ['I1']];
        $this->assertSame($output, (new AiTaskSchemaValidator)->validate($output, $schema));
        $this->assertSame(['F1'], $builder->schema('article.editor', ['content'], config('ai.content'), $context)['properties']['used_fact_ids']['items']['enum']);
        $context['images'] = [];
        $schema = $builder->schema('article.writer', ['content'], config('ai.content'), $context);
        $this->assertSame(0, $schema['properties']['used_asset_ids']['maxItems']);
        $this->expectException(AiImportException::class);
        (new AiTaskSchemaValidator)->validate($output, $schema);
    }

    /** Input: Analyzer đặt fact ID S029/I1. Output: chặn ngay, không gọi Writer/Editor. */
    public function test_analysis_cannot_name_facts_with_source_or_image_ids(): void
    {
        $artifact = $this->artifact('pilot-2026-10-05-five-cases/Q18-C.json');
        foreach (['S029', 'I1'] as $id) {
            $analysis = $artifact['calls'][0]['output'];
            $analysis['knowledge']['facts'][0]['id'] = $id;
            $requests = [];
            $provider = $this->provider([$analysis], $requests);
            try {
                (new ArticleGenerationPipeline)->run(new AiImport(['input_json' => ['language' => 'vi', 'pipeline_snapshot' => config('ai.content')]]), $provider, $this->artifact('corpus-v1/Q18.json'), ['title', 'content']);
                $this->fail('Fact IDs must remain distinct from block and image IDs.');
            } catch (AiImportException $exception) {
                $this->assertSame('AI_PROVIDER_SCHEMA', $exception->errorCode);
                $this->assertSame('invalid_value', $exception->diagnostics['validation_errors'][0]['reason']);
                $this->assertCount(1, $requests);
            }
        }
    }

    /** Input: replay Q02-C. Output: manifest tới mọi bước, source/facts/draft không bị cắt. */
    public function test_link_contract_reaches_analyze_write_and_edit_without_mutating_source(): void
    {
        $source = $this->artifact('corpus-v1/Q02.json');
        $original = $source;
        $artifact = $this->artifact('pilot-2026-10-05-five-cases/Q02-C.json');
        $requests = [];
        $provider = $this->provider(array_column($artifact['calls'], 'output'), $requests);
        $result = (new ArticleGenerationPipeline)->run(new AiImport(['input_json' => ['language' => 'vi', 'pipeline_snapshot' => config('ai.content')]]), $provider, $source, ['title', 'content']);
        $manifest = (new ArticleSourceLinkPolicy)->manifest($source);
        foreach ($requests as $request) {
            $this->assertSame($manifest, $request->input['link_requirements']);
            $this->assertSame($source['blocks'], $request->input['source']['blocks']);
            $this->assertStringContainsString('never source block IDs or image IDs', $request->systemInstructions);
        }
        $this->assertSame($artifact['calls'][0]['output'], $requests[1]->input['analysis']);
        $this->assertSame($artifact['calls'][1]['output']['draft'], $requests[2]->input['draft']);
        $this->assertSame($artifact['candidate_for_review']['content_html'], $result['content_html']);
        $this->assertSame($original, $source);
    }

    /** Input: CLI vẫn yêu cầu B. Output: từ chối trước thực thi hoặc tạo artifacts. */
    public function test_removed_branch_is_rejected_by_cli_before_execution(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', base_path('scripts/ai-quality/evaluate.php'), '--case=Q07', '--arms=B', '--run', '--max-calls=1'], base_path());
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('luồng B đã được gỡ', $process->getErrorOutput());
        $this->assertStringNotContainsString('"mode":"execute"', $process->getOutput());
    }

    /** Input: artifact B lịch sử. Output: vẫn đọc được đúng output/hash, không chạy model. */
    public function test_saved_single_step_output_remains_readable_offline(): void
    {
        $artifact = $this->artifact('pilot-2026-10-05-five-cases/Q07-B.json');
        $this->assertSame('B', $artifact['arm']);
        $this->assertCount(1, $artifact['calls']);
        $this->assertNotEmpty($artifact['candidate_for_review']['content_html']);
        $this->assertSame(ArticleInputHasher::hash(array_replace($this->artifact('corpus-v1/Q07.json'), ['inline_image_refs' => [], 'snapshot_run_id' => ''])), $artifact['source_sha256']);
    }

    /** Input: corpus checkout và bản tạm bị đổi title. Output: hash gate chạy trước AI. */
    public function test_preflight_accepts_checkout_newlines_but_rejects_changed_source(): void
    {
        $command = [PHP_BINARY, '-d', 'xdebug.mode=off', base_path('scripts/ai-quality/evaluate.php'), '--case=Q02'];
        $process = new Process($command, base_path());
        $process->mustRun();
        $this->assertSame('preflight', json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR)['mode']);
        $sourcePath = tempnam(sys_get_temp_dir(), 'aiq-source-');
        $manifestPath = tempnam(sys_get_temp_dir(), 'aiq-manifest-');
        try {
            $source = $this->artifact('corpus-v1/Q02.json');
            $source['title'] = 'Tampered source title';
            file_put_contents($sourcePath, json_encode($source, JSON_THROW_ON_ERROR));
            $original = $this->artifact('corpus-v1/manifest.json');
            $case = array_values(array_filter($original['cases'], fn (array $case): bool => $case['case_id'] === 'Q02'))[0];
            $case['source_snapshot_path'] = basename($sourcePath);
            file_put_contents($manifestPath, json_encode(['cases' => [$case]], JSON_THROW_ON_ERROR));
            $process = new Process(array_merge($command, ['--manifest='.$manifestPath]), base_path());
            $process->run();
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString('Source hash/path không hợp lệ: Q02', $process->getOutput().$process->getErrorOutput());
            $this->assertStringNotContainsString('"mode":"execute"', $process->getOutput());
        } finally {
            unlink($sourcePath);
            unlink($manifestPath);
        }
    }

    /** Input: snapshots 2.0/2.1, hashes chụp trước khi sửa. Output: bytes request bất biến. */
    public function test_old_snapshot_requests_keep_their_original_hashes(): void
    {
        $hashes = [
            '2.0' => ['90c28d9fcbda82ce1e2e0f5545603dd4206e4a04161ff182e97050d7eadbd385', '89db3bbe7f701e62c3e6a3df298e5feed64b913854f8d752285ae0824f260842', '28c40d1893d8c9353153fcff9d2a1cbd0795afd391310cf974217cec5be5659d'],
            '2.1' => ['12c9e4ed8b11b6a501350abf386cea54f7b93f1342e32d2022a523a1ce7da96a', 'e11657c8a20f5d0a1a96dd5e3b1d45b50099e4f19f4ac819be9ec85dd9327844', 'b73cf93f1258b99b99703be5283b0028e6f3add15f1bd2f5a672b6ab4da9ceab'],
        ];
        foreach ($hashes as $version => $expected) {
            $settings = $this->artifact('pilot-2026-10-05-prompt21/experiment-input.json')['pipeline_snapshot'];
            $settings['prompt_version'] = $version;
            $context = $this->context();
            foreach (['article.analysis-plan', 'article.writer', 'article.editor'] as $index => $task) {
                if ($task === 'article.writer') {
                    $context['analysis'] = ['knowledge' => ['facts' => [['id' => 'F1', 'important' => true]]]];
                }
                if ($task === 'article.editor') {
                    $context['draft'] = ['content_html' => '<p>Bản 2.0 nhận 100 phần tử.</p>'];
                }
                $request = (new ArticlePromptBuilder)->build($task, $context, ['content'], $settings);
                $this->assertSame($expected[$index], ArticleInputHasher::hash($request));
                $this->assertArrayNotHasKey('link_requirements', $request->input);
            }
        }
    }
}
