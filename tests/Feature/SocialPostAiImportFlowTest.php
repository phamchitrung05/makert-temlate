<?php

namespace Tests\Feature;

use App\Jobs\ProcessAiImportJob;
use App\Models\AiImport;
use App\Models\User;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Content\ArticleImportService;
use App\Services\Ai\Data\AiTaskRequest;
use App\Services\Ai\Data\AiTaskResponse;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử end-to-end luồng tạo bài viết AI Social Post
 * =====================================================================
 *
 * Kiểm tra:
 * 1. Khởi tạo session social_post với requested_outputs: social_content, social_gallery
 * 2. Lưu giữ đúng pipeline của social_post (không bị ghi đè bởi three_step của blog post)
 * 3. Worker chạy thành công với mock AI provider, chuẩn hóa hashtags và slot_count
 * 4. Draft kết quả chứa đầy đủ content_html, cta, hashtags, gallery_prompt
 * =====================================================================
 */
final class SocialPostAiImportFlowTest extends TestCase
{
    use UsesIsolatedDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('queue.default', 'database');
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();
        Http::preventStrayRequests();

        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo(['posts.manage', 'social_posts.create', 'social_posts.update']);
        $this->flushPermissionCache();
        Auth::forgetGuards();

        $this->token = $user->createToken('social-test', ['admin'])->plainTextToken;
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo mới session social_post và kiểm tra pipeline_snapshot
     * =====================================================================
     */
    public function test_social_post_session_creation_preserves_pipeline_snapshot(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions', [
            'target_type' => 'social_post',
            'platform' => 'facebook',
            'input' => ['type' => 'text', 'text' => 'Giới thiệu sản phẩm mới với tính năng nổi bật.'],
            'requested_outputs' => ['social_content', 'social_gallery'],
            'provider' => 'deterministic',
            'model' => 'deterministic',
            'generate_thumbnail' => false,
            'generate_seo' => false,
        ]);

        $response->assertAccepted();
        $importId = (string) $response->json('data.job_id');
        $import = AiImport::findOrFail($importId);

        $this->assertSame('social_post', $import->input_json['target_type']);
        $this->assertSame('facebook', $import->input_json['platform']);
        $this->assertSame(['social_content', 'social_gallery'], $import->input_json['pipeline_snapshot']['outputs']);

        // Pipeline không được bị đè thành 'three_step'
        $pipeline = $import->input_json['pipeline_snapshot']['pipeline'];
        $this->assertIsArray($pipeline);
        $this->assertArrayHasKey('content', $pipeline);
        $this->assertSame('social_content', $pipeline['content']['output']);

        Queue::assertPushed(ProcessAiImportJob::class, 1);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Worker thực thi import social_post thành công và chuẩn hóa dữ liệu
     * =====================================================================
     */
    public function test_social_post_import_execution_succeeds_with_normalized_fields(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/admin/ai-agent/sessions', [
            'target_type' => 'social_post',
            'platform' => 'facebook',
            'input' => ['type' => 'text', 'text' => 'Chương trình khuyến mãi mùa hè 2026.'],
            'requested_outputs' => ['social_content', 'social_gallery'],
            'provider' => 'deterministic',
            'model' => 'deterministic',
            'generate_thumbnail' => false,
            'generate_seo' => false,
        ]);

        $response->assertAccepted();
        $importId = (string) $response->json('data.job_id');
        $import = AiImport::findOrFail($importId);

        // Tạo mock provider trả response có hashtags dạng string và slot_count dạng chuỗi số
        $mockProvider = new class implements AiProviderContract {
            public function configured(): bool { return true; }
            public function providerName(): string { return 'mock'; }
            public function modelName(): string { return 'mock-social-model'; }
            public function execute(AiTaskRequest $request): AiTaskResponse {
                return new AiTaskResponse([]);
            }
            public function generate(string $title, string $content, string $language = 'vi', string $rewriteStyle = 'informative', string $promptKey = 'post.create.from_url', string $instructions = ''): array
            {
                return [
                    'content_html' => '<p>Khuyến mãi cực sốc mùa hè 2026 - Giảm tới 50%!</p>',
                    'cta' => 'Mua sắm ngay tại website.',
                    'hashtags' => '#khuyenmai #muahe2026 #sale #giamgia', // String needs normalization to array
                    'link_url' => 'https://example.com/sale',
                    'gallery_prompt' => 'Summer promotion banner with beach vibes and vibrant colors',
                    'negative_prompt' => 'blurry, dark, low resolution',
                    'aspect_ratio' => '1:1',
                    'slot_count' => '4', // String needs normalization to integer
                ];
            }
        };

        // Chạy ProcessAiImportJob với mock provider
        $service = new ArticleImportService($mockProvider);
        (new ProcessAiImportJob($import->id))->handle($service);

        $freshImport = $import->fresh();
        $this->assertSame('ready', $freshImport->status);
        $this->assertNull($freshImport->error_code);

        $draft = $freshImport->result_json['draft'];
        $this->assertStringContainsString('Khuyến mãi cực sốc mùa hè 2026', $draft['content_html']);
        $this->assertSame('Mua sắm ngay tại website.', $draft['cta']);
        $this->assertSame(['#khuyenmai', '#muahe2026', '#sale', '#giamgia'], $draft['hashtags']);
        $this->assertSame('Summer promotion banner with beach vibes and vibrant colors', $draft['gallery_prompt']);
        $this->assertSame(4, $draft['slot_count']);
    }
}
