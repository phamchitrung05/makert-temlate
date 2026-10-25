<?php

namespace Tests\Feature;

use App\Models\AiImport;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm Social Post không còn phụ thuộc Product.
 * =====================================================================
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp(): tạo database cô lập và quyền Social.
 * - tearDown(): giải phóng database cô lập.
 * - token(): tạo token admin test.
 * - test_social_post_schema_does_not_have_product_column(): kiểm migration.
 * - test_social_post_api_rejects_product_payload(): kiểm boundary request.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : migration Social Post và request có product_id.
 * - OUTPUT: schema/API chứng minh Social Post độc lập Product.
 * - SIDE EFFECT: chỉ ghi database test cô lập.
 * =====================================================================
 */
final class SocialPostProductDecouplingTest extends TestCase
{
    use UsesIsolatedDatabase;

    /** Input: PHPUnit app. Output: schema và permission test sẵn sàng. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
    }

    /** Input: isolated connection. Output: database test được giải phóng. */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Input: schema sau migrate. Output: Social Post không có product_id. */
    public function test_social_post_schema_does_not_have_product_column(): void
    {
        $this->assertFalse(Schema::hasColumn('social_posts', 'product_id'));
    }

    /** Input: token Social và payload product cũ. Output: validation 422, không ghi post. */
    public function test_social_post_api_rejects_product_payload(): void
    {
        $token = $this->token();

        $this->withToken($token)
            ->postJson('/api/admin/social-posts', ['product_id' => 1, 'caption' => 'Không hợp lệ'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_id');

        $this->assertDatabaseCount('social_posts', 0);
    }

    /** Input: Social AI candidate ready. Output: apply tạo draft Facebook không qua Post adapter. */
    public function test_social_ai_candidate_applies_to_facebook_draft(): void
    {
        $token = $this->token();
        $draft = ['content_html' => '<p>Nội dung Facebook độc lập Product.</p>'];
        $run = AiImport::query()->create([
            'created_by' => User::query()->where('id', '>', 0)->latest('id')->value('id'),
            'source_url' => '', 'source_hash' => hash('sha256', 'social-candidate'),
            'status' => 'ready', 'current_step' => 'ready', 'progress' => 100,
            'input_json' => ['target_type' => 'social_post', 'platform' => 'facebook'],
            'result_json' => ['draft' => $draft], 'source_meta_json' => [],
            'provider' => 'deterministic', 'prompt_version' => '1.0',
            'operation' => 'create', 'generation_no' => 1,
            'expires_at' => now()->addHour(),
        ]);
        $expected = hash('sha256', json_encode($draft));
        $reviewVersion = \App\Services\Ai\Content\AiContentReviewService::version($run);

        $response = $this->withToken($token)->postJson('/api/admin/ai-agent/candidates/'.$run->id.'/approve', [
            'fields' => ['content'], 'expected_version' => $expected, 'expected_review_version' => $reviewVersion,
        ]);

        $response->assertOk()->assertJsonPath('data.fields.0', 'content');
        $this->assertDatabaseHas('social_posts', ['caption' => 'Nội dung Facebook độc lập Product.']);
        $this->assertSame('approved', data_get($run->fresh()->source_meta_json, 'editorial.status'));
        // Social candidate cũng lưu vào AI Approved giống Post (theo luồng đã khôi phục).
        $this->assertDatabaseHas('ai_article_archives', ['run_id' => $run->id, 'generation_no' => $run->generation_no]);
    }

    /** Input: admin permission. Output: Sanctum token test. */
    private function token(): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('social_posts.create');
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('social-decoupling-test', ['admin'])->plainTextToken;
    }
}
