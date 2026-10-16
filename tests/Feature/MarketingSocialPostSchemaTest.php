<?php

namespace Tests\Feature;

use App\Enums\MediaAssetField;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostGenerationStatus;
use App\Enums\SocialPostReviewStatus;
use App\Models\Campaign;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\Product;
use App\Models\ProductFact;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử schema, quan hệ và audit của MP-01.
 * =====================================================================
 * Test khóa các bảng domain, caption canonical, encrypted token, MediaAsset
 * usage `social_post.image` và activity log marketing trước MP-02 trở đi.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp(): migrate schema cô lập và seed permission.
 * - tearDown(): rollback schema cô lập.
 * - test_marketing_schema_has_domain_tables_and_shared_caption_contract(): kiểm schema.
 * - test_models_cast_status_encrypt_token_and_link_social_image_usage(): kiểm model/relation.
 * - test_social_post_mutation_is_written_to_marketing_activity_log(): kiểm audit.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : model/fixture MP-01 trên SQLite in-memory.
 * - OUTPUT: assertions schema, cast, quan hệ, encryption và activity log.
 * - SIDE EFFECT: tạo dữ liệu chỉ trong connection isolated_test.
 * =====================================================================
 */
class MarketingSocialPostSchemaTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * Chuẩn bị schema Marketing và catalog permission cho test.
     *
     * Input: PHPUnit application container.
     * Output: database isolated_test đã migrate và permission cache sạch.
     * Side effect: tạo SQLite in-memory, không đụng database development.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * Rollback schema Marketing sau test.
     *
     * Input: connection isolated_test từ setUp().
     * Output: database in-memory được giải phóng.
     * Side effect: rollback migration và purge connection.
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();

        parent::tearDown();
    }

    /**
     * Kiểm tra bảng MP-01 và caption canonical không có platform_caption.
     *
     * Input: schema đã migrate.
     * Output: các bảng/cột bắt buộc tồn tại, cột platform_caption vắng mặt.
     * Side effect: chỉ đọc schema.
     */
    public function test_marketing_schema_has_domain_tables_and_shared_caption_contract(): void
    {
        foreach ([
            'products', 'product_facts', 'campaigns', 'campaign_products',
            'social_accounts', 'social_posts', 'social_post_targets',
            'social_publications', 'social_post_versions', 'daily_content_plans',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }

        $this->assertTrue(Schema::hasColumn('social_posts', 'caption'));
        $this->assertTrue(Schema::hasColumn('social_posts', 'generation_status'));
        $this->assertTrue(Schema::hasColumn('social_posts', 'review_status'));
        $this->assertTrue(Schema::hasColumn('social_posts', 'publication_status'));
        $this->assertFalse(Schema::hasColumn('social_posts', 'platform_caption'));
        $this->assertTrue(Schema::hasColumn('product_facts', 'source'));
        $this->assertTrue(Schema::hasColumn('product_facts', 'verified'));
        $this->assertTrue(Schema::hasColumn('social_accounts', 'access_token'));
    }

    /**
     * Kiểm tra enum cast, encrypted token và MediaAsset usage của Social Post.
     *
     * Input: Product/Campaign/Account/Post/MediaAsset fixture.
     * Output: relations và cast trả đúng enum/value, asset link dùng morph alias.
     * Side effect: ghi fixture vào database isolated_test.
     */
    public function test_models_cast_status_encrypt_token_and_link_social_image_usage(): void
    {
        $actor = User::factory()->create();
        $product = Product::query()->create([
            'name' => 'Thiệp cưới hoa sen',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);
        $fact = ProductFact::query()->create([
            'product_id' => $product->id,
            'fact_key' => 'style',
            'fact_value' => 'hoa sen nét mảnh',
            'source' => 'fixture',
            'verified' => true,
            'verified_by' => $actor->id,
            'verified_at' => now(),
        ]);
        $campaign = Campaign::query()->create([
            'name' => 'Mẫu thiệp cưới tháng 12',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);
        $campaign->products()->attach($product->id);

        $account = SocialAccount::query()->create([
            'provider' => SocialPlatform::Facebook,
            'account_type' => 'page',
            'external_id' => 'page-test-001',
            'display_name' => 'Page test',
            'access_token' => 'secret-token',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);
        $post = SocialPost::query()->create([
            'campaign_id' => $campaign->id,
            'product_id' => $product->id,
            'caption' => 'Caption dùng chung cho mọi target.',
            'generation_status' => SocialPostGenerationStatus::Ready,
            'review_status' => SocialPostReviewStatus::Draft,
            'created_by' => $actor->id,
        ]);
        $target = SocialPostTarget::query()->create([
            'social_post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform' => SocialPlatform::Facebook,
        ]);
        $asset = MediaAsset::factory()->image()->create(['created_by' => $actor->id]);
        MediaAssetUsage::query()->create([
            'media_asset_id' => $asset->id,
            'linkable_type' => 'social_post',
            'linkable_id' => $post->id,
            'field' => MediaAssetField::SocialPostImage,
            'sort_order' => 0,
        ]);

        $this->assertSame('hoa sen nét mảnh', $product->facts()->findOrFail($fact->id)->fact_value);
        $this->assertSame(SocialPlatform::Facebook, $account->fresh()->provider);
        $this->assertSame('secret-token', $account->fresh()->access_token);
        $this->assertNotSame('secret-token', DB::table('social_accounts')->whereKey($account->id)->value('access_token'));
        $this->assertSame(SocialPostGenerationStatus::Ready, $post->fresh()->generation_status);
        $this->assertSame(SocialPostReviewStatus::Draft, $post->fresh()->review_status);
        $this->assertSame($target->id, $post->fresh()->targets()->firstOrFail()->id);
        $this->assertSame($asset->id, $post->fresh()->mediaAssetsForField(MediaAssetField::SocialPostImage)->firstOrFail()->id);
    }

    /**
     * Kiểm tra mutation Social Post được ghi ở log marketing.
     *
     * Input: admin actor và SocialPost draft.
     * Output: activity_log có subject alias social_post và log_name marketing.
     * Side effect: ghi activity log bounded trong database.
     */
    public function test_social_post_mutation_is_written_to_marketing_activity_log(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor, 'admin');

        $post = SocialPost::query()->create([
            'caption' => 'Draft đầu tiên',
            'created_by' => $actor->id,
        ]);
        $post->update(['caption' => 'Draft đã chỉnh sửa']);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'marketing',
            'subject_type' => 'social_post',
            'subject_id' => (string) $post->id,
        ]);
    }
}
