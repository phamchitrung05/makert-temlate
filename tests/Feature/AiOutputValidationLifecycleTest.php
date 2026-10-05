<?php

namespace Tests\Feature;

use App\Jobs\ProcessAiImageGenerationJob;
use App\Jobs\ProcessAiImportJob;
use App\Models\AiImport;
use App\Models\AiProvider;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Ai\Content\ArticleImportService;
use App\Services\Ai\Content\ArticleSourceExtractor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ArticlePipelineFixture;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Regression queue baseline một lượt và lifecycle validation candidate.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp().
 * - tearDown().
 * - test_missing_or_blank_selected_output_fails_without_source_fallback_or_retry().
 * - invalidSelectedOutputs().
 * - test_empty_content_after_sanitize_fails_before_thumbnail_download().
 * - test_selected_only_valid_output_becomes_ready_and_preserves_manual_and_source_fields().
 * - test_success_and_failure_diagnostics_are_saved_without_exposing_internal_metadata().
 * - test_incomplete_response_fails_even_when_json_content_is_valid_and_keeps_diagnostics().
 * - test_partial_seo_regeneration_preserves_parent_values_not_returned_by_ai().
 * - test_failed_regeneration_preserves_parent_and_exposes_only_the_child_error().
 * - test_thumbnail_only_uses_source_without_text_ai_and_missing_image_is_optional().
 * - thumbnailSources().
 * - createRun().
 * - readyParent().
 * - regenerate().
 * - process().
 * - response().
 * - sourceHtml().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : fixtures source/JSON và cấu hình test đã cô lập.
 * - OUTPUT: assertions contract; không gọi AI thật hoặc ghi database development.
 * =====================================================================
 */
final class AiOutputValidationLifecycleTest extends TestCase
{
    use UsesIsolatedDatabase;

    private string $token;

    private AiProvider $provider;

    private int $modelId;

    /**
     * =====================================================================
     * Input: Không có; PHPUnit gọi trước mỗi ca.
     * Output: Database riêng, quyền và HTTP/Queue/Storage fake; không gọi AI thật.
     * =====================================================================
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('media_public');
        Storage::fake('media_private');
        config([
            'media-library.asset_disks.public' => 'media_public',
            'media-library.asset_disks.private' => 'media_private',
            'media-assets.temporary_disk' => 'media_private',
            'ai-providers.allowed_hosts' => [],
            'queue.default' => 'database',
        ]);
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo(['posts.manage', 'media.upload']);
        $this->flushPermissionCache();
        Auth::forgetGuards();
        $this->token = $actor->createToken('ai-validation-test', ['admin'])->plainTextToken;
        $this->provider = AiProvider::create([
            'key' => 'validation-gateway', 'name' => 'Validation gateway',
            'driver' => 'openai-compatible', 'kind' => 'gateway',
            'base_url' => 'https://validation-provider.test/v1',
            'api_key' => 'private-validation-key', 'is_active' => true,
            'discovery_mode' => 'manual',
        ]);
        $this->modelId = $this->provider->models()->create([
            'remote_model_id' => 'selected-text-model', 'label' => 'Selected text model',
            'capabilities' => ['text_generation', 'structured_output'],
        ])->id;
    }

    /**
     * =====================================================================
     * Input: Không có; PHPUnit gọi sau mỗi ca.
     * Output: Dọn connection database test và dependency; không đụng dữ liệu development.
     * =====================================================================
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    #[DataProvider('invalidSelectedOutputs')]
    /**
     * =====================================================================
     * Input: Nhóm field/output lỗi từ data provider.
     * Output: Assertions candidate failed, không merge nguồn che field thiếu hoặc retry HTTP.
     * =====================================================================
     */
    public function test_missing_or_blank_selected_output_fails_without_source_fallback_or_retry(
        array $groups,
        array $output,
        string $errorCode,
        string $missingField,
    ): void {
        Http::fake(['https://validation-provider.test/*' => fn ($request) => Http::response($this->response(ArticlePipelineFixture::httpOutput($request, $output)))]);
        $run = $this->createRun($groups);

        $this->process($run);

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame($errorCode, $run->error_code);
        $this->assertNull(data_get($run->result_json, 'draft'));
        $errors = data_get($run->source_meta_json, 'ai_response.validation_errors', []);
        $this->assertTrue(collect($errors)->contains(fn (array $error): bool => $errorCode === 'AI_PROVIDER_SCHEMA'
            ? $error['field'] === 'task_output' && $error['reason'] === 'missing'
            : str_ends_with($error['field'], $missingField)));
        $this->assertDatabaseCount('media_assets', 0);
        Http::assertSentCount(in_array('content', $groups, true) ? 2 : 1);
        Queue::assertPushed(ProcessAiImportJob::class, 1);
        Queue::assertNotPushed(ProcessAiImageGenerationJob::class);
        $this->withToken($this->token)->getJson('/api/admin/ai-agent/sessions/'.$run->id)
            ->assertOk()->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_code', $errorCode);
    }

