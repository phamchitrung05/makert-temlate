<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Settings\ProjectSettingsService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm cache Settings chỉ tồn tại trong request và được xóa khi lưu.
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp()/tearDown(): cô lập database.
 * - test_settings_cache_expires_between_requests_and_is_invalidated_after_update(): kiểm freshness.
 * - requestContext(): thay request context bằng request mới.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): DB/settings đổi -> assertion snapshot trong request và giá trị mới sau đó.
 * =====================================================================
 */
final class ProjectSettingsCacheTest extends TestCase
{
    use UsesIsolatedDatabase;

    /** Input: không có. Output: schema SQLite riêng cho test. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
    }

    /** Input: schema test. Output: giải phóng database. */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Input: DB thay đổi giữa các lần đọc và service update. Output: cache không vượt request hoặc giữ giá trị cũ sau save. */
    public function test_settings_cache_expires_between_requests_and_is_invalidated_after_update(): void
    {
        $this->requestContext();
        $settings = app(ProjectSettingsService::class);
        $first = $settings->effective('site')['site_name'];
        DB::table('settings')->where('group', 'site')->where('name', 'site_name')->update(['payload' => json_encode('Changed externally')]);
        $this->assertSame($first, $settings->effective('site')['site_name']);

        $this->requestContext();
        $this->assertSame('Changed externally', $settings->effective('site')['site_name']);
        $actor = User::factory()->create();
        $section = $settings->update('site', ['version' => 0, 'site_name' => 'Saved in request'], $actor);
        $this->assertSame('Saved in request', $section['values']['site_name']);
        $this->assertSame('Saved in request', $settings->effective('site')['site_name']);
    }

    /** Input: không có. Output: request mới với attribute bag riêng và route public. */
    private function requestContext(): void
    {
        $request = Request::create('/resources');
        $request->setRouteResolver(fn () => new Route('GET', '/resources', fn () => null));
        $this->app->instance('request', $request);
    }
}
