<?php

namespace Tests\Feature;

use App\Enums\MediaAssetField;
use App\Models\AiArticleEvaluation;
use App\Models\AiImport;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialPostVariant;
use App\Models\SocialPublication;
use App\Models\User;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm lệnh purge AI/Social chỉ xóa đúng phạm vi.
 * =====================================================================
 * Xác nhận Social Post chưa publish bị dọn cùng run, còn post đã có
 * external_post_id được giữ nguyên cùng run nguồn.
 * =====================================================================
 */
final class PurgeAiSocialDataCommandTest extends TestCase
{
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Input: owner và SocialPost. Output: run social ready gắn applied_target_id. */
    private function makeSocialRun(User $actor, SocialPost $post, string $status = 'ready'): AiImport
    {
        return AiImport::query()->create([
            'created_by' => $actor->id,
            'source_url' => '',
            'source_text' => '',
            'status' => $status,
            'operation' => 'social_caption',
            'input_json' => ['target_type' => 'social_post', 'social_post_id' => $post->getKey(), 'generation_no' => 0],
            'result_json' => ['draft' => ['content_html' => '<p>Caption.</p>']],
            'applied_target_id' => $post->getKey(),
            'completed_at' => now(),
            'expires_at' => now()->addDays(2),
        ]);
    }

    /** Input: owner. Output: SocialPost có variant và một ảnh gallery. */
    private function makePost(User $actor): array
    {
        $post = SocialPost::query()->create([
            'caption' => 'Caption.',
            'created_by' => $actor->id,
            'generation_no' => 0,
        ]);
        $variant = SocialPostVariant::query()->create([
            'social_post_id' => $post->getKey(),
            'platform' => 'facebook',
        ]);
        $asset = MediaAsset::factory()->image()->create(['created_by' => $actor->id]);
        MediaAssetUsage::query()->create([
            'media_asset_id' => $asset->id,
            'linkable_type' => 'social_post_variant',
            'linkable_id' => $variant->getKey(),
            'field' => MediaAssetField::SocialPostFacebookImages,
            'sort_order' => 0,
        ]);

        return [$post, $variant];
    }

    /** Input: post. Output: target + publication đã có external_post_id. */
    private function publish(SocialPost $post, User $actor): void
    {
        $account = SocialAccount::query()->create([
            'provider' => 'facebook',
            'account_type' => 'page',
            'external_id' => 'purge-page',
            'display_name' => 'Purge page',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);
        $target = SocialPostTarget::query()->create([
            'social_post_id' => $post->getKey(),
            'social_account_id' => $account->getKey(),
            'platform' => 'facebook',
        ]);
        SocialPublication::query()->create([
            'social_post_target_id' => $target->getKey(),
            'idempotency_key' => 'purge-published-key',
            'status' => 'published',
            'external_post_id' => 'external-1',
        ]);
    }

    public function test_unpublished_ai_social_post_and_run_are_purged(): void
    {
        $actor = User::factory()->create(['status' => 'active']);
        [$post, $variant] = $this->makePost($actor);
        $run = $this->makeSocialRun($actor, $post);
        AiArticleEvaluation::query()->create([
            'run_id' => $run->id,
            'generation_no' => 1,
            'candidate_hash' => hash('sha256', 'candidate'),
            'rubric_version' => 'test-rubric',
            'status' => 'queued',
            'expires_at' => now()->addDays(2),
        ]);

        $this->artisan('ai-social:purge', ['--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('social_posts', ['id' => $post->getKey()]);
        $this->assertDatabaseMissing('social_post_variants', ['id' => $variant->getKey()]);
        $this->assertDatabaseMissing('ai_imports', ['id' => $run->id]);
        $this->assertDatabaseMissing('ai_article_evaluations', ['run_id' => $run->id]);
        $this->assertDatabaseMissing('media_asset_usages', [
            'linkable_type' => 'social_post_variant',
            'linkable_id' => $variant->getKey(),
        ]);
        $this->assertDatabaseHas('users', ['id' => $actor->id]);
    }

    public function test_published_ai_social_post_and_provenance_are_retained(): void
    {
        $actor = User::factory()->create(['status' => 'active']);
        [$post] = $this->makePost($actor);
        $run = $this->makeSocialRun($actor, $post);
        $this->publish($post, $actor);

        $this->artisan('ai-social:purge', ['--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('social_posts', ['id' => $post->getKey()]);
        $this->assertDatabaseHas('ai_imports', ['id' => $run->id]);
        $this->assertDatabaseHas('social_publications', ['external_post_id' => 'external-1']);
    }

    public function test_dry_run_does_not_delete_anything(): void
    {
        $actor = User::factory()->create(['status' => 'active']);
        [$post] = $this->makePost($actor);
        $run = $this->makeSocialRun($actor, $post);

        $this->artisan('ai-social:purge', ['--dry-run' => true])
            ->expectsOutputToContain('đủ điều kiện dọn')
            ->assertSuccessful();

        $this->assertDatabaseHas('social_posts', ['id' => $post->getKey()]);
        $this->assertDatabaseHas('ai_imports', ['id' => $run->id]);
    }

    public function test_post_target_runs_are_not_touched(): void
    {
        $actor = User::factory()->create(['status' => 'active']);
        [$post] = $this->makePost($actor);
        $run = AiImport::query()->create([
            'created_by' => $actor->id,
            'source_url' => '',
            'source_text' => '',
            'status' => 'ready',
            'operation' => 'create',
            'input_json' => ['target_type' => 'post'],
            'result_json' => ['draft' => ['content_html' => '<p>Bài.</p>']],
            'completed_at' => now(),
            'expires_at' => now()->addDays(2),
        ]);
        $this->makeSocialRun($actor, $post);

        $this->artisan('ai-social:purge', ['--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('ai_imports', ['id' => $run->id]);
    }
}
