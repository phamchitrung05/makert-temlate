<?php

namespace Tests\Feature;

use App\Enums\MediaAssetField;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostReviewStatus;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Marketing\SocialPostGenerationStateService;
use App\Services\Marketing\SocialPostLifecycleService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

final class MarketingSocialPostVariantWorkflowTest extends TestCase
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

    public function test_facebook_target_is_linked_to_variant_and_review_requires_three_images(): void
    {
        $actor = User::factory()->create();
        $account = SocialAccount::query()->create([
            'provider' => SocialPlatform::Facebook,
            'account_type' => 'page',
            'external_id' => 'variant-workflow-page',
            'display_name' => 'Variant workflow page',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);
        $lifecycle = app(SocialPostLifecycleService::class);
        $post = $lifecycle->create([
            'caption' => null,
            'target_account_ids' => [$account->id],
        ], $actor->id);

        $target = $post->targets()->firstOrFail();
        $this->assertNotNull($target->social_post_variant_id);
        $variant = $post->variants()->firstOrFail();

        $lifecycle->update($post, [
            'facebook_content' => [
                'title' => 'Tag bánh kem',
                'body' => 'Nội dung Facebook đã sẵn sàng.',
                'bullet_points' => ['In sắc nét'],
                'cta' => 'Inbox để nhận mẫu.',
                'contact' => ['address' => null, 'phone' => null, 'website' => null],
                'hashtags' => ['#TagBanhKem'],
                'link_url' => null,
            ],
        ], $actor->id);

        $assets = MediaAsset::factory()->count(3)->image()->create(['created_by' => $actor->id]);
        foreach ($assets as $sortOrder => $asset) {
            MediaAssetUsage::query()->create([
                'media_asset_id' => $asset->id,
                'linkable_type' => 'social_post_variant',
                'linkable_id' => $variant->id,
                'field' => MediaAssetField::SocialPostFacebookImages,
                'sort_order' => $sortOrder,
            ]);
        }
        app(SocialPostGenerationStateService::class)->refresh($post->fresh(), 0);

        $ready = $post->fresh();
        $this->assertSame('ready', $ready->generation_status->value);
        $reviewed = $lifecycle->transition($ready, 'submit', $actor);
        $this->assertSame(SocialPostReviewStatus::PendingReview, $reviewed->review_status);
        $this->assertSame(SocialPostReviewStatus::PendingReview, $reviewed->variants()->firstOrFail()->review_status);
    }

    public function test_two_gallery_images_are_not_enough_for_review(): void
    {
        $actor = User::factory()->create();
        $post = app(SocialPostLifecycleService::class)->create(['caption' => 'Caption'], $actor->id);
        $variant = $post->variants()->firstOrFail();
        $variant->forceFill([
            'content_json' => ['body' => 'Caption', 'bullet_points' => [], 'hashtags' => []],
            'content_status' => 'ready',
            'generation_status' => 'partial',
        ])->save();
        foreach (MediaAsset::factory()->count(2)->image()->create(['created_by' => $actor->id]) as $sortOrder => $asset) {
            MediaAssetUsage::query()->create([
                'media_asset_id' => $asset->id,
                'linkable_type' => 'social_post_variant',
                'linkable_id' => $variant->id,
                'field' => MediaAssetField::SocialPostFacebookImages,
                'sort_order' => $sortOrder,
            ]);
        }
        app(SocialPostGenerationStateService::class)->refresh($post->fresh(), 0);

        $this->expectException(ValidationException::class);
        app(SocialPostLifecycleService::class)->transition($post->fresh(), 'submit', $actor);
    }
}
