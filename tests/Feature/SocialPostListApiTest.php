<?php

namespace Tests\Feature;

use App\Jobs\ProcessSocialPostCaptionJob;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Marketing\SocialPostLifecycleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm API list/filter và tạo draft Social Post qua HTTP.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp()/tearDown(): chuẩn bị admin, schema và queue fake.
 * - test_platform_filter_matches_variants_and_legacy_targets_with_grouped_search(): kiểm filter.
 * - test_create_api_persists_draft_and_queues_ai_without_provider_http(): kiểm tạo bài thật.
 * - test_global_stats_use_one_aggregate_query_and_ignore_deleted_posts(): kiểm KPI và số query.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): request admin -> assertion database, JSON và queue giả lập.
 * =====================================================================
 */
final class SocialPostListApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    private User $actor;

    /** Input: không có. Output: admin có quyền và database/HTTP/queue cô lập. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        $this->actor = User::factory()->create(['status' => 'active']);
        $this->actor->givePermissionTo(['marketing.view', 'marketing.manage']);
        $this->flushPermissionCache();
        $this->withToken($this->actor->createToken('social-list', ['admin'])->plainTextToken);
        Queue::fake();
        Http::preventStrayRequests();
    }

    /** Input: môi trường test. Output: giải phóng schema. */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Input: draft có variant và post cũ chỉ có target. Output: filter đúng platform và giữ search/state. */
    public function test_platform_filter_matches_variants_and_legacy_targets_with_grouped_search(): void
    {
        $draft = app(SocialPostLifecycleService::class)->create(['caption' => 'Filtered draft'], $this->actor->id);
        $legacy = SocialPost::query()->create(['caption' => 'Legacy target', 'review_status' => 'approved', 'created_by' => $this->actor->id]);
        SocialPost::query()->create(['caption' => 'Không có platform', 'created_by' => $this->actor->id]);
        $account = SocialAccount::query()->create([
            'provider' => 'facebook', 'account_type' => 'page', 'external_id' => 'legacy-page',
            'display_name' => 'Page', 'status' => 'active', 'created_by' => $this->actor->id,
        ]);
        $legacy->targets()->create(['social_account_id' => $account->id, 'platform' => 'facebook']);

        $listed = $this->getJson('/api/admin/social-posts?platform=facebook')->assertOk();
        $this->assertEqualsCanonicalizing([$draft->id, $legacy->id], array_column($listed->json('data'), 'id'));
        $this->getJson('/api/admin/social-posts?platform=facebook&search=Filtered&review_status=draft')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $draft->id);
    }

    /** Input: payload tạo draft với generate=true. Output: bản ghi thật, AI queued, chưa gọi provider. */
    public function test_create_api_persists_draft_and_queues_ai_without_provider_http(): void
    {
        $response = $this->postJson('/api/admin/social-posts', [
            'caption' => 'Bài tạo từ giao diện', 'target_account_ids' => [], 'generate' => true,
        ])->assertCreated()->assertJsonPath('data.post.review_status', 'draft');
        $id = $response->json('data.post.id');

        $this->assertDatabaseHas('social_posts', ['id' => $id, 'caption' => 'Bài tạo từ giao diện']);
        $this->assertNotEmpty($response->json('data.generation.caption_job_id'));
        Queue::assertPushed(ProcessSocialPostCaptionJob::class);
        Http::assertNothingSent();
        $this->getJson('/api/admin/social-posts?platform=facebook')->assertOk()->assertJsonPath('data.0.id', $id);
    }

    /** Input: nhiều trạng thái, bản ghi bị xóa và filter list. Output: KPI global chính xác bằng một query tổng hợp. */
    public function test_global_stats_use_one_aggregate_query_and_ignore_deleted_posts(): void
    {
        foreach (['draft', 'pending_review', 'approved', 'rejected'] as $status) {
            SocialPost::query()->create([
                'caption' => $status, 'review_status' => $status,
                'publication_status' => $status === 'approved' ? 'published' : 'pending',
                'created_by' => $this->actor->id,
            ]);
        }
        $deleted = SocialPost::query()->create(['review_status' => 'draft', 'created_by' => $this->actor->id]);
        $deleted->delete();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $response = $this->getJson('/api/admin/social-posts?search=approved')->assertOk()->assertJsonCount(1, 'data');
            $queries = collect(DB::getQueryLog())->pluck('query');
        } finally {
            DB::disableQueryLog();
        }

        $response->assertJsonPath('meta.stats', [
            'draft' => 1, 'pending_review' => 1, 'approved' => 1, 'published' => 1, 'failed' => 1,
        ]);
        $counts = $queries->filter(fn (string $sql): bool => str_contains(strtolower($sql), 'count(')
            && str_contains($sql, 'social_posts'));
        $this->assertCount(2, $counts, 'Only pagination count and one KPI aggregate are needed.');
        $this->assertCount(1, $counts->filter(fn (string $sql): bool => str_contains(strtolower($sql), 'case when')));

        SocialPost::query()->delete();
        $this->getJson('/api/admin/social-posts')->assertOk()->assertJsonPath('meta.stats', [
            'draft' => 0, 'pending_review' => 0, 'approved' => 0, 'published' => 0, 'failed' => 0,
        ]);
    }
}
