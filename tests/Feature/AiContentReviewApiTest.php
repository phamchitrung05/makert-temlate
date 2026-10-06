<?php

namespace Tests\Feature;

use App\Actions\Posts\CreatePostAction;
use App\Models\AiImport;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Services\Ai\Content\AiContentReviewService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm nghiệp vụ duyệt/từ chối và lịch sử Spatie trên ai_imports.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: setUp(), tearDown(), credentials(), candidate(),
 * versions(), events(), image(); các test nghiệp vụ:
 * - test_review_requires_permission_and_ownership_for_every_endpoint().
 * - test_review_exposes_saved_source_and_versions_without_private_snapshots().
 * - test_approval_creates_one_draft_and_audits_server_actor_without_publishing().
 * - test_rejection_requires_reason_and_blocks_edit_apply_and_form_provenance().
 * - test_stale_versions_and_edit_history_are_checked_before_any_decision().
 * - test_review_guards_expiry_readiness_target_and_required_versions().
 * - test_approval_rolls_back_post_and_audit_when_action_fails_after_writing().
 * - test_approval_preserves_media_usage_and_cleanup_keeps_post_and_activity().
 * - test_legacy_applied_review_has_no_fabricated_history_and_missing_title_is_validation().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): HTTP/fixtures -> assertions nghiệp vụ.
 * SIDE EFFECT: SQLite in-memory/storage fake; không gọi AI hoặc database thật.
 * =====================================================================
 */
class AiContentReviewApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    /** INPUT: lifecycle. OUTPUT: schema/quyền và I/O giả lập cô lập. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('media_public');
        config()->set('media-library.asset_disks.public', 'media_public');
    }

    /** INPUT: test kết thúc. OUTPUT: giải phóng riêng database test. */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** INPUT: permissions. OUTPUT: actor/token; chỉ ghi fixtures và reset guard/cache. */
    private function credentials(array $permissions = ['posts.manage', 'media.attach']): array
    {
        $user = User::factory()->create(['status' => 'active']);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return [$user, $user->createToken('ai-review-test', ['admin'])->plainTextToken];
    }

    /** INPUT: actor/overrides. OUTPUT: ready candidate có snapshot nguồn, không dispatch AI. */
    private function candidate(User $actor, array $overrides = []): AiImport
    {
        return AiImport::query()->create(array_replace([
            'created_by' => $actor->id, 'status' => 'ready', 'operation' => 'create',
            'provider' => 'deterministic', 'source_text' => 'Nguồn văn bản lưu sẵn.',
            'source_url' => 'https://example.test/saved-source',
            'input_json' => ['target_type' => 'post', 'model' => 'test-model', 'connection' => ['key' => 'private-credential']],
            'source_meta_json' => ['article_source' => ['title' => 'Nguồn gốc', 'content_html' => '<p>Nguồn gốc 123.</p><script>alert(1)</script>'], 'private' => 'private-credential'],
            'result_json' => ['provider' => 'deterministic', 'draft' => ['title' => 'Bài AI', 'content_html' => '<p>Bài AI 123.</p>', 'excerpt' => 'Tóm tắt', 'taxonomy_origin' => 'manual', 'category_ids' => [], 'tag_ids' => []]],
            'expires_at' => now()->addDays(2), 'completed_at' => now(),
        ], $overrides));
    }

    /** INPUT: run. OUTPUT: hai hash tương ứng draft và quyết định đã xem. */
    private function versions(AiImport $run): array
    {
        return ['expected_version' => hash('sha256', json_encode(data_get($run->result_json, 'draft', []))), 'expected_review_version' => AiContentReviewService::version($run)];
    }

    /** INPUT: run. OUTPUT: truy vấn chỉ sự kiện biên tập của candidate này. */
    private function events(AiImport $run)
    {
        return Activity::query()->where('log_name', 'ai-content')->where('properties->candidate_id', $run->id);
    }

    /** INPUT: actor. OUTPUT: public image với Spatie metadata, không upload thật/GD. */
    private function image(User $actor): MediaAsset
    {
        $asset = MediaAsset::factory()->image()->create(['created_by' => $actor->id]);
        $asset->media()->create([
            'collection_name' => 'library', 'name' => 'Review image', 'file_name' => 'review.png',
            'disk' => 'media_public', 'conversions_disk' => 'media_public', 'mime_type' => 'image/png',
            'size' => 100, 'manipulations' => [], 'custom_properties' => [],
            'generated_conversions' => [], 'responsive_images' => [], 'order_column' => 1,
        ]);

        return $asset->fresh('media');
    }

    /** INPUT: owner/khách/actor thiếu quyền. OUTPUT: 401/403/404, không ghi quyết định. */
    public function test_review_requires_permission_and_ownership_for_every_endpoint(): void
    {
        [$owner] = $this->credentials();
        $run = $this->candidate($owner);
        $base = '/api/admin/ai-agent/candidates/'.$run->id;
        $this->getJson($base.'/review')->assertUnauthorized();
        [, $token] = $this->credentials([]);
        $this->withToken($token)->getJson($base.'/review')->assertForbidden();
        foreach (['approve', 'reject'] as $decision) {
            Auth::forgetGuards();
            $this->withToken($token)->postJson($base.'/'.$decision, [...$this->versions($run), 'fields' => ['title'], 'reason' => 'Thiếu quyền'])->assertForbidden();
        }
        [, $token] = $this->credentials();
        foreach (['/review', '/review/history'] as $path) {
            Auth::forgetGuards();
            $this->withToken($token)->getJson($base.$path)->assertNotFound();
        }
        foreach (['approve', 'reject'] as $decision) {
            Auth::forgetGuards();
            $this->withToken($token)->postJson($base.'/'.$decision, [...$this->versions($run), 'fields' => ['title'], 'reason' => 'Kiểm tra'])->assertNotFound();
        }
        $this->assertSame(0, $this->events($run)->count());
        $this->assertDatabaseCount('posts', 0);
    }

    /** INPUT: nguồn lưu sẵn chứa script/secret. OUTPUT: allowlist an toàn, không HTTP tới nguồn. */
    public function test_review_exposes_saved_source_and_versions_without_private_snapshots(): void
    {
        [$actor, $token] = $this->credentials();
        $run = $this->candidate($actor);
        $response = $this->withToken($token)->getJson('/api/admin/ai-agent/candidates/'.$run->id.'/review')->assertOk()
            ->assertJsonPath('data.review.status', 'pending_review')->assertJsonPath('data.can_review', true)
            ->assertJsonPath('data.source.title', 'Nguồn gốc')->assertJsonPath('data.source.available', true)
            ->assertJsonPath('data.review_version', AiContentReviewService::version($run));
        $this->assertStringContainsString('Nguồn gốc 123.', $response->json('data.source.content_html'));
        $this->assertStringNotContainsString('<script', $response->getContent());
        $this->assertStringNotContainsString('private-credential', $response->getContent());
        $run->update(['source_meta_json' => []]);
        $this->withToken($token)->getJson('/api/admin/ai-agent/candidates/'.$run->id.'/review')->assertOk()->assertJsonPath('data.source.available', true);
        $run->update(['source_text' => null]);
        $this->withToken($token)->getJson('/api/admin/ai-agent/candidates/'.$run->id.'/review')->assertOk()->assertJsonPath('data.source.available', false);
        Http::assertNothingSent();
    }

    /** INPUT: approve với status/actor giả từ client. OUTPUT: Post draft + actor/time thật + Spatie history. */
    public function test_approval_creates_one_draft_and_audits_server_actor_without_publishing(): void
    {
        [$actor, $token] = $this->credentials();
        $run = $this->candidate($actor);
        $source = $run->source_meta_json['article_source'];
        $expiry = $run->expires_at->toIso8601String();
        $base = '/api/admin/ai-agent/candidates/'.$run->id;
        $response = $this->withToken($token)->postJson($base.'/approve', [...$this->versions($run), 'fields' => ['title', 'content'], 'reason' => '  Đã kiểm tra nguồn  ', 'status' => 'published', 'reviewed_by' => 999, 'reviewed_at' => '2000-01-01'])
            ->assertOk()->assertJsonPath('data.review.status', 'approved')->assertJsonPath('data.review.reviewed_by.id', $actor->id)->assertJsonPath('data.review.reason', 'Đã kiểm tra nguồn');
        $post = Post::query()->findOrFail($response->json('data.post_id'));
        $this->assertSame('draft', $post->status->value);
        $this->assertNull($post->published_at);
        $this->assertSame($source, $run->fresh()->source_meta_json['article_source']);
        $this->assertSame($expiry, $run->fresh()->expires_at->toIso8601String());
        $this->assertDatabaseHas('ai_provenances', ['run_id' => $run->id, 'field' => 'content', 'target_id' => $post->id]);
        $this->assertSame($actor->id, (int) $this->events($run)->firstOrFail()->causer_id);
        $this->withToken($token)->getJson($base.'/review/history')->assertOk()->assertJsonPath('data.0.event', 'candidate.approved')->assertJsonPath('data.0.post_id', $post->id)->assertJsonPath('meta.pagination.total', 1);
        $this->withToken($token)->postJson($base.'/approve', [...$this->versions($run->fresh()), 'fields' => ['title']])->assertConflict();
        $this->withToken($token)->postJson($base.'/apply', ['fields' => ['title']])->assertConflict();
        $this->assertDatabaseCount('posts', 1);
        $this->assertSame(1, $this->events($run)->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    /** INPUT: reject thiếu lý do rồi lý do hợp lệ. OUTPUT: từ chối, draft giữ nguyên, mọi đường apply bị khóa. */
    public function test_rejection_requires_reason_and_blocks_edit_apply_and_form_provenance(): void
    {
        [$actor, $token] = $this->credentials();
        $run = $this->candidate($actor);
        $draft = $run->result_json;
        $base = '/api/admin/ai-agent/candidates/'.$run->id;
        $this->withToken($token)->postJson($base.'/reject', [...$this->versions($run), 'reason' => '   '])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->withToken($token)->postJson($base.'/reject', [...$this->versions($run), 'reason' => '  Sai số liệu nguồn  '])->assertOk()->assertJsonPath('data.review.reason', 'Sai số liệu nguồn');
        $this->assertSame('ready', $run->fresh()->status);
        $this->assertSame($draft, $run->fresh()->result_json);
        $this->withToken($token)->getJson('/api/admin/ai-agent/sessions')->assertOk()->assertJsonPath('data.0.review.status', 'rejected');
        $this->withToken($token)->postJson($base.'/approve', [...$this->versions($run->fresh()), 'fields' => ['title']])->assertConflict();
        $this->withToken($token)->postJson($base.'/apply', ['fields' => ['title']])->assertConflict();
        $this->withToken($token)->patchJson($base, ['title' => 'Sửa bài từ chối', 'content_html' => '<p>Nội dung mới</p>', 'expected_version' => $this->versions($run)['expected_version']])->assertConflict();
        $this->withToken($token)->postJson('/api/admin/posts', ['title' => 'Lách qua PostForm', 'status' => 'draft', 'ai_run_id' => $run->id, 'ai_fields' => ['title']])->assertUnprocessable();
        $this->withToken($token)->postJson($base.'/reject', [...$this->versions($run->fresh()), 'reason' => 'Lặp'])->assertConflict();
        $this->assertDatabaseCount('posts', 0);
        $this->assertSame(1, $this->events($run)->count());
    }

    /** INPUT: edit giữa GET và quyết định. OUTPUT: 409 stale, lịch sử edit phân trang vẫn đủ. */
    public function test_stale_versions_and_edit_history_are_checked_before_any_decision(): void
    {
        [$actor, $token] = $this->credentials();
        $run = $this->candidate($actor);
        $versions = $this->versions($run);
        $base = '/api/admin/ai-agent/candidates/'.$run->id;
        $this->withToken($token)->patchJson($base, ['title' => 'Bài đã sửa', 'content_html' => '<p>Nội dung mới</p>', 'expected_version' => $versions['expected_version']])->assertOk();
        $this->withToken($token)->postJson($base.'/approve', [...$versions, 'fields' => ['title']])->assertConflict();
        $this->withToken($token)->postJson($base.'/reject', [...$this->versions($run->fresh()), 'expected_review_version' => $versions['expected_review_version'], 'reason' => 'Bản cũ'])->assertConflict();
        $this->withToken($token)->postJson($base.'/reject', [...$this->versions($run->fresh()), 'reason' => 'Bản mới'])->assertOk();
        $this->withToken($token)->getJson($base.'/review/history?per_page=1')->assertOk()->assertJsonPath('data.0.event', 'candidate.rejected')->assertJsonPath('meta.pagination.total', 2)->assertJsonPath('meta.pagination.last_page', 2);
        $this->withToken($token)->getJson($base.'/review/history?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.event', 'candidate.edited')->assertJsonPath('data.0.fields.0', 'title');
        $this->assertDatabaseCount('posts', 0);
    }

    /** INPUT: expired/chưa ready/sai target/version thiếu/title thiếu. OUTPUT: 409/422, không audit mutation. */
    public function test_review_guards_expiry_readiness_target_and_required_versions(): void
    {
        [$actor, $token] = $this->credentials();
        $run = $this->candidate($actor);
        $base = '/api/admin/ai-agent/candidates/'.$run->id;
        $this->withToken($token)->postJson($base.'/approve', ['fields' => ['title']])->assertUnprocessable()->assertJsonValidationErrors(['expected_version', 'expected_review_version']);
        $this->withToken($token)->postJson($base.'/approve', [...$this->versions($run), 'fields' => ['content']])->assertUnprocessable()->assertJsonValidationErrors('fields');
        $this->withToken($token)->postJson($base.'/approve', [...$this->versions($run), 'fields' => ['title'], 'target_id' => 1])->assertUnprocessable()->assertJsonValidationErrors('target_id');
        foreach (['writing', 'failed', 'cancelled'] as $status) {
            $run->update(['status' => $status]);
            $this->withToken($token)->postJson($base.'/reject', [...$this->versions($run), 'reason' => 'Chưa ready'])->assertConflict();
        }
        $run->update(['status' => 'ready', 'expires_at' => now()->subMinute()]);
        $this->withToken($token)->getJson($base.'/review')->assertOk()->assertJsonPath('data.can_review', false);
        $this->withToken($token)->postJson($base.'/approve', [...$this->versions($run), 'fields' => ['title']])->assertConflict();
        $run->update(['input_json' => ['target_type' => 'sound']]);
        $this->withToken($token)->getJson($base.'/review')->assertUnprocessable();
        $this->assertSame(0, $this->events($run)->count());
        $this->assertDatabaseCount('posts', 0);
    }

    /** INPUT: action thất bại sau ghi Post thật. OUTPUT: rollback Post/provenance/decision/audit cùng nhau. */
    public function test_approval_rolls_back_post_and_audit_when_action_fails_after_writing(): void
    {
        [$actor, $token] = $this->credentials();
        $run = $this->candidate($actor);
        $create = app(CreatePostAction::class);
        $this->mock(CreatePostAction::class, function (MockInterface $mock) use ($create): void {
            $mock->shouldReceive('handle')->once()->andReturnUsing(function (array $attributes, int $actorId) use ($create): void {
                $create->handle($attributes, $actorId);
                throw ValidationException::withMessages(['ai_run_id' => 'Ghi Post chưa hoàn tất']);
            });
        });
        $this->withToken($token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/approve', [...$this->versions($run), 'fields' => ['title', 'content']])->assertUnprocessable();
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('ai_provenances', 0);
        $this->assertNull($run->fresh()->applied_target_id);
        $this->assertSame('pending_review', AiContentReviewService::state($run->fresh())['status']);
        $this->assertSame(0, $this->events($run)->count());
    }

    /** INPUT: inline + thumbnail có quyền rồi cleanup run hết hạn. OUTPUT: usage/provenance/Post/audit giữ lại. */
    public function test_approval_preserves_media_usage_and_cleanup_keeps_post_and_activity(): void
    {
        [$actor, $token] = $this->credentials();
        $asset = $this->image($actor);
        $run = $this->candidate($actor);
        $result = $run->result_json;
        $result['draft']['content_html'] = '<p>Ảnh đã kiểm tra</p><img src="'.$asset->getFirstMediaUrl('library').'" data-media-asset-id="'.$asset->id.'" alt="Minh họa">';
        $result['draft']['thumbnail'] = ['media_asset_id' => $asset->id];
        $run->update(['result_json' => $result]);
        $response = $this->withToken($token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/approve', [...$this->versions($run), 'fields' => ['title', 'content', 'thumbnail']])->assertOk();
        $postId = $response->json('data.post_id');
        $this->assertDatabaseMissing('media_asset_usages', ['media_asset_id' => $asset->id, 'field' => 'post.content_images']);
        $this->assertDatabaseMissing('media_asset_usages', ['linkable_id' => $postId, 'field' => 'post.gallery']);
        $this->assertDatabaseHas('media_asset_usages', ['media_asset_id' => $asset->id, 'field' => 'post.thumbnail']);
        $run->update(['expires_at' => now()->subMinute()]);
        $this->artisan('ai-import:cleanup')->assertSuccessful();
        $this->assertDatabaseMissing('ai_imports', ['id' => $run->id]);
        $this->assertDatabaseHas('posts', ['id' => $postId, 'status' => 'draft']);
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
        $this->assertDatabaseHas('ai_provenances', ['run_id' => $run->id, 'target_id' => $postId]);
        $this->assertSame(1, $this->events($run)->count());
    }

    /** INPUT: candidate Apply cũ không audit và draft thiếu title. OUTPUT: không dựng lịch sử giả, lỗi create trả 422. */
    public function test_legacy_applied_review_has_no_fabricated_history_and_missing_title_is_validation(): void
    {
        [$actor, $token] = $this->credentials();
        $post = Post::factory()->create(['created_by' => $actor->id, 'updated_by' => $actor->id, 'status' => 'draft']);
        $run = $this->candidate($actor, ['applied_target_id' => $post->id]);
        $base = '/api/admin/ai-agent/candidates/'.$run->id;
        $this->withToken($token)->getJson($base.'/review')->assertOk()->assertJsonPath('data.review.status', 'approved')->assertJsonPath('data.review.reviewed_by', null)->assertJsonPath('data.review.reviewed_at', null)->assertJsonPath('data.can_review', false);
        $this->withToken($token)->getJson($base.'/review/history')->assertOk()->assertJsonCount(0, 'data');
        $run->update(['applied_target_id' => null, 'result_json' => ['draft' => ['content_html' => '<p>Không có tiêu đề</p>']]]);
        $this->withToken($token)->postJson($base.'/approve', [...$this->versions($run), 'fields' => ['title', 'content']])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertDatabaseCount('posts', 1);
        $this->assertSame(0, $this->events($run)->count());
    }
}
