<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm upload branding, quyền/version, rollback và runtime public/admin.
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp()/tearDown()/token()/upload()/storedPath(): fixture DB/disk cô lập.
 * - test_upload_requires_settings_manage(): kiểm quyền ghi file.
 * - test_upload_persists_and_renders_on_public_and_admin(): lưu/reload/runtime.
 * - test_replace_remove_and_conflict_keep_files_consistent(): version và cleanup.
 * - test_invalid_uploads_cannot_create_public_files(): loại file/size/dimensions.
 * - test_database_failure_rolls_back_and_removes_only_new_files(): rollback thật.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTTP multipart và DB/storage giả cô lập.
 * - OUTPUT: assertions; không thay branding hoặc file development.
 * =====================================================================
 */
final class SiteBrandingTest extends TestCase
{
    use UsesIsolatedDatabase;

    /** Input: không có. Output: schema/quyền/storage riêng cho mỗi test. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');
    }

    /** Input: không có. Output: giải phóng DB test, không chạm database development. */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Input: permissions. Output: Sanctum admin token cho user fixture. */
    private function token(array $permissions = ['settings.view', 'settings.manage']): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo($permissions);
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('branding-test', ['admin'])->plainTextToken;
    }

    /** Input: fields/files. Output: response multipart POST với method spoof giống browser. */
    private function upload(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/admin/settings/site', ['_method' => 'PATCH', ...$payload], ['Accept' => 'application/json']);
    }

    /** Input: property name. Output: path được lưu trong Spatie settings. */
    private function storedPath(string $name): ?string
    {
        return json_decode(DB::table('settings')->where('group', 'site')->where('name', $name)->value('payload'), true);
    }

    /** Input: guest/view-only. Output: 401/403, không có file public mới. */
    public function test_upload_requires_settings_manage(): void
    {
        $this->upload(['version' => 0, 'logo_file' => UploadedFile::fake()->image('logo.png')])->assertUnauthorized();
        $this->withToken($this->token(['settings.view']));
        $this->upload(['version' => 0, 'logo_file' => UploadedFile::fake()->image('logo.png')])->assertForbidden();
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /** Input: hai ảnh hợp lệ và thông tin site. Output: cùng version, reload và Blade dùng đúng URL. */
    public function test_upload_persists_and_renders_on_public_and_admin(): void
    {
        $this->withToken($this->token());
        $response = $this->upload([
            'version' => 0, 'site_name' => 'Brand mới',
            'logo_file' => UploadedFile::fake()->image('logo.png', 320, 80),
            'favicon_file' => UploadedFile::fake()->image('icon.png', 64, 64),
            'logo_path' => 'injected.php', 'favicon_url' => 'https://evil.example/icon',
        ])->assertOk()->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.values.logo_configured', true)->assertJsonPath('data.values.favicon_configured', true)
            ->assertJsonMissingPath('data.values.logo_path')->assertJsonMissingPath('data.values.favicon_path');
        Storage::disk('public')->assertExists([$this->storedPath('logo_path'), $this->storedPath('favicon_path')]);
        $logo = $response->json('data.values.logo_url');
        $favicon = $response->json('data.values.favicon_url');
        $this->withToken($this->token(['settings.view']))->getJson('/api/admin/settings')
            ->assertOk()->assertJsonPath('data.sections.site.values.logo_url', $logo);
        $this->get('/')->assertOk()->assertSee($logo, false)->assertSee($favicon, false)->assertSee('Brand mới');
        $this->get('/admin/login')->assertOk()->assertSee('id="site-branding"', false)
            ->assertSee($logo, false)->assertSee($favicon, false);
    }

    /** Input: thay/gỡ ảnh rồi gửi version cũ. Output: ảnh cũ dọn sau commit, conflict không ghi file. */
    public function test_replace_remove_and_conflict_keep_files_consistent(): void
    {
        $this->withToken($this->token());
        $this->upload([
            'version' => 0, 'site_name' => 'Giữ tên',
            'logo_file' => UploadedFile::fake()->image('old.png'),
            'favicon_file' => UploadedFile::fake()->image('old-icon.png', 32, 32),
        ])->assertOk();
        $oldLogo = $this->storedPath('logo_path');
        $oldIcon = $this->storedPath('favicon_path');
        $this->upload(['version' => 1, 'logo_file' => UploadedFile::fake()->image('new.webp'), 'remove_favicon' => '1'])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.values.site_name', 'Giữ tên')
            ->assertJsonPath('data.values.favicon_configured', false)->assertJsonPath('data.values.favicon_url', asset('favicon.ico'));
        Storage::disk('public')->assertMissing([$oldLogo, $oldIcon]);
        $newLogo = $this->storedPath('logo_path');
        $this->upload(['version' => 1, 'logo_file' => UploadedFile::fake()->image('stale.png')])->assertConflict();
        $this->assertSame([$newLogo], Storage::disk('public')->allFiles());
        $this->patchJson('/api/admin/settings/site', ['version' => 2, 'remove_logo' => true])
            ->assertOk()->assertJsonPath('data.values.logo_url', null);
        Storage::disk('public')->assertMissing($newLogo);
    }

    /** Input: loại file giả/SVG, dung lượng và ratio sai. Output: 422 trước khi ghi file public. */
    public function test_invalid_uploads_cannot_create_public_files(): void
    {
        $this->withToken($this->token());
        $this->upload(['version' => 0, 'logo_file' => UploadedFile::fake()->createWithContent('fake.png', '<?php echo 1;')])->assertUnprocessable();
        $this->upload(['version' => 0, 'logo_file' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertUnprocessable();
        $this->upload(['version' => 0, 'logo_file' => UploadedFile::fake()->image('large.png')->size(2049)])->assertUnprocessable();
        $this->upload(['version' => 0, 'favicon_file' => UploadedFile::fake()->image('wide.png', 64, 32)])->assertUnprocessable();
        $this->upload(['version' => 0, 'favicon_file' => UploadedFile::fake()->image('big.png', 513, 513)])->assertUnprocessable();
        $this->upload(['version' => 0, 'favicon_file' => UploadedFile::fake()->image('icon.jpg', 32, 32)])->assertUnprocessable();
        $this->upload(['version' => 0, 'logo_file' => UploadedFile::fake()->image('logo.png'), 'remove_logo' => '1'])->assertUnprocessable();
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertNull($this->storedPath('logo_path'));
    }

    /** Input: lỗi audit sau khi ghi file/Settings. Output: DB rollback, giữ ảnh cũ và dọn ảnh mới. */
    public function test_database_failure_rolls_back_and_removes_only_new_files(): void
    {
        $this->withToken($this->token());
        $this->upload(['version' => 0, 'logo_file' => UploadedFile::fake()->image('old.png')])->assertOk();
        $oldLogo = $this->storedPath('logo_path');
        Event::listen('eloquent.creating: '.Activity::class, function (Activity $activity): void {
            if ($activity->log_name === 'settings') {
                throw new \RuntimeException('Audit write failed in isolated test');
            }
        });
        $this->upload(['version' => 1, 'logo_file' => UploadedFile::fake()->image('new.png')])->assertStatus(500);
        $this->assertSame($oldLogo, $this->storedPath('logo_path'));
        $this->assertSame([$oldLogo], Storage::disk('public')->allFiles());
        $this->assertSame('1', DB::table('settings')->where('group', 'site')->where('name', 'version')->value('payload'));
    }
}
