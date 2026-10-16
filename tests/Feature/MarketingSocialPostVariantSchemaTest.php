<?php

namespace Tests\Feature;

use App\Enums\MediaAssetField;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostGenerationStatus;
use App\Enums\SocialPostReviewStatus;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialPostVariant;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * Kiểm contract MP-01 của platform variant và Facebook gallery.
 */
final class MarketingSocialPostVariantSchemaTest extends TestCase
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

    public function test_variant_schema_and_target_foreign_key_exist(): void
    {
        foreach (['social_post_variants', 'social_post_targets'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        foreach ([
            'social_post_id', 'platform', 'format', 'content_json', 'content_status',
            'generation_status', 'review_status', 'current_version_no',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('social_post_variants', $column), "Missing variant column: {$column}");
        }

        $this->assertTrue(Schema::hasColumn('social_post_targets', 'social_post_variant_id'));
    }

    public function test_variant_relations_and_ordered_facebook_gallery_are_typed(): void
    {
        $actor = User::factory()->create();
        $post = SocialPost::query()->create([
            'caption' => 'Caption legacy',
            'created_by' => $actor->id,
        ]);
        $variant = SocialPostVariant::query()->create([
            'social_post_id' => $post->id,
            'platform' => SocialPlatform::Facebook,
            'format' => 'feed',
            'content_json' => ['body' => 'Nội dung Facebook', 'hashtags' => ['#demo']],
            'content_status' => SocialPostGenerationStatus::Ready,
            'generation_status' => SocialPostGenerationStatus::Ready,
            'review_status' => SocialPostReviewStatus::Draft,
        ]);
        $account = SocialAccount::query()->create([
            'provider' => SocialPlatform::Facebook,
            'account_type' => 'page',
            'external_id' => 'variant-page-001',
            'display_name' => 'Variant page',
            'access_token' => 'secret-token',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);
        $target = SocialPostTarget::query()->create([
            'social_post_id' => $post->id,
            'social_post_variant_id' => $variant->id,
            'social_account_id' => $account->id,
            'platform' => SocialPlatform::Facebook,
        ]);
        $first = MediaAsset::factory()->image()->create(['created_by' => $actor->id]);
        $second = MediaAsset::factory()->image()->create(['created_by' => $actor->id]);
        MediaAssetUsage::query()->create([
            'media_asset_id' => $second->id,
            'linkable_type' => 'social_post_variant',
            'linkable_id' => $variant->id,
            'field' => MediaAssetField::SocialPostFacebookImages,
            'sort_order' => 1,
        ]);
        MediaAssetUsage::query()->create([
            'media_asset_id' => $first->id,
            'linkable_type' => 'social_post_variant',
            'linkable_id' => $variant->id,
            'field' => MediaAssetField::SocialPostFacebookImages,
            'sort_order' => 0,
        ]);

        $freshVariant = $post->fresh()->variants()->firstOrFail();
        $this->assertSame(SocialPlatform::Facebook, $freshVariant->platform);
        $this->assertSame(SocialPostGenerationStatus::Ready, $freshVariant->generation_status);
        $this->assertSame(['#demo'], $freshVariant->content_json['hashtags']);
        $this->assertSame($target->id, $freshVariant->targets()->firstOrFail()->id);
        $this->assertSame([$first->id, $second->id], $freshVariant
            ->mediaAssetUsagesForField(MediaAssetField::SocialPostFacebookImages)
            ->orderBy('sort_order')
            ->pluck('media_asset_id')
            ->all());
        $this->assertSame($variant->id, $target->fresh()->socialPostVariant->id);
    }
}
