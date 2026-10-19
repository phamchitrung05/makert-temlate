<?php

namespace Tests\Feature;

use App\Enums\MediaAssetField;
use App\Enums\MediaAssetVisibility;
use App\Enums\MediaScanStatus;
use App\Enums\ResourceStatus;
use App\Enums\ResourceType;
use App\Enums\ResourceVersionStatus;
use App\Enums\ResourceVisibility;
use App\Models\Category;
use App\Models\Customer;
use App\Models\MediaAsset;
use App\Models\Resource;
use App\Models\ResourceVersion;
use App\Models\ResourceVersionTemplate;
use App\Models\ResourceVersionTemplateScreen;
use App\Models\User;
use App\Services\MediaAssetUsageService;
use App\Services\ResourceVersionPackageGuard;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm public catalog/preview/download/share TPL-09/TPL-10
 * =====================================================================
 * Test dựng version ready với artifact private synthetic để kiểm policy public,
 * version pin, grant expiry/reuse và download counter không cần browser/network.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp(), tearDown(): chuẩn bị và dọn database test cô lập.
 * - test_public_catalog_and_latest_preview_pin_default_version(): kiểm catalog và preview.
 * - test_public_detail_uses_category_primary_slug_for_current_locale(): kiểm slug danh mục.
 * - test_public_detail_allows_category_without_primary_slug(): kiểm danh mục thiếu slug.
 * - test_catalog_and_sitemap_query_counts_do_not_grow_with_resource_count(): kiểm truy vấn theo lô.
 * - test_detail_query_count_does_not_grow_with_category_count(): kiểm eager load slug danh mục.
 * - test_catalog_does_not_expose_versions_with_unsafe_packages(): kiểm package không an toàn.
 * - test_package_guard_reloads_scan_status_after_relations_were_loaded(): kiểm gate đọc mới.
 * - test_hidden_resources_are_absent_from_catalog_detail_and_sitemap(): kiểm các trạng thái không public.
 * - test_verified_customer_download_is_pinned_and_counted_once_on_retry(): kiểm lượt tải.
 * - test_share_preview_is_separate_from_download_and_revoke_is_immediate(): kiểm share.
 * - fixture(): dựng resource public với version sẵn sàng.
 * - queriesFor(): ghi lại truy vấn của một request public.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dữ liệu giả lập và request HTTP tới catalog, preview, download, share.
 * - OUTPUT: assertion phản hồi và dữ liệu trên database/storage test cô lập.
 * =====================================================================
 */
final class ResourceTemplatePublicAccessTest extends TestCase
{
    use UsesIsolatedDatabase;

    /** Input: không có. Output: database cô lập, quyền và storage giả lập cho mỗi test. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('media_public');
        Storage::fake('media_private');
        config()->set('media-library.asset_disks.public', 'media_public');
        config()->set('media-library.asset_disks.private', 'media_private');
    }

    /** Input: môi trường test hiện tại. Output: giải phóng database và ứng dụng test. */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Input: resource public giả lập. Output: xác nhận detail, sitemap và preview hoạt động. */
    public function test_public_catalog_and_latest_preview_pin_default_version(): void
    {
        [$resource, $version] = $this->fixture();

        $this->get('/resources/weather-template')
            ->assertOk()
            ->assertSee('Weather Template');
        $this->get('/sitemap.xml')->assertOk()->assertSee('weather-template');

        $response = $this->get('/resources/weather-template/preview');
        $response->assertRedirect();
        $this->assertStringContainsString('/template-preview/', (string) $response->headers->get('Location'));
    }

    /** Input: danh mục có slug cũ và nhiều locale. Output: detail 200 với slug primary đúng locale. */
    public function test_public_detail_uses_category_primary_slug_for_current_locale(): void
    {
        config()->set('app.locale', 'vi');
        [$resource] = $this->fixture();
        $category = Category::factory()->create(['name' => 'Old Category']);
        $category->slugs()->create([
            'slug' => 'english-category', 'locale' => 'en', 'is_primary' => true,
        ]);
        $category->update(['name' => 'Current Category']);
        $resource->categories()->attach($category);

        $response = $this->get('/resources/weather-template')->assertOk();

        $this->assertSame([
            ['name' => 'Current Category', 'slug' => 'current-category'],
        ], $response->viewData('resource')['categories']);
    }

    /** Input: danh mục chỉ còn slug cũ và slug locale khác. Output: detail 200 với slug null. */
    public function test_public_detail_allows_category_without_primary_slug(): void
    {
        config()->set('app.locale', 'vi');
        [$resource] = $this->fixture();
        $category = Category::factory()->create(['name' => 'Category Without Slug']);
        $category->slugs()->update(['is_primary' => false]);
        $category->slugs()->create([
            'slug' => 'english-category', 'locale' => 'en', 'is_primary' => true,
        ]);
        $resource->categories()->attach($category);

        $response = $this->get('/resources/weather-template')->assertOk();

        $this->assertSame([
            ['name' => 'Category Without Slug', 'slug' => null],
        ], $response->viewData('resource')['categories']);
    }