    /**
     * =====================================================================
     * Input: Không có.
     * Output: Fixtures field missing/null/blank/unselected cho regression contract.
     * =====================================================================
     */
    public static function invalidSelectedOutputs(): array
    {
        return [
            'content missing despite valid source' => [
                ['title', 'content'], ['title' => 'Generated title'],
                'AI_PROVIDER_SCHEMA', 'content_html',
            ],
            'selected title is null' => [
                ['title'], ['title' => null], 'AI_PROVIDER_MISSING_FIELDS', 'title',
            ],
            'selected title contains only whitespace' => [
                ['title'], ['title' => " \n\t\u{00A0}"], 'AI_PROVIDER_EMPTY_CONTENT', 'title',
            ],
            'provider returned only unselected fields' => [
                ['excerpt'], ['title' => 'Not requested', 'content_html' => '<p>Not requested.</p>'],
                'AI_PROVIDER_MISSING_FIELDS', 'excerpt',
            ],
        ];
    }

    /**
     * =====================================================================
     * Input: HTML AI có script và whitespace cùng thumbnail nguồn.
     * Output: Assertions lỗi content rỗng xảy ra trước tạo/tải thumbnail.
     * =====================================================================
     */
    public function test_empty_content_after_sanitize_fails_before_thumbnail_download(): void
    {
        Http::fake([
            'https://source.test/article' => Http::response($this->sourceHtml(), 200, ['Content-Type' => 'text/html']),
            'https://validation-provider.test/*' => fn ($request) => Http::response($this->response(ArticlePipelineFixture::httpOutput($request, [
                'content_html' => '<script>unsafe()</script><p>&nbsp; &#160; </p>',
            ]))),
        ]);
        $run = $this->createRun(['content', 'thumbnail'], [
            'input' => ['type' => 'url', 'url' => 'https://source.test/article'],
        ]);

        $this->process($run);

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('AI_PROVIDER_EMPTY_CONTENT', $run->fresh()->error_code);
        $this->assertDatabaseCount('media_assets', 0);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://source.test/cover.png');
        Http::assertSentCount(4);
        Queue::assertNotPushed(ProcessAiImageGenerationJob::class);
    }

    /**
     * =====================================================================
     * Input: Output excerpt hợp lệ và field ngoài lựa chọn sai kiểu.
     * Output: Assertions ready, giữ title nhập tay và nội dung nguồn.
     * =====================================================================
     */
    public function test_selected_only_valid_output_becomes_ready_and_preserves_manual_and_source_fields(): void
    {
        Http::fake(['https://validation-provider.test/*' => Http::response($this->response([
            'excerpt' => 'Generated summary.', 'title' => ['invalid but unselected'],
            'content_html' => ['invalid but unselected'],
        ]))]);
        $run = $this->createRun(['excerpt']);

        $this->process($run);

        $draft = $run->fresh()->result_json['draft'];
        $this->assertSame('ready', $run->fresh()->status);
        $this->assertSame('Manual title', $draft['title']);
        $this->assertSame('Generated summary.', $draft['excerpt']);
        $this->assertStringContainsString('Source content must be preserved.', $draft['content_html']);
        $this->assertSame($draft['content_html'], $draft['content']);
        Http::assertSentCount(1);
    }

