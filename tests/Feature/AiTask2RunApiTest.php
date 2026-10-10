<?php

namespace Tests\Feature;

use App\Jobs\ProcessAiImportJob;
use App\Models\AiImport;
use App\Models\AiImportStep;
use App\Models\AiWritingProfile;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Services\Ai\Content\ArticleImportService;
use App\Services\Ai\Providers\Adapters\DeterministicAiProvider;
use App\Services\Ai\Runs\AiRunBudget;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm API backend Task 2 về snapshot, taxonomy, version và lifecycle.
 * =====================================================================
 *
 * PHPUnit kiểm snapshot nguồn/profile/brief, taxonomy thủ công, version candidate
 * và lifecycle Task 2. Gồm queue budget, contract ba bước và phục hồi metadata ảnh.
 * HTTP/queue và database được cô lập; không gọi AI hoặc sửa dữ liệu ứng dụng.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp().
 * - tearDown().
 * - token().
 * - profile().
 * - createRun().
 * - test_create_snapshots_profile_brief_and_manual_taxonomy().
 * - test_ai_taxonomy_generation_is_rejected().
 * - test_html_source_preview_preserves_code_inside_form().
 * - test_html_file_preview_selects_div_article_body_instead_of_footer_section().
 * - test_html_run_and_ambiguous_sources_contract().
 * - test_regenerate_keeps_parent_profile_and_source_snapshot().
 * - test_step_polling_hides_intermediate_output_and_secrets().
 * - test_new_running_stages_cannot_be_deleted_and_cancel_is_terminal().
 * - test_three_step_queue_budget_is_inside_queue_lease().
 * - test_new_run_forces_three_steps_even_with_legacy_configuration().
 * - test_regenerate_preserves_edited_manual_taxonomy_and_clears_legacy_ai_taxonomy().
 * - test_apply_checks_candidate_version_and_uses_manual_taxonomy().
 * - test_missing_snapshot_requires_explicit_refresh().
 * - test_sync_queue_is_rejected_before_run_creation().
 * - test_optional_image_metadata_does_not_overwrite_ready_candidate_edits().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Fixtures, HTTP request và dependency test đã cô lập.
 * - OUTPUT: Assertions cho output, quyền, validation và lifecycle hiện có.
 * - SIDE EFFECT: Tạo/đọc/sửa dữ liệu ở DB test; setup/teardown quản lý schema và connection riêng.
 * - EXCEPTION/TRANSACTION: Lỗi assertion/dependency truyền ra PHPUnit; transaction code nghiệp vụ chạy trong môi trường test.
 * =====================================================================
 */
