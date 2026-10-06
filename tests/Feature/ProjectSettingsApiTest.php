<?php

namespace Tests\Feature;

use App\Enums\MediaAssetKind;
use App\Exceptions\MediaSecurityException;
use App\Mail\SettingsTestMail;
use App\Models\Post;
use App\Models\User;
use App\Services\Media\MediaUploadValidator;
use App\Services\SeoMetadataService;
use App\Services\Settings\ProjectSettingsService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/** Kiểm auth, persistence, conflict, redaction và runtime bằng DB cô lập; không gửi email thật. */
final class ProjectSettingsApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    /** Tạo schema riêng và permission catalog. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
    }

    /** Giải phóng database test, không đụng dữ liệu development. */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Input: quyền. Output: token Sanctum admin cho fixture active. */
    private function token(array $permissions = ['settings.view', 'settings.manage']): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo($permissions);
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('settings-test', ['admin'])->plainTextToken;
    }

    /** Guest/bất kỳ admin không thể đọc credentials hoặc ghi cài đặt. */
    public function test_permissions_and_read_only_access(): void
    {
        $this->getJson('/api/admin/settings')->assertUnauthorized();
        $token = $this->token([]);
        $this->withToken($token)->getJson('/api/admin/settings')->assertForbidden();
        $this->withToken($token)->getJson('/api/admin/settings/preferences')->assertOk();
        $token = $this->token(['settings.view']);
        $this->withToken($token)->getJson('/api/admin/settings')->assertOk()->assertJsonPath('data.can_manage', false);
        $this->withToken($token)->patchJson('/api/admin/settings/site', ['version' => 0, 'site_name' => 'Denied'])->assertForbidden();
        $this->withToken($token)->postJson('/api/admin/settings/mail/test', ['recipient' => 'test@example.test'])->assertForbidden();
    }

    /** Save/reload, partial update, validation và stale version không overwrite. */
    public function test_group_updates_are_versioned_allowlisted_and_audited(): void
    {
        $token = $this->token();
        $this->withToken($token)->patchJson('/api/admin/settings/site', [
            'version' => 0, 'site_name' => 'Market mới', 'injected' => 'ignored',
        ])->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.values.site_name', 'Market mới');
        $this->withToken($token)->patchJson('/api/admin/settings/site', [
            'version' => 0, 'site_name' => 'Stale',
        ])->assertConflict();
        $this->withToken($token)->patchJson('/api/admin/settings/site', [
            'version' => 1, 'timezone' => 'not-a-timezone',
        ])->assertUnprocessable();
        $this->withToken($token)->getJson('/api/admin/settings')->assertOk()
            ->assertJsonPath('data.sections.site.values.site_name', 'Market mới')
            ->assertJsonPath('data.sections.site.version', 1);
        $this->assertDatabaseMissing('settings', ['name' => 'injected']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'settings', 'description' => 'settings.updated']);
    }

    /** Password encrypted, không xuất hiện trong response/audit, blank giữ secret. */
    public function test_mail_secret_is_write_only_and_mail_test_uses_saved_transport(): void
    {
        Mail::fake();
        $token = $this->token();
        $response = $this->withToken($token)->patchJson('/api/admin/settings/mail', [
            'version' => 0, 'password' => 'private-smtp-password', 'mailer' => 'array',
            'from_name' => 'Market sender', 'from_address' => 'sender@example.test',
        ])->assertOk()->assertJsonPath('data.values.password_configured', true);
        $this->assertStringNotContainsString('private-smtp-password', $response->getContent());
        $this->assertStringNotContainsString('private-smtp-password', DB::table('settings')->where('group', 'mail')->where('name', 'password')->value('payload'));
        $this->assertStringNotContainsString('private-smtp-password', DB::table('activity_log')->pluck('properties')->implode(''));
        $this->withToken($token)->patchJson('/api/admin/settings/mail', ['version' => 1, 'password' => ''])->assertOk();
        $this->assertSame('private-smtp-password', app(ProjectSettingsService::class)->effective('mail')['password']);
        $this->withToken($token)->postJson('/api/admin/settings/mail/test', ['recipient' => 'receiver@example.test'])
            ->assertOk()->assertJsonPath('data.mailer', 'array');
        Mail::assertSent(SettingsTestMail::class, fn ($mail) => $mail->hasTo('receiver@example.test'));
        $this->assertSame('Market sender', config('mail.from.name'));
        $this->withToken($token)->postJson('/api/admin/settings/mail/test', ['recipient' => 'invalid'])->assertUnprocessable();
    }

    /** Media policy refresh cùng instance, MIME allowlist giữ nguyên, giới hạn áp dụng bytes. */
    public function test_media_settings_apply_to_upload_and_conversion_policy(): void
    {
        $token = $this->token();
        $settings = app(ProjectSettingsService::class);
        $this->withToken($token)->patchJson('/api/admin/settings/media', [
            'version' => 0, 'image_max_size_kb' => 1,
            'allowed_extensions' => ['jpg'], 'conversion_format' => 'jpg', 'conversion_quality' => 70,
        ])->assertOk();
        $this->assertSame(['jpg'], $settings->mediaPolicy('image')['extensions']);
        $this->assertSame('jpg', $settings->imageProfile('featured')['format']);
        $this->assertSame(70, $settings->imageProfile('og')['quality']);
        $this->withToken($token)->patchJson('/api/admin/settings/media', ['version' => 1, 'allowed_extensions' => ['php']])->assertUnprocessable();
        $this->expectException(MediaSecurityException::class);
        app(MediaUploadValidator::class)->validate(UploadedFile::fake()->image('large.jpg')->size(2), MediaAssetKind::Image);
    }

    /** Robots/public metadata/fallback đọc DB sau save; không đổi SEO riêng của Post. */
    public function test_site_and_seo_settings_are_consumed_by_public_and_content(): void
    {
        $token = $this->token();
        $this->withToken($token)->patchJson('/api/admin/settings/site', [
            'version' => 0, 'site_name' => 'Market public',
            'site_url' => 'https://market.example.test', 'contact_email' => 'contact@example.test',
        ])->assertOk();
        $this->withToken($token)->patchJson('/api/admin/settings/seo', [
            'version' => 0, 'title_format' => '%title% | %sitename%',
            'default_description' => 'Mô tả public', 'robots_txt' => "User-agent: *\nDisallow: /admin/",
        ])->assertOk();
        $this->get('/')->assertOk()->assertSee('Market public')->assertSee('Mô tả public')
            ->assertSee('https://market.example.test/')->assertSee('mailto:contact@example.test');
        $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertSee('Disallow: /admin/', false);
        $post = Post::factory()->create(['title' => 'Bài viết']);
        $seo = app(SeoMetadataService::class);
        $this->assertSame('Bài viết | Market public', $seo->resolve($post)['seo_title']);
        $post->seoMetadata()->create(['seo_title' => 'Riêng cho Post']);
        $post->unsetRelation('seoMetadata');
        $this->assertSame('Riêng cho Post', $seo->resolve($post)['seo_title']);
    }

    /** Locale chưa có bản dịch/default bị tắt bị reject; diagnostics không fixture. */
    public function test_languages_and_read_only_operations_match_actual_project(): void
    {
        $token = $this->token();
        $this->withToken($token)->patchJson('/api/admin/settings/languages', ['version' => 0, 'enabled_locales' => ['ja']])->assertUnprocessable();
        $this->withToken($token)->patchJson('/api/admin/settings/languages', ['version' => 0, 'enabled_locales' => ['fr'], 'default_locale' => 'en'])->assertUnprocessable();
        $this->withToken($token)->patchJson('/api/admin/settings/languages', ['version' => 0, 'enabled_locales' => ['fr'], 'default_locale' => 'fr'])->assertOk();
        $this->withToken($token)->getJson('/api/admin/settings/preferences')->assertJsonPath('data.languages.default_locale', 'fr');
        $cron = $this->withToken($token)->getJson('/api/admin/settings/cron')->assertOk()->assertJsonPath('data.history_available', false);
        $this->assertStringContainsString('ai-import:cleanup', $cron->getContent());
        // HTTP không boot Artisan; registry vẫn đầy đủ và không đăng ký trùng khi làm mới.
        $this->app->instance(Schedule::class, new Schedule);
        $count = count($this->withToken($token)->getJson('/api/admin/settings/cron')->assertOk()->json('data.items'));
        $this->assertGreaterThanOrEqual(2, $count);
        $this->assertCount($count, $this->withToken($token)->getJson('/api/admin/settings/cron')->assertOk()->json('data.items'));
        $this->withToken($token)->getJson('/api/admin/settings/webhooks')->assertOk()->assertJsonPath('data.supported', false)->assertJsonPath('data.items', []);
        $this->withToken($token)->getJson('/api/admin/settings/system-info')->assertOk()
            ->assertJsonPath('data.runtime.php', PHP_VERSION)->assertJsonPath('data.database.status', 'connected')->assertJsonPath('data.queue.worker_health', 'unknown');
    }

    /** Token mới dùng thời hạn DB; throttle login lấy giới hạn DB và không tắt được. */
    public function test_security_settings_control_login_and_new_token_expiry(): void
    {
        $token = $this->token();
        $this->withToken($token)->patchJson('/api/admin/settings/security', [
            'version' => 0, 'token_expiration_days' => 2, 'login_max_attempts' => 2, 'login_decay_minutes' => 5,
        ])->assertOk();
        User::factory()->create(['email' => 'login@example.test', 'password' => bcrypt('Example123!'), 'status' => 'active']);
        Auth::forgetGuards();
        $response = $this->postJson('/api/admin/login', ['email' => 'login@example.test', 'password' => 'Example123!', 'deviceName' => 'settings-test'])->assertOk();
        $expiry = DB::table('personal_access_tokens')->orderByDesc('id')->value('expires_at');
        $this->assertEqualsWithDelta(now()->addDays(2)->timestamp, Carbon::parse($expiry)->timestamp, 3);
        $this->postJson('/api/admin/login', ['email' => 'bad@example.test', 'password' => 'Bad123!'])->assertUnprocessable();
        $this->postJson('/api/admin/login', ['email' => 'bad@example.test', 'password' => 'Bad123!'])->assertTooManyRequests();
    }

    /** AI writer hiện có kiểm version cùng catalog; client cũ vẫn hỗ trợ partial update. */
    public function test_ai_settings_prevent_stale_updates_through_existing_writer(): void
    {
        $token = $this->token(['ai_settings.manage']);
        $version = $this->withToken($token)->getJson('/api/admin/settings/ai/settings')->assertOk()->json('data.settings_version');
        $this->withToken($token)->putJson('/api/admin/settings/ai/settings', ['settings_version' => $version, 'auto_seo' => false])->assertOk();
        $this->withToken($token)->putJson('/api/admin/settings/ai/settings', ['settings_version' => $version, 'auto_thumbnail' => false])->assertConflict();
        $this->withToken($token)->getJson('/api/admin/settings/ai/settings')->assertJsonPath('data.auto_thumbnail', true)->assertJsonPath('data.auto_seo', false);
    }
}
