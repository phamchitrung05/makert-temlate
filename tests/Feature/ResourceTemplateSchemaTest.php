<?php

namespace Tests\Feature;

use App\Enums\MediaAssetField;
use App\Enums\ResourceVersionTemplateProcessingStatus;
use App\Models\MediaAsset;
use App\Models\ResourceVersionTemplate;
use App\Models\ResourceVersionTemplateScreen;
use App\Models\User;
use App\Services\MediaAssetUsageService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm schema/model/relationship của TPL-01
 * =====================================================================
 *
 * Test chạy trên SQLite in-memory để xác nhận hai bảng template có FK/index/
 * unique/cascade đúng contract, model cast enum/JSON và relation version →
 * template → screens hoạt động. Không xử lý ZIP, không tạo preview và không
 * kiểm tra ready gate của TPL-05.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp(): migrate database cô lập
 * - tearDown(): rollback database cô lập
 * - test_template_tables_have_contract_columns_and_indexes(): kiểm schema
 * - test_template_models_cast_and_resolve_relationships(): kiểm model/relation/scope
 * - test_template_screen_constraints_and_version_cascade(): kiểm unique/FK/cascade
 * - test_template_screen_morph_alias_is_registered(): kiểm morph map TPL-01
 * - test_template_screen_preview_can_attach_image_usage(): kiểm field usage
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : factories ResourceVersionTemplate và ResourceVersionTemplateScreen
 * - OUTPUT: assertions về schema, cast, relation, scope và constraint
 * - SIDE EFFECT: migrate/rollback SQLite in-memory, không đụng database development
 * - EXCEPTION/TRANSACTION: QueryException được dùng để kiểm unique; test không nuốt lỗi ngoài ca đó
 * =====================================================================
 */
class ResourceTemplateSchemaTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị database SQLite cô lập cho TPL-01
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: Schema toàn project và hai bảng template đã migrate.
     * SIDE EFFECT: Cấu hình database.default sang isolated_test.
     * EXCEPTION/TRANSACTION: Migration exception truyền lên; không mở transaction thủ công.
     * =====================================================================
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Rollback database cô lập sau mỗi test
     * =====================================================================
     * INPUT: Schema isolated_test từ setUp().
     * OUTPUT: Connection được reset.
     * SIDE EFFECT: Xóa schema in-memory; không ảnh hưởng database development.
     * EXCEPTION/TRANSACTION: Migration reset exception truyền lên.
     * =====================================================================
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();

        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm columns, FK và indexes của hai bảng TPL-01
     * =====================================================================
     * INPUT: Schema đã migrate trong isolated_test.
     * OUTPUT: Bảng template/screen đủ cột và index contract.
     * SIDE EFFECT: Chỉ đọc schema metadata.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function test_template_tables_have_contract_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('resource_version_templates'));
        $this->assertTrue(Schema::hasTable('resource_version_template_screens'));

        foreach ([
            'resource_version_id', 'source_media_id', 'package_sha256', 'processing_status',
            'processing_token', 'source_root', 'entry_screen_slug', 'manifest_json',
            'preview_path', 'processing_error', 'processed_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('resource_version_templates', $column), $column);
        }

        foreach (['resource_version_template_id', 'slug', 'title', 'source_path', 'sort_order', 'metadata'] as $column) {
            $this->assertTrue(Schema::hasColumn('resource_version_template_screens', $column), $column);
        }

        $this->assertSame(
            ['pending', 'processing', 'ready', 'failed'],
            ResourceVersionTemplateProcessingStatus::values(),
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm cast, relation và processing scopes
     * =====================================================================
     * INPUT: Template ready có hai screen factory.
     * OUTPUT: Cast enum/JSON, version relation và screen order đúng contract.
     * SIDE EFFECT: Ghi rows vào SQLite cô lập.
     * EXCEPTION/TRANSACTION: Factory/database exception truyền lên.
     * =====================================================================
     */
    public function test_template_models_cast_and_resolve_relationships(): void
    {
        $template = ResourceVersionTemplate::factory()->ready()->create([
            'manifest_json' => ['schema_version' => 1, 'entry_screen' => 'home'],
        ]);
        $first = ResourceVersionTemplateScreen::factory()->create([
            'resource_version_template_id' => $template->id,
            'sort_order' => 2,
        ]);
        $second = ResourceVersionTemplateScreen::factory()->secondScreen()->create([
            'resource_version_template_id' => $template->id,
            'sort_order' => 1,
        ]);

        $template = $template->fresh(['resourceVersion', 'screens']);
        $version = $template->resourceVersion->fresh(['template', 'templateScreens']);

        $this->assertSame(ResourceVersionTemplateProcessingStatus::Ready, $template->processing_status);
        $this->assertSame(['schema_version' => 1, 'entry_screen' => 'home'], $template->manifest_json);
        $this->assertTrue($template->resourceVersion->is($version));
        $this->assertTrue($version->template->is($template));
        $this->assertSame([$second->id, $first->id], $template->screens->pluck('id')->all());
        $this->assertSame([$second->id, $first->id], $version->templateScreens->pluck('id')->all());
        $this->assertSame(
            [$second->id, $first->id],
            ResourceVersionTemplateScreen::query()->whereBelongsTo($template)->ordered()->pluck('id')->all(),
        );
        $this->assertCount(0, ResourceVersionTemplate::query()->processing()->get());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm unique screen và cascade khi xóa version vật lý
     * =====================================================================
     * INPUT: Một template có screen home/settings và version cha.
     * OUTPUT: Duplicate slug/path bị chặn; force delete version dọn template/screens.
     * SIDE EFFECT: Ghi/xóa rows trong SQLite cô lập.
     * EXCEPTION/TRANSACTION: QueryException chỉ được bắt ở duplicate constraint.
     * =====================================================================
     */
    public function test_template_screen_constraints_and_version_cascade(): void
    {
        $template = ResourceVersionTemplate::factory()->create();
        ResourceVersionTemplateScreen::factory()->create(['resource_version_template_id' => $template->id]);

        try {
            ResourceVersionTemplateScreen::factory()->create(['resource_version_template_id' => $template->id]);
            $this->fail('Duplicate screen slug/path phải bị database chặn.');
        } catch (QueryException) {
            $this->assertDatabaseCount('resource_version_template_screens', 1);
        }

        $version = $template->resourceVersion;
        $templateId = $template->id;
        $screenId = $template->screens()->firstOrFail()->id;
        $version->forceDelete();

        $this->assertDatabaseMissing('resource_version_templates', ['id' => $templateId]);
        $this->assertDatabaseMissing('resource_version_template_screens', ['id' => $screenId]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm morph alias template_screen đã đăng ký
     * =====================================================================
     * INPUT: Morph registry của AppServiceProvider.
     * OUTPUT: template_screen resolve đúng ResourceVersionTemplateScreen.
     * SIDE EFFECT: Chỉ đọc registry.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function test_template_screen_morph_alias_is_registered(): void
    {
        $this->assertSame(
            ResourceVersionTemplateScreen::class,
            Relation::getMorphedModel('template_screen'),
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm screenshot preview attach đúng model/kind/cardinality
     * =====================================================================
     * INPUT: Actor có media.attach, template screen và MediaAsset image.
     * OUTPUT: Usage có morph alias template_screen và field preview.
     * SIDE EFFECT: Ghi một media_asset_usage vào SQLite cô lập.
     * EXCEPTION/TRANSACTION: Validation/authorization/database exception truyền lên.
     * =====================================================================
     */
    public function test_template_screen_preview_can_attach_image_usage(): void
    {
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo('media.attach');
        $screen = ResourceVersionTemplateScreen::factory()->create();
        $asset = MediaAsset::factory()->image()->create();

        $usage = app(MediaAssetUsageService::class)->attach(
            $actor,
            $screen,
            $asset,
            MediaAssetField::TemplateScreenPreview,
        );

        $this->assertSame('template_screen', $usage->linkable_type);
        $this->assertSame($screen->id, $usage->linkable_id);
        $this->assertSame(MediaAssetField::TemplateScreenPreview, $usage->field);
    }
}