class AiTask2RunApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị môi trường cô lập trước mỗi ca kiểm thử
     * =====================================================================
     *
     * INPUT:
     * - PHPUnit setup.
     *
     * OUTPUT:
     * - DB cô lập/quyền/queue fake.
     *
     * SIDE EFFECT:
     * - migrate SQLite, không tác động database ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi setup/schema truyền ra PHPUnit; không mở transaction nghiệp vụ bao toàn bộ ca test.
     *
     * =====================================================================
     */
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('queue.default', 'database');
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();
        Http::preventStrayRequests();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn môi trường cô lập sau mỗi ca kiểm thử
     * =====================================================================
     *
     * INPUT:
     * - PHPUnit teardown.
     *
     * OUTPUT:
     * - dọn database cô lập.
     *
     * SIDE EFFECT:
     * - rollback test, không ghi production.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi teardown truyền ra PHPUnit; không gọi provider hoặc mở transaction nghiệp vụ mới.
     *
     * =====================================================================
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị actor và token admin cho request kiểm thử
     * =====================================================================
     *
     * INPUT:
     * - không có.
     *
     * OUTPUT:
     * - token actor posts.manage.
     *
     * SIDE EFFECT:
     * - chỉ tạo user/token trong DB test.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    private function token(): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('posts.manage');
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('task2', ['admin'])->plainTextToken;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo writing profile fixture trong database test
     * =====================================================================
     *
     * INPUT:
     * - không có.
     *
     * OUTPUT:
     * - mẫu bật version 1 đã được người dùng lưu.
     *
     * SIDE EFFECT:
     * - chỉ ghi fixture profile, không phân tích bài mẫu.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    private function profile(): AiWritingProfile
    {
        return AiWritingProfile::query()->create([
            'name' => 'Trực tiếp', 'rules_json' => ['tone' => 'Trực tiếp'], 'evidence_json' => [],
            'style_instructions' => 'Viết tự nhiên, giữ nguyên code.', 'version' => 1,
            'origin' => 'manual', 'is_enabled' => true,
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo AI run qua API để kiểm snapshot và lifecycle
     * =====================================================================
     *
     * INPUT:
     * - token và options override.
     *
     * OUTPUT:
     * - run đã queued.
     *
     * SIDE EFFECT:
     * - gọi API nội bộ; Queue fake không chạy generation.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    private function createRun(string $token, array $options = []): AiImport
    {
        $response = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', array_replace([
            'text' => 'Nguồn hướng dẫn giải thích tính năng thật.', 'generate_thumbnail' => false,
            'provider' => 'deterministic',
            'requested_outputs' => ['content'],
        ], $options))->assertStatus(202);

        return AiImport::query()->findOrFail($response->json('data.job_id'));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử profile/brief/category/tag thủ công
     * =====================================================================
     *
     * INPUT:
     * - profile/brief/category/tag thủ công.
     *
     * OUTPUT:
     * - snapshot và draft public giữ IDs/manual.
     *
     * SIDE EFFECT:
     * - API/queue fake; không gọi AI hoặc tạo Post.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_create_snapshots_profile_brief_and_manual_taxonomy(): void
    {
        $token = $this->token();
        $profile = $this->profile();
        $category = Category::query()->create(['name' => 'Dev', 'status' => 'active']);
        $tag = Tag::query()->create(['name' => 'PHP', 'status' => 'active']);
        $run = $this->createRun($token, [
            'writing_profile_id' => $profile->id, 'writing_brief' => ['audience' => 'Người mới'],
            'category_ids' => [$category->id], 'tag_ids' => [$tag->id],
        ]);
        $this->assertSame('Người mới', data_get($run->input_json, 'writing_brief.audience'));
        $this->assertSame(1, data_get($run->input_json, 'writing_profile_snapshot.version'));
        $this->assertSame([$category->id], $run->input_json['category_ids']);
        $this->assertSame('manual', $run->input_json['taxonomy_origin']);
        $draft = (new ArticleImportService(new DeterministicAiProvider))->run($run)['draft'];
        $this->assertSame('manual', $draft['taxonomy_origin']);
        $this->assertSame([$category->id], $draft['category_ids']);
        $this->assertSame([$tag->id], $draft['tag_ids']);
        $profile->update(['version' => 2, 'style_instructions' => 'Đã đổi']);
        $this->assertSame('Viết tự nhiên, giữ nguyên code.', data_get($run->fresh()->input_json, 'writing_profile_snapshot.style_instructions'));
        Queue::assertPushed(ProcessAiImportJob::class);
        $this->assertDatabaseCount('posts', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử aI taxonomy generation được yêu cầu
     * =====================================================================
     *
     * INPUT:
     * - AI taxonomy generation được yêu cầu.
     *
     * OUTPUT:
     * - 422 thay vì gửi taxonomy vào model.
     *
     * SIDE EFFECT:
     * - không tạo run/provider request.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_ai_taxonomy_generation_is_rejected(): void
    {
        $this->withToken($this->token())->postJson('/api/admin/ai-agent/sessions', [
            'text' => 'Nguồn', 'requested_outputs' => ['taxonomy'],
        ])->assertStatus(422);
        $this->assertDatabaseCount('ai_imports', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử hTML/form/code và script
     * =====================================================================
     *
     * INPUT:
     * - HTML/form/code và script.
     *
     * OUTPUT:
     * - preview giữ code/form nhưng bỏ script/footer.
     *
     * SIDE EFFECT:
     * - không tạo run hoặc gọi model/URL.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_html_source_preview_preserves_code_inside_form(): void
    {
        $response = $this->withToken($this->token())->postJson('/api/admin/ai-agent/source-preview', [
            'html' => "<html><body><form><article><h1>Guide</h1><pre>if (true) {\n    run();\n}</pre><p>Nội dung cần giữ.</p></article></form><footer>Footer</footer><script>evil()</script></body></html>",
        ])->assertOk();
        $html = $response->json('data.content_html');
        $this->assertStringContainsString("\n    run();\n", $html);
        $this->assertStringContainsString('Nội dung cần giữ.', $html);
        $this->assertStringNotContainsString('evil', $html);
        $this->assertStringNotContainsString('Footer', $html);
        $this->assertNotEmpty($response->json('data.blocks'));
        $this->assertDatabaseCount('ai_imports', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử file HTML có bài trong div/form và footer copyright dùng section
     * =====================================================================
     *
     * INPUT:
     * - file HTML có bài trong div/form và footer copyright dùng section.
     *
     * OUTPUT:
     * - upload preview trả đầu/cuối bài và source blocks, không trả footer.
     *
     * SIDE EFFECT:
     * - chỉ upload tạm trong test; không gọi HTTP/model hoặc tạo run.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_html_file_preview_selects_div_article_body_instead_of_footer_section(): void
    {
        $file = UploadedFile::fake()->createWithContent('saved-article.html',
            '<html><head><title>Bài du thuyền</title></head><body><form>'
            .'<div id="article-content"><p>Đầu bài: du thuyền Ovation của Royal Caribbean.</p>'
            .'<p>Cuối bài: tám tiện ích trên du thuyền.</p>'
            .'<img src="./ship.jpg" alt="Du thuyền"></div>'
            .'<div class="footer"><div class="copyright"><section><p>'
            .'© 2005. GPDKKD: 0303941729.</p></section></div></div>'
            .'</form></body></html>');

        $response = $this->withToken($this->token())->post('/api/admin/ai-agent/source-preview', [
            'target_type' => 'post', 'html_file' => $file,
        ])->assertOk();

        $content = $response->json('data.content_html');

        $this->assertStringContainsString('Đầu bài: du thuyền Ovation của Royal Caribbean.', $content);
        $this->assertStringContainsString('Cuối bài: tám tiện ích trên du thuyền.', $content);
        $this->assertStringNotContainsString('GPDKKD', $content);
        $this->assertSame('Bài du thuyền', $response->json('data.title'));
        $this->assertCount(2, $response->json('data.blocks'));
        $this->assertCount(1, $response->json('data.source_images'));
        $this->assertDatabaseCount('ai_imports', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử hTML tạo run và hai nguồn trùng
     * =====================================================================
     *
     * INPUT:
     * - HTML tạo run và hai nguồn trùng.
     *
     * OUTPUT:
     * - raw HTML được nhận; nguồn mơ hồ lỗi 422.
     *
     * SIDE EFFECT:
     * - Queue fake, không chạy AI.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_html_run_and_ambiguous_sources_contract(): void
    {
        $token = $this->token();
        $response = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', [
            'html' => '<article><pre>    code</pre><p>Source</p></article>', 'requested_outputs' => ['content'],
            'provider' => 'deterministic',
        ])->assertStatus(202);
        $run = AiImport::query()->findOrFail($response->json('data.job_id'));
        $this->assertSame('html', $run->input_json['source_format']);
        $this->assertStringContainsString('<pre>    code</pre>', $run->source_text);
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', [
            'text' => 'A', 'html' => '<p>B</p>',
        ])->assertStatus(422);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử parent với source/profile snapshot
     * =====================================================================
     *
     * INPUT:
     * - parent với source/profile snapshot; profile sau đó bị tắt.
     *
     * OUTPUT:
     * - child giữ snapshot parent, không đọc lại profile hiện tại.
     *
     * SIDE EFFECT:
     * - chỉ tạo child queued, không fetch URL.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_regenerate_keeps_parent_profile_and_source_snapshot(): void
    {
        $token = $this->token();
        $profile = $this->profile();
        $parent = $this->createRun($token, ['writing_profile_id' => $profile->id]);
        $source = ['hash' => 'same-source', 'content_html' => '<p>Snapshot</p>'];
        $parent->update(['status' => 'ready', 'source_meta_json' => ['article_source' => $source]]);
        $profile->update(['is_enabled' => false, 'version' => 2]);
        $response = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$parent->id.'/regenerate', ['fields' => ['title']])->assertStatus(202);
        $child = AiImport::query()->findOrFail($response->json('data.job_id'));
        $this->assertSame($source, $child->source_meta_json['article_source']);
        $this->assertSame(1, $child->input_json['writing_profile_snapshot']['version']);
        $this->assertSame('ready', $parent->fresh()->status);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử checkpoint có output nội bộ
     * =====================================================================
     *
     * INPUT:
     * - checkpoint có output nội bộ.
     *
     * OUTPUT:
     * - polling chỉ trả metadata an toàn.
     *
     * SIDE EFFECT:
     * - tạo step fixture, không gọi AI.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_step_polling_hides_intermediate_output_and_secrets(): void
    {
        $token = $this->token();
        $run = $this->createRun($token);
        $run->update(['source_meta_json' => ['ai_response' => ['model' => 'short-model', 'usage' => ['total_tokens' => 31], 'raw_response' => 'secret', 'api_key' => 'secret']]]);
        AiImportStep::query()->create([
            'ai_import_id' => $run->id, 'step_key' => 'article.analysis-plan',
            'input_hash' => str_repeat('a', 64), 'status' => 'completed',
            'output_json' => ['private_source' => 'never expose'],
            'diagnostics_json' => ['model' => 'example', 'api_key' => 'secret'],
        ]);
        $response = $this->withToken($token)->getJson('/api/admin/ai-agent/sessions/'.$run->id)->assertOk();
        $response->assertJsonPath('data.steps.0.key', 'article.analysis-plan');
        $this->assertStringNotContainsString('never expose', $response->getContent());
        $this->assertStringNotContainsString('secret', $response->getContent());
        $response->assertJsonPath('data.response_diagnostics.usage.total_tokens', 31);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử run đang editing và yêu cầu hủy
     * =====================================================================
     *
     * INPUT:
     * - run đang editing và yêu cầu hủy.
     *
     * OUTPUT:
     * - progress sau hủy không hồi sinh run.
     *
     * SIDE EFFECT:
     * - chỉ cập nhật lifecycle test; không gọi model.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_new_running_stages_cannot_be_deleted_and_cancel_is_terminal(): void
    {
        $token = $this->token();
        $run = $this->createRun($token);
        $run->update(['status' => 'editing', 'current_step' => 'editing']);
        $this->withToken($token)->deleteJson('/api/admin/ai-agent/sessions/'.$run->id)->assertStatus(409);
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$run->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $run->advance('writing', 55);
        $this->assertSame('cancelled', $run->fresh()->status);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử request timeout 600s và ba bước
     * =====================================================================
     *
     * INPUT:
     * - request timeout 600s và ba bước.
     *
     * OUTPUT:
     * - job 1920s nằm trong queue lease.
     *
     * SIDE EFFECT:
     * - không dispatch hoặc gọi model.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_three_step_queue_budget_is_inside_queue_lease(): void
    {
        $this->assertSame(3, AiRunBudget::calls(['target_type' => 'post', 'provider' => 'openai', 'fields' => ['content']]));
        $job = new ProcessAiImportJob('fixture', 600, 3);
        $this->assertSame(1920, $job->timeout);
        $this->assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'));
        $this->assertSame(1, AiRunBudget::calls(['provider' => 'openai', 'fields' => ['title']]));
        $this->assertSame(3, AiRunBudget::calls(['provider' => 'openai', 'fields' => ['content'], 'pipeline_snapshot' => ['pipeline' => 'single_step']]));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử cấu hình cũ còn mode B
     * =====================================================================
     *
     * INPUT:
     * - cấu hình cũ còn mode B.
     *
     * OUTPUT:
     * - run mới snapshot C và budget ba lượt.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_new_run_forces_three_steps_even_with_legacy_configuration(): void
    {
        config()->set('ai.content.pipeline', 'single_step');
        $run = $this->createRun($this->token());
        $this->assertSame('three_step', $run->input_json['pipeline_snapshot']['pipeline']);
        $this->assertSame(3, AiRunBudget::calls(array_replace((array) $run->input_json, ['provider' => 'openai'])));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử taxonomy sửa trên candidate và lựa chọn cũ trống
     * =====================================================================
     *
     * INPUT:
     * - taxonomy sửa trên candidate và lựa chọn cũ trống.
     *
     * OUTPUT:
     * - child giữ lựa chọn thủ công mới nhất, không lấy taxonomy AI cũ.
     *
     * SIDE EFFECT:
     * - queue fake, chỉ tạo child trong database test.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_regenerate_preserves_edited_manual_taxonomy_and_clears_legacy_ai_taxonomy(): void
    {
        $token = $this->token();
        $category = Category::query()->create(['name' => 'Manual', 'status' => 'active']);
        $run = $this->createRun($token);
        $run->update(['status' => 'ready', 'source_meta_json' => ['article_source' => ['hash' => 'fixed', 'content_html' => '<p>Source</p>']],
            'result_json' => ['draft' => ['title' => 'Edited', 'content_html' => '<p>Edited</p>', 'taxonomy_origin' => 'manual', 'category_ids' => [$category->id], 'tag_ids' => []]]]);
        $childId = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$run->id.'/regenerate', ['fields' => ['title']])->assertAccepted()->json('data.job_id');
        $this->assertSame([$category->id], AiImport::findOrFail($childId)->input_json['category_ids']);
        $input = $run->input_json;
        unset($input['taxonomy_origin']);
        $run->update(['input_json' => $input, 'result_json' => ['draft' => ['category_ids' => [$category->id]]]]);
        $legacyId = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$run->id.'/regenerate', ['fields' => ['title']])->assertAccepted()->json('data.job_id');
        $this->assertSame([], AiImport::findOrFail($legacyId)->input_json['category_ids']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử apply version cũ, rồi version hiện tại với taxonomy thủ công
     * =====================================================================
     *
     * INPUT:
     * - apply version cũ, rồi version hiện tại với taxonomy thủ công.
     *
     * OUTPUT:
     * - stale 409 không ghi Post; version đúng tạo Post draft.
     *
     * SIDE EFFECT:
     * - ghi Post/provenance trong database test, không gọi AI.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_apply_checks_candidate_version_and_uses_manual_taxonomy(): void
    {
        $token = $this->token();
        $category = Category::query()->create(['name' => 'Manual', 'status' => 'active']);
        $run = $this->createRun($token);
        $draft = ['title' => 'Bài mới', 'content_html' => '<p>Nội dung bài.</p>', 'category_ids' => [$category->id], 'tag_ids' => [], 'taxonomy_origin' => 'manual'];
        // Candidate legacy giả lập; kiểm Apply/taxonomy, không giả đã chạy worker archive.
        $run->update(['archive_version' => null, 'status' => 'ready', 'result_json' => ['draft' => $draft]]);
        $url = '/api/admin/ai-agent/candidates/'.$run->id.'/apply';
        $this->withToken($token)->postJson($url, ['fields' => ['title', 'content', 'taxonomy'], 'expected_version' => str_repeat('0', 64)])->assertConflict();
        $this->assertDatabaseCount('posts', 0);
        $postId = $this->withToken($token)->postJson($url, ['fields' => ['title', 'content', 'taxonomy'], 'expected_version' => hash('sha256', json_encode($draft))])->assertOk()->json('data.post_id');
        $post = Post::findOrFail($postId);
        $this->assertSame('draft', $post->status->value ?? $post->status);
        $this->assertSame([$category->id], $post->categories()->pluck('categories.id')->all());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử nguồn snapshot đã mất hoặc hết hạn
     * =====================================================================
     *
     * INPUT:
     * - nguồn snapshot đã mất hoặc hết hạn.
     *
     * OUTPUT:
     * - yêu cầu refresh explicit, không âm thầm fetch nguồn khác.
     *
     * SIDE EFFECT:
     * - chỉ ghi child khi refresh_source=true; queue fake.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_missing_snapshot_requires_explicit_refresh(): void
    {
        $token = $this->token();
        $run = $this->createRun($token);
        $run->update(['status' => 'ready']);
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$run->id.'/regenerate', [])->assertUnprocessable()->assertJsonValidationErrors('refresh_source');
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$run->id.'/regenerate', ['refresh_source' => true])->assertAccepted();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử queue sync
     * =====================================================================
     *
     * INPUT:
     * - queue sync.
     *
     * OUTPUT:
     * - 422 trước khi lưu run hoặc gọi provider.
     *
     * SIDE EFFECT:
     * - không ghi database hoặc gọi AI trong HTTP request.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_sync_queue_is_rejected_before_run_creation(): void
    {
        config()->set('queue.default', 'sync');
        $this->withToken($this->token())->postJson('/api/admin/ai-agent/sessions', ['text' => 'Source', 'provider' => 'deterministic'])->assertUnprocessable()->assertJsonValidationErrors('queue');
        $this->assertDatabaseCount('ai_imports', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử parent được sửa ngay khi child ảnh được tạo sau content ready
     * =====================================================================
     *
     * INPUT:
     * - parent được sửa ngay khi child ảnh được tạo sau content ready.
     *
     * OUTPUT:
     * - ghi image_job_id không làm mất title/content vừa sửa.
     *
     * SIDE EFFECT:
     * - event mô phỏng edit và Queue fake, không gọi model/ảnh thật.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_optional_image_metadata_does_not_overwrite_ready_candidate_edits(): void
    {
        $run = $this->createRun($this->token());
        $input = $run->input_json;
        $input['provider'] = 'deterministic';
        $input['ai_connection'] = [];
        $input['thumbnail_mode'] = 'generate';
        $input['generate_thumbnail'] = true;
        $input['fields'] = ['title', 'content', 'thumbnail'];
        $input['image_connection'] = ['provider' => 'fixture', 'model' => 'image'];
        $run->update(['input_json' => $input, 'provider' => 'deterministic']);
        $event = 'eloquent.created: '.AiImport::class;
        Event::listen($event, function (AiImport $child) use ($run): void {
            if ($child->operation !== 'image' || $child->parent_id !== $run->id) {
                return;
            }
            $parent = $run->fresh();
            $result = $parent->result_json;
            $result['draft']['title'] = 'Đã sửa trong editor';
            $result['draft']['content_html'] = '<p>Nội dung vừa sửa.</p>';
            $parent->update(['result_json' => $result]);
        });
        try {
            (new ProcessAiImportJob($run->id))->handle(app(ArticleImportService::class));
        } finally {
            Event::forget($event);
        }
        $this->assertSame('ready', $run->fresh()->status);
        $this->assertSame('Đã sửa trong editor', data_get($run->fresh()->result_json, 'draft.title'));
        $this->assertSame('<p>Nội dung vừa sửa.</p>', data_get($run->fresh()->result_json, 'draft.content_html'));
        $this->assertNotEmpty(data_get($run->fresh()->result_json, 'image_job_id'));
    }
}