    /**
     * =====================================================================
     * Input: HTTP envelope chứa diagnostics hợp lệ và secret cố tình cài vào.
     * Output: Assertions lưu token/ID an toàn, API không lộ secret/raw metadata.
     * =====================================================================
     */
    public function test_success_and_failure_diagnostics_are_saved_without_exposing_internal_metadata(): void
    {
        $secret = 'private-validation-key';
        $success = $this->response([
            'excerpt' => 'Safe generated summary.', 'unknown-'.$secret => $secret,
        ], ['authorization' => 'Bearer '.$secret, 'debug_body' => $secret]);
        $failure = $this->response(['excerpt' => ['raw_secret_value' => $secret]], [
            'authorization' => 'Bearer '.$secret, 'debug_body' => $secret,
        ]);
        Http::fake(['https://validation-provider.test/*' => Http::sequence()->push($success)->push($failure)]);
        $valid = $this->createRun(['excerpt']);
        $valid->update(['source_meta_json' => ['retained_source_metadata' => 'keep-me']]);
        $this->process($valid);
        $invalid = $this->createRun(['excerpt'], ['input' => ['type' => 'text', 'text' => 'A distinct source for the second run.']]);
        $this->process($invalid);

        $this->assertSame('ready', $valid->fresh()->status);
        $this->assertSame('keep-me', $valid->fresh()->source_meta_json['retained_source_metadata']);
        $this->assertSame('failed', $invalid->fresh()->status);
        $this->assertSame('AI_PROVIDER_SCHEMA', $invalid->fresh()->error_code);
        foreach ([$valid->fresh(), $invalid->fresh()] as $run) {
            $metadata = $run->source_meta_json['ai_response'];
            $this->assertSame('response-validation-123', $metadata['response_id']);
            $this->assertSame('reported-text-model', $metadata['reported_model']);
            $this->assertSame('stop', $metadata['finish_reason']);
            $this->assertSame(17, $metadata['usage']['prompt_tokens']);
            $this->assertSame(8, $metadata['usage']['completion_tokens']);
            $this->assertSame(['excerpt'], $metadata['requested_groups']);
            $this->assertContains('excerpt', $metadata['returned_fields']);
            $this->assertStringNotContainsString($secret, json_encode($run->source_meta_json));
            $this->assertArrayNotHasKey('authorization', $metadata);
            $this->assertArrayNotHasKey('debug_body', $metadata);
            $response = $this->withToken($this->token)->getJson('/api/admin/ai-agent/sessions/'.$run->id)->assertOk();
            $this->assertArrayNotHasKey('source_meta_json', $response->json('data'));
            $this->assertArrayNotHasKey('ai_response', $response->json('data'));
            // Task 2 công khai diagnostics allowlist; raw metadata/key vẫn phải được ẩn.
            $this->assertSame('response-validation-123', $response->json('data.response_diagnostics.response_id'));
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $errors = $invalid->fresh()->source_meta_json['ai_response']['validation_errors'];
        $this->assertContains('invalid_type', array_column($errors, 'reason'));
        $detail = $this->withToken($this->token)->getJson('/api/admin/ai-agent/sessions/'.$invalid->id)->assertOk();
        $this->assertNotEmpty($detail->json('data.error'));
        $this->assertNotEmpty($detail->json('data.validation_errors'));
        $list = $this->withToken($this->token)->getJson('/api/admin/ai-agent/sessions')->assertOk();
        $summary = collect($list->json('data'))->firstWhere('id', $invalid->id);
        $this->assertSame('AI_PROVIDER_SCHEMA', $summary['error_code']);
        $this->assertNotEmpty($summary['error']);
        $this->assertNotEmpty($summary['validation_errors']);
        $this->assertStringNotContainsString($secret, $list->getContent());
        $this->assertStringNotContainsString('response-validation-123', $list->getContent());
    }

    /**
     * =====================================================================
     * Input: Finish reason length với JSON vẫn parse được.
     * Output: Assertions failed incomplete, không nhận draft dở dang.
     * =====================================================================
     */
    public function test_incomplete_response_fails_even_when_json_content_is_valid_and_keeps_diagnostics(): void
    {
        $response = $this->response(['title' => 'Generated title', 'content_html' => '<p>Generated content.</p>']);
        $response['choices'][0]['finish_reason'] = 'length';
        Http::fake(['https://validation-provider.test/*' => Http::response($response)]);
        $run = $this->createRun(['title', 'content']);

        $this->process($run);

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('AI_PROVIDER_INCOMPLETE', $run->error_code);
        $this->assertSame('length', $run->source_meta_json['ai_response']['finish_reason']);
        $this->assertSame('response-validation-123', $run->source_meta_json['ai_response']['response_id']);
        $this->assertNull(data_get($run->result_json, 'draft'));
        Http::assertSentCount(1);
    }

    /**
     * =====================================================================
     * Input: Parent đã sửa và output SEO một phần.
     * Output: Assertions chỉ field SEO có giá trị mới thay đổi, parent giữ nguyên.
     * =====================================================================
     */
    public function test_partial_seo_regeneration_preserves_parent_values_not_returned_by_ai(): void
    {
        $parent = $this->readyParent();
        $before = $parent->result_json;
        Http::fake(['https://validation-provider.test/*' => Http::response($this->response([
            'seo_title' => 'Updated SEO title', 'robots_index' => false,
        ]))]);
        $child = $this->regenerate($parent, ['seo']);

        $this->process($child);

        $draft = $child->fresh()->result_json['draft'];
        $this->assertSame('ready', $child->fresh()->status);
        $this->assertSame('Updated SEO title', $draft['seo_title']);
        $this->assertFalse($draft['robots_index']);
        foreach (['title', 'content_html', 'excerpt', 'seo_description', 'canonical_url', 'robots_follow', 'thumbnail'] as $field) {
            $this->assertSame($before['draft'][$field], $draft[$field], $field.' must remain unchanged.');
        }
        $this->assertSame($before, $parent->fresh()->result_json);
        Http::assertSentCount(1);
    }

    /**
     * =====================================================================
     * Input: Parent ready và child thiếu content được yêu cầu.
     * Output: Assertions child failed, parent version/draft không mất và Apply bị chặn.
     * =====================================================================
     */
    public function test_failed_regeneration_preserves_parent_and_exposes_only_the_child_error(): void
    {
        $parent = $this->readyParent();
        $before = $parent->result_json;
        $version = $this->withToken($this->token)->getJson('/api/admin/ai-agent/sessions/'.$parent->id)->json('data.draft_version');
        Http::fake(['https://validation-provider.test/*' => fn ($request) => Http::response($this->response(ArticlePipelineFixture::httpOutput($request, ['title' => 'Unselected title'])))]);
        $child = $this->regenerate($parent, ['content']);

        $this->process($child);

        $this->assertSame('failed', $child->fresh()->status);
        $this->assertSame('AI_PROVIDER_SCHEMA', $child->fresh()->error_code);
        $this->assertSame($before, $parent->fresh()->result_json);
        $this->assertSame('ready', $parent->fresh()->status);
        $this->withToken($this->token)->getJson('/api/admin/ai-agent/sessions/'.$parent->id)->assertOk()
            ->assertJsonPath('data.draft_version', $version)->assertJsonPath('data.status', 'ready');
        $this->withToken($this->token)->getJson('/api/admin/ai-agent/sessions/'.$child->id)->assertOk()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', 'AI_PROVIDER_SCHEMA');
        $this->withToken($this->token)->postJson('/api/admin/ai-agent/candidates/'.$child->id.'/apply', [
            'fields' => ['content'],
        ])->assertStatus(409);
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('media_assets', 0);
        Http::assertSentCount(2);
        Queue::assertNotPushed(ProcessAiImageGenerationJob::class);
    }

    #[DataProvider('thumbnailSources')]
    /**
     * =====================================================================
     * Input: Cờ nguồn có ảnh và HTTP/storage fake.
     * Output: Assertions thumbnail-only không gọi AI text; bỏ qua ca cần GD khi extension thiếu.
     * =====================================================================
     */
    public function test_thumbnail_only_uses_source_without_text_ai_and_missing_image_is_optional(bool $hasImage): void
    {
        if ($hasImage && ! function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('PHP CLI chưa có GD để chạy regression download/convert thumbnail.');
        }
        Http::fake([
            'https://source.test/article' => Http::response($this->sourceHtml($hasImage), 200, ['Content-Type' => 'text/html']),
            'https://source.test/cover.png' => Http::response(
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
                200,
                ['Content-Type' => 'image/png'],
            ),
        ]);
        $run = $this->createRun(['thumbnail'], ['input' => ['type' => 'url', 'url' => 'https://source.test/article']]);

        $this->process($run);

        $run->refresh();
        $this->assertSame('ready', $run->status);
        $this->assertSame('Manual title', $run->result_json['draft']['title']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'validation-provider.test'));
        Http::assertSentCount($hasImage ? 2 : 1);
        if ($hasImage) {
            $asset = MediaAsset::query()->sole();
            $this->assertSame($asset->id, data_get($run->result_json, 'draft.thumbnail.media_asset_id'));
            $media = $asset->getFirstMedia('library');
            $this->assertNotNull($media);
            Storage::disk('media_public')->assertExists($media->getPathRelativeToRoot());
        } else {
            $this->assertNull(data_get($run->result_json, 'draft.thumbnail.media_asset_id'));
            $this->assertDatabaseCount('media_assets', 0);
        }
        Queue::assertNotPushed(ProcessAiImageGenerationJob::class);
    }

