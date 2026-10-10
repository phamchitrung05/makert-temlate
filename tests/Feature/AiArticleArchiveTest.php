<?php

namespace Tests\Feature;

use App\Exceptions\AiArticleArchiveException;
use App\Exceptions\AiImportException;
use App\Jobs\EvaluateAiArticleJob;
use App\Jobs\ProcessAiImportJob;
use App\Models\AiArticleArchive;
use App\Models\AiImport;
use App\Models\Post;
use App\Models\User;
use App\Services\Ai\Content\AiContentReviewService;
use App\Services\Ai\Content\Archives\AiArticleArchiveService;
use App\Services\Ai\Content\ArticleImportService;
use App\Services\Ai\Content\ArticleSourceExtractor;
use App\Services\Ai\Providers\Adapters\DeterministicAiProvider;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\ArchiveArticleProvider;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm kho AI bền vững chỉ cho candidate đã được duyệt.
 * =====================================================================
 *
 * PHPUnit kiểm kho chỉ lưu candidate đã duyệt: tạo ba bản cùng session, chọn bản 2,
 * giữ original trước edit, rollback và dọn run/Post. SQLite in-memory, storage,
 * queue và provider fake; chặn HTTP lạ, không dùng dữ liệu hoặc AI thật.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp().
 * - tearDown().
 * - makeRun().
 * - fixtureResult().
 * - complete().
 * - approve().
 * - archive().
 * - token().
 * - test_worker_keeps_candidate_transient_until_approval().
 * - test_approval_archives_only_selected_generation().
 * - test_multiple_regenerations_archive_only_approved_candidate().
 * - test_rejection_does_not_archive_candidate().
 * - test_edit_before_approval_preserves_ai_original().
 * - test_cleanup_removes_unapproved_candidate_without_archive().
 * - test_cleanup_preserves_approved_archive_after_run_expiry().
 * - test_approved_archive_is_immutable_and_unique().
 * - test_checkpoint_is_idempotent_and_conflict_is_blocked_before_approval().
 * - test_archive_failure_rolls_back_approval_and_keeps_checkpoint().
 * - test_worker_recovers_transient_checkpoint_without_archiving().
 * - test_failed_cancelled_expired_runs_do_not_archive().
 * - test_retry_keeps_unapproved_generation_transient().
 * - test_deterministic_output_is_classified_after_approval().
 * - test_approved_snapshot_redacts_credentials_and_keeps_ordinary_urls().
 * - test_three_step_trace_is_archived_only_after_approval().
 * - test_image_and_non_post_runs_are_outside_article_archive_scope().
 * - test_archive_command_only_handles_approved_candidates().
 * - test_review_approval_records_archive_lifecycle_and_blocks_duplicate_decisions().
 * - test_post_form_apply_archives_the_selected_original().
 * - test_ready_recovery_does_not_overwrite_manual_candidate_edits().
 * - test_late_audit_failure_rolls_back_post_provenance_and_archive().
 * - test_invalid_or_missing_checkpoint_blocks_approval_but_not_unapproved_cleanup().
 * - test_cleanup_retains_approved_run_when_original_is_missing().
 * - test_cleanup_detects_corrupted_approved_archive_before_removing_run().
 * - test_approved_archive_survives_run_and_post_deletion().
 * - test_legacy_approval_keeps_original_unknown_instead_of_copying_edited_candidate().
 * - test_legacy_command_requires_opt_in_and_only_archives_approved_metadata().
 * - test_archive_command_recovers_approved_checkpoint_and_validates_options().
 * - test_cancel_and_expiry_after_checkpoint_do_not_create_archives().
 * - test_retry_blocks_approved_runs_and_busy_workers().
 * - test_title_only_approval_does_not_classify_inherited_content_as_new_ai_article().
 * - test_mixed_approval_distinguishes_new_ai_content_from_inherited_fields().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Fixtures, HTTP request và dependency test đã cô lập.
 * - OUTPUT: Assertions cho output, quyền, validation và lifecycle hiện có.
 * - SIDE EFFECT: Tạo/đọc/sửa dữ liệu ở DB test; setup/teardown quản lý schema và connection riêng.
 * - EXCEPTION/TRANSACTION: Lỗi assertion/dependency truyền ra PHPUnit; transaction code nghiệp vụ chạy trong môi trường test.
 * =====================================================================
 */
class AiArticleArchiveTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị môi trường cô lập trước mỗi ca kiểm thử
     * =====================================================================
     *
     * INPUT:
     * - Không có tham số; PHPUnit gọi trước mỗi ca test.
     *
     * OUTPUT:
     * - SQLite in-memory, quyền đã seed, queue/storage fake và HTTP lạ bị chặn.
     *
     * SIDE EFFECT:
     * - Migrate schema test, seed RolePermissionSeeder và cấu hình fake; không đổi database ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi setup/schema truyền ra PHPUnit; không mở transaction nghiệp vụ bao toàn bộ ca test.
     *
     * =====================================================================
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        config()->set('queue.default', 'database');
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('media_public');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn môi trường cô lập sau mỗi ca kiểm thử
     * =====================================================================
     *
     * INPUT:
     * - Không có tham số; PHPUnit gọi sau mỗi ca test.
     *
     * OUTPUT:
     * - Schema/connection test được rollback và trạng thái framework được dọn.
     *
     * SIDE EFFECT:
     * - Rollback hoặc drop schema test và giải phóng connection theo teardown của class; không dọn dữ liệu ứng dụng.
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
     * CHỨC NĂNG: Tạo run Post và nguồn fixture có context để kiểm kho
     * =====================================================================
     *
     * INPUT:
     * - attributes/input overrides cho AiImport fixture; mặc định target Post và archive_version 1.
     *
     * OUTPUT:
     * - AiImport với nguồn HTML/source blocks, brief/profile và session_id để đối chiếu snapshot.
     *
     * SIDE EFFECT:
     * - Tạo User/AiImport trong DB cô lập, extract nguồn fixture và lưu session/checkpoint override khi có.
     *
     * EXCEPTION/TRANSACTION:
     * - Không tự mở transaction; lỗi extractor/DB truyền ra PHPUnit, teardown dọn schema test.
     *
     * =====================================================================
     */
    private function makeRun(array $attributes = [], array $input = []): AiImport
    {
        $actor = User::factory()->create(['status' => 'active']);
        $sourceHtml = '<h2>Nguồn thử</h2><p>Hệ thống chạy lúc 8 giờ và lưu dữ liệu.</p><pre><code>echo 123;</code></pre><table><tr><td>20</td></tr></table>';
        $source = (new ArticleSourceExtractor)->snapshot($sourceHtml, ['source_url' => 'https://example.test/source', 'title' => 'Nguồn thử']);
        $run = AiImport::query()->create(array_replace([
            'created_by' => $actor->id, 'source_url' => '', 'source_text' => $sourceHtml,
            'status' => 'queued', 'current_step' => 'queued', 'progress' => 0,
            'archive_version' => 1, 'generation_no' => 1, 'operation' => 'create',
            'input_json' => array_replace([
                'target_type' => 'post', 'source_type' => 'text', 'source_format' => 'html',
                'provider' => 'deterministic', 'language' => 'vi', 'generate_thumbnail' => false,
                'writing_brief' => ['audience' => 'Người mới', 'purpose' => 'Hướng dẫn'],
                'writing_profile_snapshot' => ['id' => 12, 'version' => 3, 'name' => 'Mẫu thử', 'style_instructions' => 'Viết rõ ràng'],
            ], $input),
            'source_meta_json' => ['article_source' => $source], 'expires_at' => now()->addDays(2),
        ], $attributes));
        $run->forceFill(['session_id' => $run->session_id ?: $run->id])->save();
        if (array_key_exists('archive_pending_json', $attributes)) {
            $run->forceFill(['archive_pending_json' => $attributes['archive_pending_json']])->save();
        }

        return $run;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dựng kết quả canonical AI giả dùng trong kiểm thử
     * =====================================================================
     *
     * INPUT:
     * - Không có tham số.
     *
     * OUTPUT:
     * - Array kết quả fixture có source/draft/identity/version và generation_meta.mode là ai.
     *
     * SIDE EFFECT:
     * - Chỉ dựng array/model trong memory; không persist hoặc gọi HTTP.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; caller thực thi pipeline trên fixture này.
     *
     * =====================================================================
     */
    private function fixtureResult(): array
    {
        return [
            'source' => ['url' => 'https://example.test/source', 'title' => 'Nguồn thử'],
            'draft' => ['title' => 'Bài AI gốc', 'content_html' => '<p>Bản AI đầu tiên, chạy lúc 8 giờ.</p>',
                'content' => '<p>Bản AI đầu tiên, chạy lúc 8 giờ.</p>', 'excerpt' => 'Mô tả ban đầu'],
            'provider' => 'fixture', 'model' => 'writer-fixture', 'prompt_key' => 'post.create.from_text',
            'prompt_version' => '1.0', 'schema_version' => 'post.content.v1', 'requested_fields' => ['title', 'content'],
            'generation_meta' => ['mode' => 'ai', 'generated_fields' => ['title', 'content_html', 'content', 'excerpt']],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chạy worker bằng service fake và xác nhận checkpoint tạm
     * =====================================================================
     *
     * INPUT:
     * - Run/generation cần hoàn tất và result fixture tùy chọn; không dùng provider thật.
     *
     * OUTPUT:
     * - void: assertions xác nhận ready, checkpoint tạm tồn tại và chưa có archive cho run.
     *
     * SIDE EFFECT:
     * - Bind ArticleImportService mock và chạy ProcessAiImportJob; chỉ tạo checkpoint/lifecycle trong DB test, chưa tạo kho/Post.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi mock/assertion/worker truyền ra ca test; transaction/lock thuộc worker và service checkpoint.
     *
     * =====================================================================
     */
    private function complete(AiImport $run, ?array $result = null): void
    {
        $service = $this->mock(ArticleImportService::class);
        $service->shouldReceive('run')->once()->andReturn($result ?? $this->fixtureResult());
        (new ProcessAiImportJob($run->id, generationNo: (int) $run->generation_no))->handle($service);
        $this->assertSame('ready', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->archive_pending_json);
        $this->assertDatabaseMissing('ai_article_archives', ['run_id' => $run->id]);
        $quality = app(\App\Services\Ai\Content\Quality\ArticleQualityEvaluationService::class);
        if ($quality->supports($run->fresh())) {
            $evaluation = $quality->latestFor($run->fresh());
            $evaluation?->forceFill([
                'status' => 'ready', 'score_total' => 4.5,
                'scores_json' => ['accuracy' => 4.5, 'source_grounding' => 4.5, 'clarity' => 4.5, 'structure' => 4.5, 'style' => 4.5],
                'source_references_json' => ['S001'],
                'eligibility_json' => ['score' => true, 'source' => true, 'facts' => true, 'eligible' => true, 'reasons' => []],
                'completed_at' => now(),
            ])->save();
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Duyệt candidate qua API Apply và lấy Post draft ID
     * =====================================================================
     *
     * INPUT:
     * - Candidate ready và fields được chọn, mặc định title/content; helper tạo token của owner.
     *
     * OUTPUT:
     * - int Post draft ID sau khi request API Apply trả thành công.
     *
     * SIDE EFFECT:
     * - Gửi API Apply với hash draft hiện tại; ghi Post/provenance/quyết định/archive qua boundary duyệt trong DB test.
     *
     * EXCEPTION/TRANSACTION:
     * - HTTP không thành công gây assertion failure; transaction/lock và rollback thuộc AiContentReviewService.
     *
     * =====================================================================
     */
    private function approve(AiImport $run, array $fields = ['title', 'content']): int
    {
        $quality = app(\App\Services\Ai\Content\Quality\ArticleQualityEvaluationService::class);
        if ($quality->supports($run->fresh())) {
            $evaluation = $quality->schedule($run->fresh());
            $evaluation?->forceFill([
                'status' => 'ready', 'score_total' => 4.5,
                'scores_json' => ['accuracy' => 4.5, 'source_grounding' => 4.5, 'clarity' => 4.5, 'structure' => 4.5, 'style' => 4.5],
                'source_references_json' => ['S001'],
                'eligibility_json' => ['score' => true, 'source' => true, 'facts' => true, 'eligible' => true, 'reasons' => []],
                'completed_at' => now(),
            ])->save();
        }
        $token = $this->token($run);
        $response = $this->withToken($token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/apply', [
            'fields' => $fields,
            'expected_version' => hash('sha256', json_encode(data_get($run->fresh()->result_json, 'draft'))),
        ])->assertOk();

        return (int) $response->json('data.post_id');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc archive của run và generation trong database test
     * =====================================================================
     *
     * INPUT:
     * - AiImport và số generation cần đọc, mặc định 1.
     *
     * OUTPUT:
     * - AiArticleArchive khớp run_id/generation_no trong DB test.
     *
     * SIDE EFFECT:
     * - Query kho theo run_id/generation_no trong DB cô lập; không ghi hoặc eager load quan hệ.
     *
     * EXCEPTION/TRANSACTION:
     * - firstOrFail() ném ModelNotFoundException nếu archive thiếu; không tự mở transaction.
     *
     * =====================================================================
     */
    private function archive(AiImport $run, int $number = 1): AiArticleArchive
    {
        return AiArticleArchive::query()->where('run_id', $run->id)->where('generation_no', $number)->firstOrFail();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị actor và token admin cho request kiểm thử
     * =====================================================================
     *
     * INPUT:
     * - AiImport có created_by trỏ tới User fixture đã tạo.
     *
     * OUTPUT:
     * - Token Sanctum có ability admin cho owner với posts.manage/media.attach.
     *
     * SIDE EFFECT:
     * - Cấp quyền, xóa permission cache/Auth guards và tạo token trong DB cô lập.
     *
     * EXCEPTION/TRANSACTION:
     * - Không tự mở transaction; thiếu owner ném ModelNotFoundException, lỗi quyền/token truyền ra ca test.
     *
     * =====================================================================
     */
    private function token(AiImport $run): string
    {
        $actor = User::query()->findOrFail($run->created_by);
        $actor->givePermissionTo(['posts.manage', 'media.attach']);
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $actor->createToken('archive-test', ['admin'])->plainTextToken;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử bài hoàn tất chưa duyệt
     * =====================================================================
     *
     * INPUT:
     * - bài hoàn tất chưa duyệt.
     *
     * OUTPUT:
     * - archive không tồn tại, checkpoint tạm tồn tại.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_worker_keeps_candidate_transient_until_approval(): void
    {
        $run = $this->makeRun();
        $this->complete($run);
        $this->assertDatabaseCount('ai_article_archives', 0);
        $this->assertSame('pending_review', AiContentReviewService::state($run->fresh())['status']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử candidate ready
     * =====================================================================
     *
     * INPUT:
     * - candidate ready.
     *
     * OUTPUT:
     * - chỉ bản được duyệt vào kho, đủ source/context/hash.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_approval_archives_only_selected_generation(): void
    {
        $run = $this->makeRun();
        $this->complete($run);
        $postId = $this->approve($run);
        $archive = $this->archive($run);

        $this->assertSame($postId, $archive->applied_target_id);
        $this->assertSame('ai_original', $archive->content_origin);
        $this->assertTrue($archive->has_generated_content);
        $this->assertSame('Bài AI gốc', $archive->draft_snapshot_json['title']);
        $this->assertSame('S001', $archive->source_snapshot_json['blocks'][0]['id']);
        $this->assertSame('Người mới', $archive->context_snapshot_json['writing_brief']['audience']);
        $this->assertNull($run->fresh()->archive_pending_json);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử tạo rồi regenerate hai lần trong cùng session
     * =====================================================================
     *
     * INPUT:
     * - tạo rồi regenerate hai lần trong cùng session.
     *
     * OUTPUT:
     * - chỉ bản số 2 tồn tại sau cleanup.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_multiple_regenerations_archive_only_approved_candidate(): void
    {
        $first = $this->makeRun();
        $this->complete($first);
        $token = $this->token($first);
        $response = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$first->id.'/regenerate', [
            'fields' => ['title', 'content'],
        ])->assertAccepted();
        $second = AiImport::findOrFail($response->json('data.job_id'));
        $this->complete($second, tap($this->fixtureResult(), function (&$result): void {
            $result['draft']['title'] = 'Bản số 2';
        }));
        $response = $this->withToken($token)->postJson('/api/admin/ai-agent/sessions/'.$second->id.'/regenerate', [
            'fields' => ['title', 'content'],
        ])->assertAccepted();
        $third = AiImport::findOrFail($response->json('data.job_id'));
        $this->complete($third, tap($this->fixtureResult(), function (&$result): void {
            $result['draft']['title'] = 'Bản số 3';
        }));
        $this->assertSame($first->id, $second->parent_id);
        $this->assertSame($second->id, $third->parent_id);
        $this->assertSame($first->id, $second->session_id);
        $this->assertSame($first->id, $third->session_id);
        $this->approve($second);
        AiImport::query()->where('session_id', $first->id)->update(['expires_at' => now()->subMinute()]);
        $this->artisan('ai-import:cleanup')->assertSuccessful();

        $this->assertDatabaseCount('ai_article_archives', 1);
        $this->assertDatabaseHas('ai_article_archives', ['run_id' => $second->id, 'generation_no' => 1]);
        $this->assertSame('Bản số 2', $this->archive($second)->draft_snapshot_json['title']);
        $this->assertSame($first->id, $this->archive($second)->session_id);
        $this->assertDatabaseCount('ai_imports', 0);
        $this->assertDatabaseMissing('ai_article_archives', ['run_id' => $first->id]);
        $this->assertDatabaseMissing('ai_article_archives', ['run_id' => $third->id]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử candidate bị từ chối
     * =====================================================================
     *
     * INPUT:
     * - candidate bị từ chối.
     *
     * OUTPUT:
     * - không tạo archive bền vững.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_rejection_does_not_archive_candidate(): void
    {
        $run = $this->makeRun();
        $this->complete($run);
        $this->withToken($this->token($run))->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/reject', [
            'reason' => 'Chưa phù hợp nhu cầu',
            'expected_version' => hash('sha256', json_encode(data_get($run->fresh()->result_json, 'draft'))),
            'expected_review_version' => AiContentReviewService::version($run->fresh()),
        ])->assertOk();
        $run->update(['expires_at' => now()->subMinute()]);
        $this->artisan('ai-import:cleanup')->assertSuccessful();
        $this->assertDatabaseCount('ai_article_archives', 0);
        $this->assertDatabaseMissing('ai_imports', ['id' => $run->id]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử candidate được sửa trước khi duyệt
     * =====================================================================
     *
     * INPUT:
     * - candidate được sửa trước khi duyệt.
     *
     * OUTPUT:
     * - kho giữ bản AI trước edit, Post giữ bản đã sửa.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_edit_before_approval_preserves_ai_original(): void
    {
        $run = $this->makeRun();
        $this->complete($run);
        $token = $this->token($run);
        $this->withToken($token)->patchJson('/api/admin/ai-agent/candidates/'.$run->id, [
            'title' => 'Bản người dùng sửa', 'content_html' => '<p>Nội dung người dùng biên tập.</p>',
            'expected_version' => hash('sha256', json_encode(data_get($run->fresh()->result_json, 'draft'))),
        ])->assertOk();
        $postId = $this->approve($run);
        $archive = $this->archive($run);

        $this->assertSame('Bài AI gốc', $archive->draft_snapshot_json['title']);
        $this->assertSame('Bản người dùng sửa', Post::findOrFail($postId)->title);
        $this->assertSame('approved', $archive->lifecycle_json['review_status']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử ready chưa duyệt hết hạn
     * =====================================================================
     *
     * INPUT:
     * - ready chưa duyệt hết hạn.
     *
     * OUTPUT:
     * - cleanup xóa candidate nhưng không tạo archive.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_cleanup_removes_unapproved_candidate_without_archive(): void
    {
        $run = $this->makeRun();
        $this->complete($run);
        $run->update(['expires_at' => now()->subMinute()]);
        $this->artisan('ai-import:cleanup')->assertSuccessful();
        $this->assertDatabaseMissing('ai_imports', ['id' => $run->id]);
        $this->assertDatabaseCount('ai_article_archives', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử candidate đã duyệt hết hạn
     * =====================================================================
     *
     * INPUT:
     * - candidate đã duyệt hết hạn.
     *
     * OUTPUT:
     * - archive vẫn đọc được sau cleanup.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_cleanup_preserves_approved_archive_after_run_expiry(): void
    {
        $run = $this->makeRun();
        $this->complete($run);
        $this->approve($run);
        $hash = $this->archive($run)->payload_hash;
        $run->update(['expires_at' => now()->subMinute()]);
        $this->artisan('ai-import:cleanup')->assertSuccessful();
        $archive = $this->archive($run);

        $this->assertDatabaseMissing('ai_imports', ['id' => $run->id]);
        $this->assertSame($hash, $archive->payload_hash);
        $this->assertSame('retention_cleanup', $archive->lifecycle_json['removed_reason']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử archive đã lưu
     * =====================================================================
     *
     * INPUT:
     * - archive đã lưu.
     *
     * OUTPUT:
     * - payload immutable và unique generation.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_approved_archive_is_immutable_and_unique(): void
    {
        $run = $this->makeRun();
        $this->complete($run);
        $this->approve($run);
        $archive = $this->archive($run);
        try {
            $archive->update(['draft_snapshot_json' => ['title' => 'Thay bản gốc']]);
            $this->fail('Payload phải bất biến.');
        } catch (AiArticleArchiveException $exception) {
            $this->assertSame('AI_ARCHIVE_IMMUTABLE', $exception->reason);
        }
        $copy = $archive->fresh()->getAttributes();
        unset($copy['id']);
        try {
            AiArticleArchive::query()->insert($copy);
            $this->fail('Unique key phải chặn insert thứ hai.');
        } catch (QueryException) {
            $this->assertDatabaseCount('ai_article_archives', 1);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử hai lần stage cùng payload/khác payload
     * =====================================================================
     *
     * INPUT:
     * - hai lần stage cùng payload/khác payload.
     *
     * OUTPUT:
     * - checkpoint idempotent/conflict, chưa archive.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_checkpoint_is_idempotent_and_conflict_is_blocked_before_approval(): void
    {
        $run = $this->makeRun();
        $archives = app(AiArticleArchiveService::class);
        $archives->stageCompletion($run->id, 1, $this->fixtureResult());
        $before = $run->fresh()->archive_pending_json;
        $archives->stageCompletion($run->id, 1, $this->fixtureResult());
        $this->assertSame($before, $run->fresh()->archive_pending_json);
        $other = $this->fixtureResult();
        $other['draft']['title'] = 'Bản khác';
        try {
            $archives->stageCompletion($run->id, 1, $other);
            $this->fail('Không được ghi đè checkpoint của writer đầu.');
        } catch (AiArticleArchiveException $exception) {
            $this->assertSame('AI_ARCHIVE_CONFLICT', $exception->reason);
        }
        $this->assertSame($before, $run->fresh()->archive_pending_json);
        $this->assertDatabaseCount('ai_article_archives', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử archive insert lỗi trong approve
     * =====================================================================
     *
     * INPUT:
     * - archive insert lỗi trong approve.
     *
     * OUTPUT:
     * - Post/decision/archive rollback, checkpoint còn để retry.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_archive_failure_rolls_back_approval_and_keeps_checkpoint(): void
    {
        $run = $this->makeRun();
        $this->complete($run);
        $event = 'eloquent.creating: '.AiArticleArchive::class;
        Event::listen($event, fn () => throw new RuntimeException('Storage unavailable'));
        $this->withToken($this->token($run))->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/apply', [
            'fields' => ['title', 'content'],
            'expected_version' => hash('sha256', json_encode(data_get($run->fresh()->result_json, 'draft'))),
        ])->assertStatus(500);
        Event::forget($event);

        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('ai_article_archives', 0);
        $this->assertNotNull($run->fresh()->archive_pending_json);
        $this->assertSame('pending_review', AiContentReviewService::state($run->fresh())['status']);
        $this->assertDatabaseCount('ai_provenances', 0);
        $this->approve($run);
        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseCount('ai_article_archives', 1);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử worker dừng sau checkpoint
     * =====================================================================
     *
     * INPUT:
     * - worker dừng sau checkpoint.
     *
     * OUTPUT:
     * - lần chạy sau lên ready, không gọi provider lại.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_worker_recovers_transient_checkpoint_without_archiving(): void
    {
        $run = $this->makeRun();
        $metadata = array_replace($run->source_meta_json, ['ai_response' => ['usage' => ['total_tokens' => 9]],
            'article_pipeline' => ['used_steps' => [['task' => 'article.writer', 'prompt_version' => '2.3']]]]);
        $run->update(['source_meta_json' => $metadata]);
        $archives = app(AiArticleArchiveService::class);
        $archives->stageCompletion($run->id, 1, $this->fixtureResult());
        (new ProcessAiImportJob($run->id, generationNo: 1))->failed(new RuntimeException('Worker stopped'));
        $service = $this->mock(ArticleImportService::class);
        $service->shouldNotReceive('run');
        (new ProcessAiImportJob($run->id, generationNo: 1))->handle($service);

        $this->assertSame('ready', $run->fresh()->status);
        $this->assertSame($metadata, $run->fresh()->source_meta_json);
        $this->assertDatabaseCount('ai_article_archives', 0);
        $this->approve($run);
        $this->assertDatabaseCount('ai_article_archives', 1);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử provider lỗi/hủy/hết hạn
     * =====================================================================
     *
     * INPUT:
     * - provider lỗi/hủy/hết hạn.
     *
     * OUTPUT:
     * - không tạo bài archive giả.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_failed_cancelled_expired_runs_do_not_archive(): void
    {
        $failed = $this->makeRun();
        $service = $this->mock(ArticleImportService::class);
        $service->shouldReceive('run')->once()->andThrow(new AiImportException('Provider lỗi', 'AI_PROVIDER_TIMEOUT'));
        (new ProcessAiImportJob($failed->id, generationNo: 1))->handle($service);
        $cancelled = $this->makeRun();
        $this->withToken($this->token($cancelled))->postJson('/api/admin/ai-agent/sessions/'.$cancelled->id.'/cancel')->assertOk();
        $expired = $this->makeRun(['expires_at' => now()->subMinute()]);
        $this->artisan('ai-import:cleanup')->assertSuccessful();

        $this->assertDatabaseCount('ai_article_archives', 0);
        $this->assertDatabaseMissing('ai_imports', ['id' => $expired->id]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử retry tạo generation mới
     * =====================================================================
     *
     * INPUT:
     * - retry tạo generation mới.
     *
     * OUTPUT:
     * - generation cũ không archive; chỉ generation được duyệt được lưu.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_retry_keeps_unapproved_generation_transient(): void
    {
        $run = $this->makeRun();
        $this->complete($run);
        $run->forceFill(['status' => 'failed', 'error_code' => 'AI_PROVIDER_TIMEOUT', 'completed_at' => now()])->save();
        $this->withToken($this->token($run))->postJson('/api/admin/ai-agent/sessions/'.$run->id.'/retry')->assertOk();
        $this->assertSame(2, $run->fresh()->generation_no);
        $this->assertNull($run->fresh()->archive_pending_json);
        $staleService = $this->mock(ArticleImportService::class);
        $staleService->shouldNotReceive('run');
        (new ProcessAiImportJob($run->id, generationNo: 1))->handle($staleService);
        (new ProcessAiImportJob($run->id, generationNo: 1))->failed(new RuntimeException('Old job'));
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertNull($run->fresh()->error_code);
        $this->assertDatabaseCount('ai_article_archives', 0);
        $this->complete($run->fresh());
        $this->approve($run->fresh());
        $this->assertDatabaseCount('ai_article_archives', 1);
        $this->assertDatabaseHas('ai_article_archives', ['run_id' => $run->id, 'generation_no' => 2]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử deterministic output
     * =====================================================================
     *
     * INPUT:
     * - deterministic output.
     *
     * OUTPUT:
     * - chỉ khi approve mới lưu, không tính là AI content mới.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_deterministic_output_is_classified_after_approval(): void
    {
        $run = $this->makeRun();
        $service = $this->mock(ArticleImportService::class);
        $service->shouldReceive('run')->once()->andReturnUsing(fn () => (new ArticleImportService(new DeterministicAiProvider))->run($run));
        (new ProcessAiImportJob($run->id, generationNo: 1))->handle($service);
        $this->assertDatabaseCount('ai_article_archives', 0);
        $this->approve($run);
        $archive = $this->archive($run);
        $this->assertSame('deterministic', $archive->content_origin);
        $this->assertFalse($archive->has_generated_content);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử source/context có credential và URL thường
     * =====================================================================
     *
     * INPUT:
     * - source/context có credential và URL thường.
     *
     * OUTPUT:
     * - checkpoint/archive redact đúng.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_approved_snapshot_redacts_credentials_and_keeps_ordinary_urls(): void
    {
        $run = $this->makeRun(input: [
            'ai_connection' => ['key' => 'fixture-secret-value', 'headers' => ['Authorization' => 'Bearer fixture-header-token']],
            'instructions' => 'Yêu cầu fixture-secret-value và https://user:pass@example.test/path?token=private&lang=vi',
        ]);
        $result = $this->fixtureResult();
        $result['draft']['content_html'] = '<p>fixture-secret-value <a href="https://user:pass@example.test/ref?api_key=hidden&lang=vi">Nguồn</a></p>';
        $ordinaryUrl = 'https://example.test/ref?keyword=hello&x.y=1&x.y=2&a=%2f#key-features';
        $result['draft']['content_html'] .= '<p><a href="'.$ordinaryUrl.'">Tham khảo thường</a></p>';
        $result['draft']['content'] = $result['draft']['content_html'];
        $result['headers'] = ['Authorization' => 'fixture-header-token'];
        $this->complete($run, $result);
        $checkpoint = json_encode($run->fresh()->archive_pending_json, JSON_UNESCAPED_SLASHES);
        $this->assertArrayNotHasKey('archive_pending_json', $run->fresh()->toArray());
        foreach (['fixture-secret-value', 'fixture-header-token', 'user:pass', 'api_key=hidden', 'token=private', 'Authorization'] as $secret) {
            $this->assertStringNotContainsString($secret, $checkpoint);
        }
        $this->approve($run);
        $archive = $this->archive($run);
        $this->assertArrayNotHasKey('draft_snapshot_json', $archive->toArray());
        $this->assertStringContainsString('[redacted]', $archive->draft_snapshot_json['content_html']);
        $this->assertStringContainsString('lang=vi', $archive->draft_snapshot_json['content_html']);
        $this->assertStringContainsString($ordinaryUrl, $archive->draft_snapshot_json['content_html']);
        $this->assertArrayNotHasKey('ai_connection', $archive->context_snapshot_json);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử pipeline ba bước thật bằng provider fixture
     * =====================================================================
     *
     * INPUT:
     * - pipeline ba bước thật bằng provider fixture.
     *
     * OUTPUT:
     * - trace chỉ xuất hiện trong archive sau approve.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_three_step_trace_is_archived_only_after_approval(): void
    {
        $source = (new ArticleSourceExtractor)->snapshot('<p>Version 2.0 supports 100 items and requires a paid plan.</p>', ['source_url' => 'inline://fixture', 'title' => 'Version 2.0']);
        $run = $this->makeRun(['source_meta_json' => ['article_source' => $source]], [
            'requested_outputs' => ['title', 'content'], 'fields' => ['title', 'content'],
            'pipeline_snapshot' => ['pipeline' => 'three_step', 'prompt_version' => '2.3', 'schema_version' => '1'],
        ]);
        $provider = new ArchiveArticleProvider;
        (new ProcessAiImportJob($run->id, generationNo: 1))->handle(new ArticleImportService($provider));
        $this->assertSame(3, $provider->calls);
        $this->assertDatabaseCount('ai_article_archives', 0);
        $this->approve($run);
        $steps = $this->archive($run)->diagnostics_json['used_steps'];
        $this->assertCount(3, $steps);
        $this->assertSame('article.writer', $steps[1]['task']);
        $this->assertSame('2.3', $steps[1]['prompt_version']);
        $this->assertSame(15, $steps[1]['diagnostics']['usage']['total_tokens']);
        Http::assertNothingSent();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử ảnh/resource target
     * =====================================================================
     *
     * INPUT:
     * - ảnh/resource target.
     *
     * OUTPUT:
     * - ngoài phạm vi kho bài Post.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_image_and_non_post_runs_are_outside_article_archive_scope(): void
    {
        $image = $this->makeRun(['operation' => 'image', 'status' => 'failed']);
        $resource = $this->makeRun(['status' => 'failed'], ['target_type' => 'resource']);
        $archives = app(AiArticleArchiveService::class);
        $this->assertNull($archives->preserve($image, legacy: true));
        $this->assertNull($archives->preserve($resource, legacy: true));
        $this->assertDatabaseCount('ai_article_archives', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử command archive trên candidate approved/chưa approved
     * =====================================================================
     *
     * INPUT:
     * - command archive trên candidate approved/chưa approved.
     *
     * OUTPUT:
     * - chỉ approved được kiểm kê/ghi.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_archive_command_only_handles_approved_candidates(): void
    {
        $pending = $this->makeRun();
        $approved = $this->makeRun();
        $this->complete($pending);
        $this->complete($approved);
        $this->approve($approved);
        $this->artisan('ai-articles:archive', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('ai_article_archives', 1);
        $this->assertDatabaseMissing('ai_article_archives', ['run_id' => $pending->id]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử approve chuẩn và lần duyệt lặp
     * =====================================================================
     *
     * INPUT:
     * - approve chuẩn và lần duyệt lặp.
     *
     * OUTPUT:
     * - một kho/Post, actor/reason thật và không Publish.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_review_approval_records_archive_lifecycle_and_blocks_duplicate_decisions(): void
    {
        $run = $this->makeRun();
        $this->complete($run);
        $token = $this->token($run);
        $base = '/api/admin/ai-agent/candidates/'.$run->id;
        $data = [
            'fields' => ['title', 'content'], 'reason' => 'Đã đọc và chọn bản này',
            'expected_version' => hash('sha256', json_encode(data_get($run->fresh()->result_json, 'draft'))),
            'expected_review_version' => AiContentReviewService::version($run->fresh()),
        ];
        $response = $this->withToken($token)->postJson($base.'/approve', $data)->assertOk();
        $archive = $this->archive($run);
        $this->assertSame($run->created_by, $archive->lifecycle_json['reviewed_by']);
        $this->assertSame($data['reason'], $archive->lifecycle_json['reason']);
        $this->assertNotNull($archive->lifecycle_json['reviewed_at']);
        $this->assertSame((int) $response->json('data.post_id'), $archive->applied_target_id);
        $this->assertSame('draft', Post::findOrFail($archive->applied_target_id)->status->value);
        $this->withToken($token)->postJson($base.'/approve', $data)->assertConflict();
        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseCount('ai_article_archives', 1);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử post form sử dụng run có checkpoint
     * =====================================================================
     *
     * INPUT:
     * - Post form sử dụng run có checkpoint.
     *
     * OUTPUT:
     * - archive được ghi dù không qua màn hình review.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_post_form_apply_archives_the_selected_original(): void
    {
        $run = $this->makeRun();
        $this->complete($run);
        $this->withToken($this->token($run))->postJson('/api/admin/posts', [
            'title' => 'Bài đã biên tập tại Post', 'content' => '<p>Nội dung biên tập tại Post.</p>',
            'status' => 'draft', 'ai_run_id' => $run->id, 'ai_fields' => ['title', 'content'],
        ])->assertCreated();
        $archive = $this->archive($run);
        $this->assertSame('Bài AI gốc', $archive->draft_snapshot_json['title']);
        $this->assertSame('Bài đã biên tập tại Post', Post::findOrFail($archive->applied_target_id)->title);
        $this->assertSame('approved', $archive->lifecycle_json['review_status']);
        $this->assertDatabaseCount('ai_article_archives', 1);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử ready đã sửa và worker redelivery
     * =====================================================================
     *
     * INPUT:
     * - ready đã sửa và worker redelivery.
     *
     * OUTPUT:
     * - không khôi phục bản gốc đè edit hoặc gia hạn run.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_ready_recovery_does_not_overwrite_manual_candidate_edits(): void
    {
        $run = $this->makeRun();
        $this->complete($run);
        $this->withToken($this->token($run))->patchJson('/api/admin/ai-agent/candidates/'.$run->id, [
            'title' => 'Tôi sửa tay', 'content_html' => '<p>Bản chỉnh tay của tôi.</p>',
            'expected_version' => hash('sha256', json_encode(data_get($run->fresh()->result_json, 'draft'))),
        ])->assertOk();
        $before = $run->fresh()->getAttributes();
        $this->assertFalse(app(AiArticleArchiveService::class)->resumeReady($run->id, 1));
        $service = $this->mock(ArticleImportService::class);
        $service->shouldNotReceive('run');
        (new ProcessAiImportJob($run->id, generationNo: 1))->handle($service);
        $this->assertSame($before, $run->fresh()->getAttributes());
        $this->assertDatabaseCount('ai_article_archives', 0);
        $this->approve($run);
        $this->assertSame('Bài AI gốc', $this->archive($run)->draft_snapshot_json['title']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử lỗi audit sau khi Post/provenance/archive đã được ghi
     * =====================================================================
     *
     * INPUT:
     * - lỗi audit sau khi Post/provenance/archive đã được ghi.
     *
     * OUTPUT:
     * - tất cả rollback cùng checkpoint.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_late_audit_failure_rolls_back_post_provenance_and_archive(): void
    {
        $run = $this->makeRun();
        $this->complete($run);
        $event = 'eloquent.creating: '.Activity::class;
        Event::listen($event, function (Activity $activity): void {
            if ($activity->log_name === 'ai-content' && $activity->description === 'candidate.approved') {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            $this->withToken($this->token($run))->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/apply', [
                'fields' => ['title', 'content'],
            ])->assertStatus(500);
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('ai_provenances', 0);
        $this->assertDatabaseCount('ai_article_archives', 0);
        $this->assertDatabaseMissing('activity_log', ['log_name' => 'ai-content', 'description' => 'candidate.approved']);
        $this->assertNotNull($run->fresh()->archive_pending_json);
        $this->assertSame('pending_review', AiContentReviewService::state($run->fresh())['status']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử checkpoint thiếu/sai hash khi duyệt
     * =====================================================================
     *
     * INPUT:
     * - checkpoint thiếu/sai hash khi duyệt.
     *
     * OUTPUT:
     * - rollback; candidate chưa chọn vẫn dọn được.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_invalid_or_missing_checkpoint_blocks_approval_but_not_unapproved_cleanup(): void
    {
        foreach ([null, ['hash' => 'invalid', 'snapshot' => [], 'result' => []]] as $pending) {
            $run = $this->makeRun(['status' => 'ready', 'result_json' => $this->fixtureResult(), 'archive_pending_json' => $pending]);
            $quality = app(\App\Services\Ai\Content\Quality\ArticleQualityEvaluationService::class);
            $evaluation = $quality->schedule($run);
            $evaluation?->forceFill([
                'status' => 'ready', 'score_total' => 4.5,
                'scores_json' => ['accuracy' => 4.5, 'source_grounding' => 4.5, 'clarity' => 4.5, 'structure' => 4.5, 'style' => 4.5],
                'source_references_json' => ['S001'],
                'eligibility_json' => ['score' => true, 'source' => true, 'facts' => true, 'eligible' => true, 'reasons' => []],
                'completed_at' => now(),
            ])->save();
            $this->withToken($this->token($run))->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/apply', [
                'fields' => ['title', 'content'],
            ])->assertStatus(500);
            $this->assertSame('pending_review', AiContentReviewService::state($run->fresh())['status']);
            $run->update(['expires_at' => now()->subMinute()]);
        }
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('ai_provenances', 0);
        $this->assertDatabaseCount('ai_article_archives', 0);
        $this->artisan('ai-import:cleanup')->assertSuccessful();
        $this->assertDatabaseCount('ai_imports', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử bản đã duyệt thiếu kho/checkpoint
     * =====================================================================
     *
     * INPUT:
     * - bản đã duyệt thiếu kho/checkpoint.
     *
     * OUTPUT:
     * - cleanup giữ run thay vì mất bản đã chọn.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_cleanup_retains_approved_run_when_original_is_missing(): void
    {
        $run = $this->makeRun(['status' => 'ready', 'applied_target_id' => 321, 'expires_at' => now()->subMinute()]);
        $this->artisan('ai-import:cleanup')->expectsOutputToContain('AI_ARCHIVE_ORIGINAL_MISSING')->assertFailed();
        $this->assertDatabaseHas('ai_imports', ['id' => $run->id]);
        $this->assertDatabaseCount('ai_article_archives', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử jSON kho bị thay bằng raw SQL
     * =====================================================================
     *
     * INPUT:
     * - JSON kho bị thay bằng raw SQL.
     *
     * OUTPUT:
     * - kiểm hash giữ run đã duyệt để phục hồi.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_cleanup_detects_corrupted_approved_archive_before_removing_run(): void
    {
        $run = $this->makeRun();
        $this->complete($run);
        $this->approve($run);
        DB::table('ai_article_archives')->where('run_id', $run->id)->update(['draft_snapshot_json' => json_encode(['title' => 'Payload bị đổi'])]);
        $run->update(['expires_at' => now()->subMinute()]);
        $this->artisan('ai-import:cleanup')->expectsOutputToContain('AI_ARCHIVE_CHECKPOINT_INVALID')->assertFailed();
        $this->assertDatabaseHas('ai_imports', ['id' => $run->id]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử đã duyệt rồi xóa run/Post
     * =====================================================================
     *
     * INPUT:
     * - đã duyệt rồi xóa run/Post.
     *
     * OUTPUT:
     * - payload/hash và nguồn vẫn đọc được độc lập.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_approved_archive_survives_run_and_post_deletion(): void
    {
        $run = $this->makeRun();
        $this->complete($run);
        $postId = $this->approve($run);
        $hash = $this->archive($run)->payload_hash;
        $token = $this->token($run);
        $this->withToken($token)->deleteJson('/api/admin/ai-agent/sessions/'.$run->id)->assertOk();
        $this->withToken($token)->deleteJson('/api/admin/posts/'.$postId)->assertNoContent();
        Post::withTrashed()->findOrFail($postId)->forceDelete();
        $archive = $this->archive($run);
        $this->assertDatabaseMissing('ai_imports', ['id' => $run->id]);
        $this->assertDatabaseMissing('posts', ['id' => $postId]);
        $this->assertSame($hash, $archive->payload_hash);
        $this->assertSame('Bài AI gốc', $archive->draft_snapshot_json['title']);
        $this->assertSame('user_deleted', $archive->lifecycle_json['removed_reason']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử duyệt candidate cũ không có checkpoint
     * =====================================================================
     *
     * INPUT:
     * - duyệt candidate cũ không có checkpoint.
     *
     * OUTPUT:
     * - Post vẫn tạo, kho đánh dấu thiếu original.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_legacy_approval_keeps_original_unknown_instead_of_copying_edited_candidate(): void
    {
        $run = $this->makeRun(['archive_version' => null, 'status' => 'ready', 'result_json' => $this->fixtureResult()]);
        $postId = $this->approve($run);
        $archive = $this->archive($run);
        $this->assertSame($postId, $archive->applied_target_id);
        $this->assertSame('legacy_unverified', $archive->content_origin);
        $this->assertNull($archive->draft_snapshot_json);
        $this->assertNull($archive->content_hash);
        $this->assertFalse($archive->has_generated_content);
        $this->assertSame('legacy_candidate_may_have_been_edited', $archive->context_snapshot_json['missing_original_reason']);
        $this->assertSame('Bài AI gốc', Post::findOrFail($postId)->title);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử legacy approved/chưa chọn, dry-run và opt-in
     * =====================================================================
     *
     * INPUT:
     * - legacy approved/chưa chọn, dry-run và opt-in.
     *
     * OUTPUT:
     * - chỉ approved được ghi metadata, không fake bài.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_legacy_command_requires_opt_in_and_only_archives_approved_metadata(): void
    {
        $approved = $this->makeRun(['archive_version' => null, 'status' => 'ready', 'applied_target_id' => 321, 'result_json' => $this->fixtureResult()]);
        $pending = $this->makeRun(['archive_version' => null, 'status' => 'ready', 'result_json' => $this->fixtureResult()]);
        $this->artisan('ai-articles:archive')->expectsOutputToContain('Đã kiểm tra 0 run')->assertSuccessful();
        $this->artisan('ai-articles:archive', ['--include-legacy' => true, '--dry-run' => true])
            ->expectsOutputToContain('legacy_approved_unverified: 1')->assertSuccessful();
        $this->assertDatabaseCount('ai_article_archives', 0);
        $this->artisan('ai-articles:archive', ['--include-legacy' => true, '--limit' => 1])
            ->expectsOutputToContain('legacy_unverified: 1')->assertSuccessful();
        $hash = $this->archive($approved)->payload_hash;
        $this->artisan('ai-articles:archive', ['--include-legacy' => true])->assertSuccessful();
        $this->assertDatabaseCount('ai_article_archives', 1);
        $this->assertSame($hash, $this->archive($approved)->payload_hash);
        $this->assertNull($this->archive($approved)->draft_snapshot_json);
        $this->assertDatabaseMissing('ai_article_archives', ['run_id' => $pending->id]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử run approved có checkpoint còn sót, candidate chưa chọn và tham số sai
     * =====================================================================
     *
     * INPUT:
     * - run approved có checkpoint còn sót, candidate chưa chọn và tham số sai.
     *
     * OUTPUT:
     * - phục hồi chỉ approved có giới hạn.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_archive_command_recovers_approved_checkpoint_and_validates_options(): void
    {
        $approved = $this->makeRun();
        $pending = $this->makeRun();
        $this->complete($approved);
        $this->complete($pending);
        $approved->update(['applied_target_id' => 321]);
        $this->artisan('ai-articles:archive', ['--run-id' => [$approved->id], '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('ai_article_archives', 0);
        $this->artisan('ai-articles:archive', ['--run-id' => [$approved->id]])->assertSuccessful();
        $this->assertDatabaseCount('ai_article_archives', 1);
        $this->assertNull($approved->fresh()->archive_pending_json);
        $this->assertNotNull($pending->fresh()->archive_pending_json);
        $this->artisan('ai-articles:archive', ['--limit' => 1001])->assertExitCode(2);
        $this->artisan('ai-articles:archive', ['--run-id' => ['invalid-uuid']])->assertExitCode(2);
        Http::assertNothingSent();
        Queue::assertPushed(EvaluateAiArticleJob::class, 2);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử hủy/hết hạn sau checkpoint
     * =====================================================================
     *
     * INPUT:
     * - hủy/hết hạn sau checkpoint.
     *
     * OUTPUT:
     * - worker không hồi sinh candidate hoặc tự tạo archive.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_cancel_and_expiry_after_checkpoint_do_not_create_archives(): void
    {
        $archives = app(AiArticleArchiveService::class);
        $cancelled = $this->makeRun();
        $archives->stageCompletion($cancelled->id, 1, $this->fixtureResult());
        $this->withToken($this->token($cancelled))->postJson('/api/admin/ai-agent/sessions/'.$cancelled->id.'/cancel')->assertOk();
        $this->assertFalse($archives->resumeReady($cancelled->id, 1));
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $expired = $this->makeRun();
        $archives->stageCompletion($expired->id, 1, $this->fixtureResult());
        $expired->update(['expires_at' => now()->subMinute()]);
        $this->assertFalse($archives->resumeReady($expired->id, 1));
        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertDatabaseCount('ai_article_archives', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử run đã duyệt bị gán expired và process lock đang bận
     * =====================================================================
     *
     * INPUT:
     * - run đã duyệt bị gán expired và process lock đang bận.
     *
     * OUTPUT:
     * - không retry đã duyệt, không tranh worker.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_retry_blocks_approved_runs_and_busy_workers(): void
    {
        $approved = $this->makeRun();
        $this->complete($approved);
        $this->approve($approved);
        $approved->update(['status' => 'expired']);
        $this->withToken($this->token($approved))->postJson('/api/admin/ai-agent/sessions/'.$approved->id.'/retry')->assertConflict();
        $this->assertSame(1, $approved->fresh()->generation_no);
        $failed = $this->makeRun(['status' => 'failed']);
        $lock = Cache::lock('ai-import-process-'.$failed->id, 120);
        $this->assertTrue($lock->get());
        try {
            $this->withToken($this->token($failed))->postJson('/api/admin/ai-agent/sessions/'.$failed->id.'/retry')->assertConflict();
            $this->assertSame(1, $failed->fresh()->generation_no);
        } finally {
            $lock->release();
        }
        $this->assertDatabaseCount('ai_article_archives', 1);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử chỉ title AI mới, content từ parent đã sửa
     * =====================================================================
     *
     * INPUT:
     * - chỉ title AI mới, content từ parent đã sửa.
     *
     * OUTPUT:
     * - duyệt không biến content kế thừa thành bài AI mới.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_title_only_approval_does_not_classify_inherited_content_as_new_ai_article(): void
    {
        $parent = $this->makeRun(['status' => 'ready', 'result_json' => $this->fixtureResult()]);
        $run = $this->makeRun(['parent_id' => $parent->id, 'operation' => 'regenerate'], [
            'fields' => ['title'], 'parent_draft_snapshot' => $this->fixtureResult()['draft'],
        ]);
        $result = $this->fixtureResult();
        $result['generation_meta']['generated_fields'] = ['title'];
        $this->complete($run, $result);
        $this->approve($run);
        $archive = $this->archive($run);
        $this->assertSame('ai_fields_only', $archive->content_origin);
        $this->assertFalse($archive->has_generated_content);
        $this->assertSame('ai', $archive->context_snapshot_json['field_origins']['title']);
        $this->assertSame('inherited', $archive->context_snapshot_json['field_origins']['content_html']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử content AI mới và title kế thừa
     * =====================================================================
     *
     * INPUT:
     * - content AI mới và title kế thừa.
     *
     * OUTPUT:
     * - kho approved mang mixed, vẫn có content mới để chấm.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_mixed_approval_distinguishes_new_ai_content_from_inherited_fields(): void
    {
        $parent = $this->makeRun(['status' => 'ready', 'result_json' => $this->fixtureResult()]);
        $run = $this->makeRun(['parent_id' => $parent->id, 'operation' => 'regenerate'], [
            'fields' => ['content'], 'parent_draft_snapshot' => $this->fixtureResult()['draft'],
        ]);
        $result = $this->fixtureResult();
        $result['generation_meta']['generated_fields'] = ['content_html', 'content'];
        $this->complete($run, $result);
        $this->approve($run);
        $archive = $this->archive($run);
        $this->assertSame('mixed', $archive->content_origin);
        $this->assertTrue($archive->has_generated_content);
        $this->assertSame('inherited', $archive->context_snapshot_json['field_origins']['title']);
        $this->assertSame('ai', $archive->context_snapshot_json['field_origins']['content_html']);
    }
}