    /** Input: catalog tăng từ 1 lên 12 template. Output: số query catalog/sitemap không tăng theo số item. */
    public function test_catalog_and_sitemap_query_counts_do_not_grow_with_resource_count(): void
    {
        $this->fixture();
        $this->get('/resources')->assertOk();
        $singleCatalog = $this->queriesFor('/resources');
        $singleSitemap = $this->queriesFor('/sitemap.xml');
        foreach (range(1, 11) as $number) {
            $this->fixture($number);
        }

        $multipleCatalog = $this->queriesFor('/resources');
        $multipleSitemap = $this->queriesFor('/sitemap.xml');
        $items = $this->get('/resources')->assertOk()->viewData('items');

        $this->assertCount(12, $items);
        foreach ($items as $item) {
            $this->assertNotNull($item['default_version']);
            $this->assertNotNull($item['slug']);
        }
        $this->assertSame(count($singleCatalog), count($multipleCatalog), 'Catalog query count must stay constant for 1 and 12 cards. '.json_encode(array_count_values(array_column($multipleCatalog, 'query'))));
        $this->assertLessThanOrEqual(20, count($multipleCatalog));
        $this->assertSame(count($singleSitemap), count($multipleSitemap), 'Sitemap query count must stay constant for 1 and 12 resources.');
    }

    /** Input: detail tăng từ 1 lên 12 danh mục. Output: slug chính xác và số query không tăng. */
    public function test_detail_query_count_does_not_grow_with_category_count(): void
    {
        [$resource] = $this->fixture();
        $resource->categories()->attach(Category::factory()->create());
        $this->get('/resources/weather-template')->assertOk();
        $single = $this->queriesFor('/resources/weather-template');
        $resource->categories()->attach(Category::factory()->count(11)->create()->modelKeys());

        $multiple = $this->queriesFor('/resources/weather-template');

        $this->assertSame(count($single), count($multiple), 'Detail must batch category slugs.');
        $categories = $this->get('/resources/weather-template')->assertOk()->viewData('resource')['categories'];
        $this->assertCount(12, $categories);
        $this->assertNotContains(null, array_column($categories, 'slug'));
    }

    /** Input: package bị đổi visibility, scan, soft delete, thiếu hoặc trùng usage. Output: card không cấp version. */
    public function test_catalog_does_not_expose_versions_with_unsafe_packages(): void
    {
        foreach (['public', 'infected', 'deleted', 'missing', 'duplicate'] as $number => $state) {
            [, $version] = $this->fixture($number + 1);
            $usage = $version->mediaAssetUsages()->firstOrFail();
            $package = $usage->mediaAsset;
            match ($state) {
                'public' => $package->update(['visibility' => MediaAssetVisibility::Public]),
                'infected' => $package->getFirstMedia('library')->setCustomProperty('scan_status', 'infected')->save(),
                'deleted' => $package->delete(),
                'missing' => $usage->delete(),
                'duplicate' => $usage->replicate()->fill(['media_asset_id' => MediaAsset::factory()->archive()->create()->id])->save(),
            };
        }
        $this->fixture();

        $items = $this->get('/resources')->assertOk()->viewData('items')->getCollection()->keyBy('slug');

        $this->assertNotNull($items['weather-template']['default_version']);
        foreach (range(1, 5) as $number) {
            $this->assertNull($items['weather-template-'.$number]['default_version']);
        }
    }

    /** Input: scan đổi sau eager load. Output: gate preview/download vẫn kiểm DB hiện tại và từ chối. */
    public function test_package_guard_reloads_scan_status_after_relations_were_loaded(): void
    {
        [, $version] = $this->fixture();
        $version->load('mediaAssetUsages.mediaAsset.media');
        $media = $version->mediaAssetUsages->first()->mediaAsset->getFirstMedia('library');
        $media->fresh()->setCustomProperty('scan_status', 'infected')->save();

        $this->expectException(ValidationException::class);

        app(ResourceVersionPackageGuard::class)->cleanPackageFor($version);
    }

    /** Input: draft, archived, private, future, soft-deleted và ebook. Output: không lộ qua catalog/detail/sitemap. */
    public function test_hidden_resources_are_absent_from_catalog_detail_and_sitemap(): void
    {
        $this->fixture();
        $hiddenSlugs = [];
        foreach ([
            ['status' => 'draft'], ['status' => 'archived'], ['visibility' => 'private'],
            ['published_at' => now()->addDay()], ['deleted_at' => now()], ['type' => 'ebook'],
        ] as $number => $attributes) {
            $deleted = isset($attributes['deleted_at']);
            unset($attributes['deleted_at']);
            $resource = Resource::factory()->create(array_replace([
                'title' => 'Hidden Catalog '.$number, 'type' => 'template', 'status' => 'published',
                'visibility' => 'public', 'published_at' => now()->subMinute(),
            ], $attributes));
            $hiddenSlugs[] = $resource->primarySlug()->slug;
            if ($deleted) {
                $resource->delete();
            }
        }

        $this->assertCount(1, $this->get('/resources')->assertOk()->viewData('items'));
        $sitemap = $this->get('/sitemap.xml')->assertOk();
        foreach ($hiddenSlugs as $slug) {
            $this->get('/resources/'.$slug)->assertNotFound();
            $sitemap->assertDontSee($slug);
        }
    }

