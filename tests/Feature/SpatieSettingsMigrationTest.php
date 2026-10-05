<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\Settings\AiSettingsService;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm chứng chuyển dữ liệu settings và worker đọc giá trị mới.
 * CÁC HÀM/METHOD TRONG FILE: setUp(), tearDown(), test_*().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): dữ liệu key/value cũ -> typed Spatie payload
 * và rollback giữ giá trị; chỉ dùng SQLite cô lập.
 * =====================================================================
 */
class SpatieSettingsMigrationTest extends TestCase
{
    use UsesIsolatedDatabase;

    /** Input: PHPUnit lifecycle. Output: schema SQLite mới. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
    }

    /** Input: kết thúc test. Output: dọn schema cô lập. */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Input: dữ liệu cũ đã có actor/tuning/custom group. Output: bảo toàn value/type/audit và rollback. */
    public function test_migration_preserves_existing_values_and_rollback_copies_later_changes(): void
    {
        $migration = require database_path('migrations/2026_10_03_100000_migrate_settings_to_spatie.php');
        $migration->down();
        $actor = User::factory()->create();
        DB::table('settings')->where('group', 'ai')->where('key', 'request_timeout')->update([
            'value' => json_encode('120'), 'type' => 'integer', 'updated_by' => $actor->id,
        ]);
        DB::table('settings')->insert([
            'group' => 'custom', 'key' => 'labels', 'type' => 'json', 'value' => json_encode(['vi' => 'Nhãn']),
            'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration->up();
        $seed = require database_path('settings/2026_10_03_100001_initialize_ai_settings.php');
        $seed->up();
        $this->assertSame(120, app(AiSettings::class)->refresh()->request_timeout);
        $this->assertDatabaseHas('legacy_settings', ['key' => 'request_timeout', 'updated_by' => $actor->id]);
        $this->assertSame(['vi' => 'Nhãn'], json_decode(DB::table('settings')->where('group', 'custom')->value('payload'), true));
        app(AiSettingsService::class)->update(['request_timeout' => 240], $actor->id);
        $migration->down();
        $this->assertSame(240, json_decode(DB::table('settings')->where('group', 'ai')->where('key', 'request_timeout')->value('value')));
        $migration->up();
        $seed->up();
        $this->assertSame(240, app(AiSettings::class)->refresh()->request_timeout);
    }

    /** Input: một service đã đọc settings, rồi repository đổi tuning. Output: đọc mới và partial update giữ property khác. */
    public function test_same_service_refreshes_settings_and_keeps_partial_update_audit(): void
    {
        $service = app(AiSettingsService::class);
        $this->assertSame(30, $service->all()['request_timeout']);
        DB::table('settings')->where('group', 'ai')->where('name', 'request_timeout')->update(['payload' => '120']);
        $this->assertSame(120, $service->all()['request_timeout']);
        $actor = User::factory()->create();
        $values = $service->update(['default_temperature' => 0.6], $actor->id);
        $this->assertSame(120, $values['request_timeout']);
        $this->assertSame(0.6, $values['default_temperature']);
        $this->assertDatabaseHas('activity_log', ['description' => 'settings.updated', 'causer_id' => $actor->id]);
    }
}
