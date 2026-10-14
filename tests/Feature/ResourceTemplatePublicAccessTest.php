<?php

namespace Tests\Feature;

use App\Enums\MediaAssetField;
use App\Enums\MediaAssetVisibility;
use App\Enums\MediaScanStatus;
use App\Enums\ResourceStatus;
use App\Enums\ResourceType;
use App\Enums\ResourceVersionStatus;
use App\Enums\ResourceVersionTemplateProcessingStatus;
use App\Enums\ResourceVisibility;
use App\Models\MediaAsset;
use App\Models\Resource;
use App\Models\ResourceVersion;
use App\Models\ResourceVersionTemplate;
use App\Models\ResourceVersionTemplateScreen;
use App\Models\User;
use App\Models\Customer;
use App\Services\MediaAssetUsageService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm public catalog/preview/download/share TPL-09/TPL-10
 * =====================================================================
 * Test dựng version ready với artifact private synthetic để kiểm policy public,
 * version pin, grant expiry/reuse và download counter không cần browser/network.
 * =====================================================================
 */
final class ResourceTemplatePublicAccessTest extends TestCase
{
    use UsesIsolatedDatabase;

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

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

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

    /** Input: none. Output: published public resource + one ready default version. */
    private function fixture(): array
    {
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo(['resource_versions.manage', 'media.attach']);
        $this->flushPermissionCache();
        $resource = Resource::factory()->create([
            'type' => ResourceType::Template,
            'status' => ResourceStatus::Published,
            'visibility' => ResourceVisibility::Public,
            'published_at' => now()->subMinute(),
            'title' => 'Weather Template',
        ]);
        $resource->primarySlug()?->update(['slug' => 'weather-template']);
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
}
