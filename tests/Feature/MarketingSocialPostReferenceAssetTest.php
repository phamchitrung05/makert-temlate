<?php

namespace Tests\Feature;

use App\Enums\MediaAssetField;
use App\Models\Campaign;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Marketing\SocialPostContextBuilder;
use App\Services\MediaAssetUsageService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử reference image của Product và ranh giới Social.
 * =====================================================================
 * Product reference images đi qua MediaAssetUsageService và được trả theo thứ
 * tự chọn. Social Post không còn phụ thuộc Product nên context AI của post
 * không chứa asset tham chiếu của Product.
 * =====================================================================
 */
final class MarketingSocialPostReferenceAssetTest extends TestCase
{
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Product keeps its gallery, but the independent Social context never inherits Product assets. */
    public function test_product_reference_images_are_not_inherited_by_social_context(): void
    {
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo('media.attach');
        $this->flushPermissionCache();

        $product = Product::query()->create([
            'name' => 'Hộp bánh kem',
            'short_description' => 'Hộp giấy in logo theo yêu cầu.',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);
        $assets = MediaAsset::factory()->image()->count(3)->create(['created_by' => $actor->id]);

        app(MediaAssetUsageService::class)->replaceByIds(
            $actor,
            $product,
            MediaAssetField::ProductReferenceImages,
            [$assets[2]->id, $assets[0]->id, $assets[1]->id],
        );

        $this->assertSame(
            [$assets[2]->id, $assets[0]->id, $assets[1]->id],
            $product->fresh()->mediaAssetUsagesForField(MediaAssetField::ProductReferenceImages)
                ->orderBy('sort_order')->pluck('media_asset_id')->all(),
        );

        $campaign = Campaign::query()->create([
            'name' => 'Bộ nhận diện tiệm bánh',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);
        $campaign->products()->attach($product->id);
        $post = SocialPost::query()->create([
            'campaign_id' => $campaign->id,
            'created_by' => $actor->id,
        ]);

        $context = app(SocialPostContextBuilder::class)->build($post);

        $this->assertArrayNotHasKey('reference_asset_ids', $context);
        $this->assertArrayNotHasKey('product', $context);
        $this->assertArrayNotHasKey('facts', $context);
        $this->assertSame((int) $campaign->getKey(), $context['campaign']['id']);
        $this->assertNotEmpty($context['context_hash']);
    }

    /** Product API nhận, trả và clear gallery reference theo thứ tự request. */
    public function test_product_api_replaces_reference_gallery(): void
    {
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo(['social_posts.create', 'social_posts.update', 'media.attach']);
        $this->flushPermissionCache();
        Auth::forgetGuards();
        $token = $actor->createToken('product-reference-test', ['admin'])->plainTextToken;
        $assets = MediaAsset::factory()->image()->count(2)->create(['created_by' => $actor->id]);

        $response = $this->withToken($token)->postJson('/api/admin/marketing/products', [
            'name' => 'Tem nhãn hộp bánh',
            'status' => 'active',
            'reference_asset_ids' => [$assets[1]->id, $assets[0]->id],
        ])->assertCreated();

        $productId = (int) $response->json('data.id');
        $response->assertJsonPath('data.reference_asset_ids', [$assets[1]->id, $assets[0]->id]);

        $this->withToken($token)->putJson("/api/admin/marketing/products/{$productId}", [
            'name' => 'Tem nhãn hộp bánh',
            'status' => 'active',
            'reference_asset_ids' => [],
        ])->assertOk()->assertJsonPath('data.reference_asset_ids', []);

        $this->assertDatabaseMissing('media_asset_usages', [
            'linkable_type' => 'product',
            'linkable_id' => $productId,
            'field' => MediaAssetField::ProductReferenceImages->value,
        ]);
    }
}
