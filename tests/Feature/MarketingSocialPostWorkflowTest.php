<?php

namespace Tests\Feature;

use App\Jobs\ProcessSocialPostCaptionJob;
use App\Jobs\ProcessSocialPostImagePromptJob;
use App\Models\AiImport;
use App\Models\Campaign;
use App\Models\Product;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Marketing\SocialPostGenerationService;
use App\Services\Marketing\SocialPostGenerationStateService;
use App\Services\Marketing\SocialPostLifecycleService;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử vertical slice generation Social Post.
 * =====================================================================
 * Test dùng deterministic text provider và queue fake, không gọi API trả phí.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp()/tearDown(): cô lập schema.
 * - test_caption_and_image_prompt_are_independent_and_partial(): kiểm branch.
 * - fixture(): tạo product/campaign/post context.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : product/campaign và AI generation services.
 * - OUTPUT: caption/image prompt task ready, image thiếu -> partial draft.
 * - SIDE EFFECT: DB/queue chỉ trong isolated SQLite.
 * =====================================================================
 */
final class MarketingSocialPostWorkflowTest extends TestCase
{
    use UsesIsolatedDatabase;

    /** Input: test application. Output: migrated isolated schema. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        Queue::fake();
    }

    /** Input: isolated schema. Output: DB connection released. */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * Caption và image prompt chạy độc lập; thiếu image provider giữ draft partial.
     *
     * Input: SocialPost context và generation service.
     * Output: caption ready, image prompt ready, binary image failed/partial.
     */
    public function test_caption_and_image_prompt_are_independent_and_partial(): void
    {
        [$actor, $post] = $this->fixture();
        $queued = app(SocialPostGenerationService::class)->queue($post, $actor->id, ['component' => 'all']);

        $this->assertNotNull($queued['caption_job_id']);
        $this->assertNotNull($queued['image_prompt_job_id']);
        $this->assertSame('generating', $post->fresh()->generation_status->value);

        (new ProcessSocialPostCaptionJob($queued['caption_job_id'], $queued['generation_no']))
            ->handle(app(\App\Services\Marketing\SocialPostAiWorkerService::class));
        $post->refresh();
        $this->assertNotEmpty($post->caption);
        $this->assertSame('partial', $post->generation_status->value);

        (new ProcessSocialPostImagePromptJob($queued['image_prompt_job_id'], $queued['generation_no']))
            ->handle(app(\App\Services\Marketing\SocialPostAiWorkerService::class));
        $imagePromptImport = AiImport::query()->where('operation', 'social_image_prompt')->firstOrFail();
        $imageImport = AiImport::query()->where('operation', 'social_image')->firstOrFail();
        $this->assertSame('ready', $imagePromptImport->fresh()->status);
        $this->assertSame('queued', $imageImport->fresh()->status);
        $this->assertSame('partial', $post->fresh()->generation_status->value);
    }

    /**
     * Hai branch thất bại phải chuyển aggregate sang failed.
     *
     * Input: component statuses.
     * Output: SocialPostGenerationStatus::Failed.
     */
    public function test_both_failed_generation_is_failed(): void
    {
        $status = app(SocialPostGenerationStateService::class)->statusFor('failed', 'failed');

        $this->assertSame('failed', $status->value);
    }

    /**
     * Tạo actor/product/campaign/post tối thiểu cho workflow.
     *
     * Input: isolated database.
     * Output: [User, SocialPost] có product fact verified.
     */
    private function fixture(): array
    {
        $actor = User::factory()->create();
        $product = Product::query()->create([
            'name' => 'Thiệp cưới hoa sen', 'short_description' => 'Mẫu thiệp nét mảnh',
            'default_cta' => 'Inbox để xem mẫu', 'status' => 'active', 'created_by' => $actor->id,
        ]);
        $product->facts()->create([
            'fact_key' => 'style', 'fact_value' => 'hoa sen nét mảnh', 'source' => 'fixture',
            'verified' => true, 'verified_by' => $actor->id, 'verified_at' => now(),
        ]);
        $campaign = Campaign::query()->create([
            'name' => 'Mẫu thiệp tháng 12', 'message' => 'Chuẩn bị sớm cho ngày trọng đại.',
            'status' => 'active', 'created_by' => $actor->id,
        ]);
        $campaign->products()->attach($product->id);
        $post = app(SocialPostLifecycleService::class)->create([
            'campaign_id' => $campaign->id, 'product_id' => $product->id,
            'caption' => null, 'target_account_ids' => [],
        ], $actor->id);

        return [$actor, $post];
    }
}
