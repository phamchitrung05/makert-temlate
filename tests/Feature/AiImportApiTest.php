<?php

namespace Tests\Feature;

use App\Jobs\ProcessAiImportJob;
use App\Models\AiImport;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khóa contract HTTP và quyền của AI import queue API.
 * =====================================================================
 *
 * CÁC HÀM/METHOD TRONG FILE: setUp(), tearDown(), token() và test_* tạo/poll/cancel ownership.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : request admin URL/options.
 * - OUTPUT: assertion 202 queued, lifecycle payload và ownership boundary.
 * - SIDE EFFECT: database SQLite cô lập và fake queue; không gọi provider thật.
 * =====================================================================
 */
class AiImportApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * INPUT: PHPUnit lifecycle. OUTPUT: isolated schema and seeded permissions.
     * SIDE EFFECT: reset database and permission cache. EXCEPTION/TRANSACTION: test setup only.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * INPUT: PHPUnit lifecycle. OUTPUT: resources released.
     * SIDE EFFECT: teardown isolated database. EXCEPTION/TRANSACTION: test cleanup only.
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * INPUT: không có. OUTPUT: personal Sanctum token có posts.manage.
     * SIDE EFFECT: tạo user test và flush permission cache. EXCEPTION/TRANSACTION: test DB cô lập.
     */
    private function token(): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('posts.manage');
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('ai-import-test', ['admin'])->plainTextToken;
    }

    /**
     * INPUT: URL/options import. OUTPUT: assertion 202 và job queued.
     * SIDE EFFECT: fake queue, ghi AiImport test. EXCEPTION/TRANSACTION: không gọi provider thật.
     */
    public function test_store_queues_import_and_persists_options(): void
    {
        Queue::fake();
        $response = $this->withToken($this->token())->postJson('/api/admin/posts/ai/import', [
            'url' => 'https://example.test/article', 'language' => 'vi', 'generate_thumbnail' => true,
        ]);

        $response->assertStatus(202)->assertJsonPath('data.status', 'queued')->assertJsonPath('data.progress', 0);
        $this->assertDatabaseHas('ai_imports', ['status' => 'queued', 'source_url' => 'https://example.test/article']);
        Queue::assertPushed(ProcessAiImportJob::class);
    }

    /**
     * INPUT: UUID import của user khác. OUTPUT: assertion ownership 404.
     * SIDE EFFECT: fake queue và database test. EXCEPTION/TRANSACTION: không gọi provider thật.
     */
    public function test_import_status_is_private_to_creator(): void
    {
        Queue::fake();
        $this->withToken($this->token())->postJson('/api/admin/posts/ai/import', ['url' => 'https://example.test/article'])->assertStatus(202);
        $import = AiImport::query()->firstOrFail();
        $other = User::factory()->create(['status' => 'active']);
        $other->givePermissionTo('posts.manage');
        $this->flushPermissionCache();
        $otherToken = $other->createToken('other', ['admin'])->plainTextToken;
        Auth::forgetGuards();

        $this->withToken($otherToken)->getJson('/api/admin/posts/ai/import/'.$import->id)->assertNotFound();
    }

    /**
     * Input: target Post và quyền AI của admin. Output: capability từ registry,
     * không lộ endpoint/API key và chỉ trả provider/model đã allowlist.
     * Side effect: chỉ đọc config; không tạo job hoặc gọi provider.
     */
    public function test_capabilities_are_resolved_from_registries(): void
    {
        $response = $this->withToken($this->token())
            ->getJson('/api/admin/ai-agent/capabilities/post');

        $response->assertOk()
            ->assertJsonPath('data.target_type', 'post')
            ->assertJsonPath('data.prompts.0.key', 'post.create.from_url')
            ->assertJsonPath('data.schemas.0.key', 'post.content.v1');
        $configuredKey = (string) config('ai-import.key');
        if ($configuredKey !== '') {
            $this->assertStringNotContainsString($configuredKey, $response->getContent());
        }
    }
}