    /**
     * =====================================================================
     * Input: Không có.
     * Output: Hai fixtures nguồn có/không có thumbnail.
     * =====================================================================
     */
    public static function thumbnailSources(): array
    {
        return ['source has image' => [true], 'source has no image' => [false]];
    }

    /**
     * =====================================================================
     * Input: Nhóm field và overrides API payload.
     * Output: AiImport queued từ endpoint thật; Queue fake chặn worker tự chạy.
     * =====================================================================
     */
    private function createRun(array $groups, array $overrides = []): AiImport
    {
        $id = $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions', array_replace([
            'target_type' => 'post', 'provider' => $this->provider->key, 'model_id' => $this->modelId,
            'input' => ['type' => 'text', 'text' => "Source title\n\nSource content must be preserved."],
            'title' => 'Manual title', 'requested_outputs' => $groups, 'thumbnail_mode' => 'source',
        ], $overrides))->assertAccepted()->json('data.job_id');

        return AiImport::findOrFail($id);
    }

    /**
     * =====================================================================
     * Input: Không có.
     * Output: Parent candidate ready với các field đã biên tập trong database test.
     * =====================================================================
     */
    private function readyParent(): AiImport
    {
        $parent = $this->createRun(['title', 'content', 'seo']);
        $parent->update(['status' => 'ready', 'source_meta_json' => ['article_source' => (new ArticleSourceExtractor)->snapshot('<p>Source content must be preserved.</p>', ['source_url' => 'inline://'.$parent->id, 'title' => 'Source title'])], 'result_json' => ['draft' => [
            'title' => 'Edited parent title', 'content_html' => '<p>Edited parent content.</p>',
            'content' => '<p>Edited parent content.</p>', 'excerpt' => 'Edited parent excerpt.',
            'seo_title' => 'Previous SEO title', 'seo_description' => 'Edited parent SEO description.',
            'canonical_url' => 'https://canonical.test/edited-parent', 'robots_index' => true, 'robots_follow' => false,
            'thumbnail' => ['media_asset_id' => null, 'source_url' => null, 'alt_text' => 'Edited thumbnail alt'],
        ]]]);

        return $parent->fresh();
    }

    /**
     * =====================================================================
     * Input: Parent và nhóm field được chọn.
     * Output: Child queued qua API thật; không thay parent.
     * =====================================================================
     */
    private function regenerate(AiImport $parent, array $groups): AiImport
    {
        $id = $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions/'.$parent->id.'/regenerate', [
            'fields' => $groups,
        ])->assertAccepted()->json('data.job_id');

        return AiImport::findOrFail($id);
    }

    /**
     * =====================================================================
     * Input: Run trong database test.
     * Output: Gọi job trực tiếp với service thật và HTTP fake; ghi lifecycle test.
     * =====================================================================
     */
    private function process(AiImport $run): void
    {
        (new ProcessAiImportJob($run->id))->handle(app(ArticleImportService::class));
    }

    /**
     * =====================================================================
     * Input: Output canonical và envelope overrides.
     * Output: Response OpenAI stop giả lập có token/ID để kiểm diagnostics.
     * =====================================================================
     */
    private function response(array $output, array $overrides = []): array
    {
        return array_replace([
            'id' => 'response-validation-123', 'model' => 'reported-text-model',
            'usage' => ['prompt_tokens' => 17, 'completion_tokens' => 8, 'total_tokens' => 25, 'unknown' => 'private-validation-key'],
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($output, JSON_THROW_ON_ERROR)]]],
        ], $overrides);
    }

    /**
     * =====================================================================
     * Input: Cờ nguồn có thumbnail.
     * Output: HTML source cố định; không đọc network hoặc filesystem.
     * =====================================================================
     */
    private function sourceHtml(bool $hasImage = true): string
    {
        return '<html><head><title>Source title</title>'
            .($hasImage ? '<meta property="og:image" content="https://source.test/cover.png">' : '')
            .'</head><body><article><p>Source content must be preserved.</p></article></body></html>';
    }
}