    /** Input: khách đã xác minh và resource public. Output: tải lại chỉ tính một lượt download. */
    public function test_verified_customer_download_is_pinned_and_counted_once_on_retry(): void
    {
        [$resource, $version] = $this->fixture();
        $customer = Customer::factory()->create([
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $grant = $this->actingAs($customer, 'customer')->get('/resources/weather-template/download');
        $grant->assertRedirect();
        $downloadUrl = $grant->headers->get('Location');
        $this->assertStringContainsString('/template-download/', $downloadUrl);
        $this->get($downloadUrl)->assertOk();
        $this->get($downloadUrl)->assertOk();
        $this->assertDatabaseHas('downloads', ['resource_version_id' => $version->id, 'status' => 'completed']);
        $this->assertSame(1, (int) $resource->fresh()->download_count);
    }

    /** Input: link share preview được tạo rồi thu hồi. Output: link cũ bị từ chối ngay với mã 410. */
    public function test_share_preview_is_separate_from_download_and_revoke_is_immediate(): void
    {
        [$resource] = $this->fixture();
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo('resource_versions.manage');
        $this->flushPermissionCache();
        $token = $actor->createToken('tpl-share-test', ['admin'])->plainTextToken;
        $version = $resource->versions()->firstOrFail();

        $created = $this->withToken($token)->postJson('/api/admin/resource-versions/'.$version->id.'/template/shares', [
            'capability' => 'preview', 'days' => 2,
        ])->assertCreated();
        $shareUrl = $created->json('data.url');
        $this->assertNotEmpty($shareUrl);
        $show = $this->get($shareUrl);
        $show->assertRedirect();
        $this->assertStringContainsString('/template-preview/', (string) $show->headers->get('Location'));

        $shareId = $created->json('data.id');
        $this->withToken($token)->deleteJson('/api/admin/template-shares/'.$shareId)->assertOk();
        $this->get($shareUrl)->assertStatus(410);
    }

    /** Input: số thứ tự để slug không trùng. Output: resource public và version mặc định sẵn sàng trong DB/storage test. */
    private function fixture(int $number = 0): array
    {
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo(['resource_versions.manage', 'media.attach']);
        $this->flushPermissionCache();
        $resource = Resource::factory()->create([
            'type' => ResourceType::Template,
            'status' => ResourceStatus::Published,
            'visibility' => ResourceVisibility::Public,
            'published_at' => now()->subMinute(),
            'title' => 'Weather Template'.($number ? ' '.$number : ''),
        ]);
        $resource->primarySlug()?->update(['slug' => 'weather-template'.($number ? '-'.$number : '')]);
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id, 'version' => '1.0.0',
            'status' => ResourceVersionStatus::Draft, 'is_default' => false, 'created_by' => $actor->id,
        ]);
        $package = MediaAsset::factory()->archive()->create([
            'visibility' => MediaAssetVisibility::Private, 'created_by' => $actor->id,
        ]);
        $bytes = 'template zip payload '.$version->id.Str::uuid();
        $package->addMediaFromString($bytes)->usingFileName('template.zip')->withCustomProperties([
            'scan_status' => MediaScanStatus::Clean->value,
            'checksum_sha256' => hash('sha256', $bytes),
        ])->toMediaCollection('library', $package->mediaDisk());
        app(MediaAssetUsageService::class)->attach($actor, $version, $package, MediaAssetField::ResourceVersionPackage);
        $token = (string) Str::uuid();
        $path = 'template-previews/'.$version->id.'/'.$token;
        File::ensureDirectoryExists(Storage::disk('media_private')->path($path));
        Storage::disk('media_private')->put($path.'/index.html', '<h1>weather</h1>');
        $template = ResourceVersionTemplate::factory()->ready()->create([
            'resource_version_id' => $version->id, 'source_media_id' => $package->id,
            'package_sha256' => hash('sha256', $bytes), 'processing_token' => $token,
            'entry_screen_slug' => 'home', 'manifest_json' => [
                'requires_entry_selection' => false, 'files' => ['index.html'], 'warnings' => [],
            ], 'preview_path' => $path,
        ]);
        ResourceVersionTemplateScreen::factory()->create([
            'resource_version_template_id' => $template->id, 'slug' => 'home',
            'title' => 'Home', 'source_path' => 'index.html', 'sort_order' => 0,
        ]);
        $version->forceFill(['status' => ResourceVersionStatus::Ready, 'is_default' => true])->save();

        return [$resource->fresh(), $version->fresh()];
    }

    /** Input: URL public. Output: danh sách SQL của request; luôn tắt query log sau khi đo. */
    private function queriesFor(string $path): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->get($path)->assertOk();

            return DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
    }
}
