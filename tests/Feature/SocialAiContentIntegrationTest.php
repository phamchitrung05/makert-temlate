<?php

namespace Tests\Feature;

use App\Jobs\ProcessAiImportJob;
use App\Jobs\ProcessSocialPostImagePromptJob;
use App\Models\AiArticleArchive;
use App\Models\AiImport;
use App\Models\SocialPost;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Regression luồng Social từ AI Content (candidate → approve).
 * =====================================================================
 * Sau khi bỏ session adapter, Social đi pipeline chuẩn: POST /ai-agent/sessions
 * tạo candidate AiImport (operation create) trong danh sách AI Content; approve
 * qua candidate endpoint tạo SocialPost draft + queue ảnh + lưu vào AI Approved.
 * Test này fake queue/provider, không gọi paid API.
 * =====================================================================
 */
final class SocialAiContentIntegrationTest extends TestCase
{
    use UsesIsolatedDatabase;

    private User $actor;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        config(['queue.default' => 'database']);
        Queue::fake();
        $this->actor = User::factory()->create(['status' => 'active']);
        $this->actor->givePermissionTo(['social_posts.create', 'social_posts.view', 'social_posts.update', 'media.attach', 'media.upload']);
        $this->flushPermissionCache();
        Auth::forgetGuards();
        $this->token = $this->actor->createToken('social-ai-content', ['admin'])->plainTextToken;
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    private function payload(array $extra = []): array
    {
        return array_replace([
            'target_type' => 'social_post', 'text' => 'Một buổi sáng bên vườn hoa.',
            'provider' => 'deterministic', 'model' => 'deterministic',
            'requested_outputs' => ['social_content', 'social_gallery'], 'image_count' => 2,
        ], $extra);
    }

    /** Tạo candidate social qua API; trả về AiImport ready có draft social. */
    private function socialCandidate(array $extra = []): AiImport
    {
        $data = $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions', $this->payload($extra))
            ->assertAccepted()->json('data');

        return AiImport::findOrFail($data['job_id']);
    }

    /** Social từ AI Content giờ tạo candidate trong list thay vì SocialPost trực tiếp. */
    public function test_social_session_creates_candidate_in_ai_content_list(): void
    {
        $run = $this->socialCandidate();

        $this->assertSame('social_post', $run->input_json['target_type']);
        $this->assertSame('social_post.facebook.create.from_text', $run->input_json['prompt_key']);
        $this->assertSame('create', $run->operation);
        // Pipeline chuẩn: candidate queued qua ProcessAiImportJob, không queue component social trực tiếp.
        Queue::assertPushed(ProcessAiImportJob::class, 1);
        Queue::assertNotPushed(ProcessSocialPostImagePromptJob::class);
        // Social chưa duyệt: không tạo SocialPost nào.
        $this->assertDatabaseCount('social_posts', 0);
        // Candidate xuất hiện trong danh sách AI Content của owner.
        $this->withToken($this->token)->getJson('/api/admin/ai-agent/sessions')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.target_type', 'social_post');
    }

    /** Approve candidate social tạo SocialPost draft + lưu vào AI Approved + queue ảnh gallery. */
    public function test_social_candidate_approve_creates_draft_and_archive(): void
    {
        $run = $this->socialCandidate();
        $draft = ['content_html' => '<p>Caption Facebook.</p>', 'cta' => 'Mua ngay', 'hashtags' => ['hoa'], 'link_url' => null];
        $run->update(['status' => 'ready', 'result_json' => ['draft' => $draft]]);
        $expected = hash('sha256', json_encode($draft));
        $reviewVersion = \App\Services\Ai\Content\AiContentReviewService::version($run->fresh());

        $response = $this->withToken($this->token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/approve', [
            'fields' => ['social_content', 'social_gallery'],
            'expected_version' => $expected,
            'expected_review_version' => $reviewVersion,
        ]);

        $response->assertOk()->assertJsonPath('data.social_post_id', fn ($id) => $id !== null);
        $post = SocialPost::query()->latest('id')->firstOrFail();
        $this->assertSame("Caption Facebook.\n\nMua ngay\n\n#hoa", $post->caption);
        $this->assertSame($post->id, $run->fresh()->applied_target_id);
        $this->assertSame('approved', data_get($run->fresh()->source_meta_json, 'editorial.status'));
        // Social candidate cũng vào AI Approved giống Post.
        $this->assertDatabaseHas('ai_article_archives', ['run_id' => $run->id, 'generation_no' => $run->generation_no]);
        $archive = AiArticleArchive::query()->where('run_id', $run->id)->firstOrFail();
        $this->assertSame('social_post', $archive->target_type);
        // social_gallery được chọn → queue generate ảnh qua social_image_prompt.
        Queue::assertPushed(ProcessSocialPostImagePromptJob::class, 1);
    }

    /** Approve chỉ social_content (không gallery) thì không queue ảnh. */
    public function test_social_approve_without_gallery_does_not_queue_images(): void
    {
        $run = $this->socialCandidate(['requested_outputs' => ['social_content']]);
        $draft = ['content_html' => '<p>Caption only.</p>'];
        $run->update(['status' => 'ready', 'result_json' => ['draft' => $draft]]);
        $expected = hash('sha256', json_encode($draft));
        $reviewVersion = \App\Services\Ai\Content\AiContentReviewService::version($run->fresh());

        $this->withToken($this->token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/approve', [
            'fields' => ['social_content'],
            'expected_version' => $expected,
            'expected_review_version' => $reviewVersion,
        ])->assertOk();

        Queue::assertNotPushed(ProcessSocialPostImagePromptJob::class);
        $this->assertDatabaseHas('ai_article_archives', ['run_id' => $run->id]);
    }
}
