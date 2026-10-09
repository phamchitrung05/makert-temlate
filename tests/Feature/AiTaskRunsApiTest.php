<?php

namespace Tests\Feature;

use App\Models\AiTaskRun;
use App\Models\AiImport;
use App\Models\AiWritingProfileAnalysis;
use App\Models\User;
use App\Services\Ai\Runs\AiTaskRunService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm API tracker task AI dùng chung và adapter lifecycle.
 * =====================================================================
 * Kiểm idempotency, scope owner/quyền, projection an toàn và hủy đúng nguồn.
 * Test không gọi provider; worker thật chỉ được mô phỏng bằng service registry.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp(): migrate database cô lập, seed permission và fake queue/HTTP.
 * - tearDown(): giải phóng database cô lập sau mỗi test.
 * - token(): tạo actor có ai_settings.manage và token ability admin.
 * - analysis(): tạo source queued tối thiểu cho adapter analysis.
 * - importRun(): tạo source article/image queued với payload nhạy cảm giả lập.
 * - test_register_is_idempotent_and_scoped(): kiểm UUID dedupe, list và scope owner.
 * - test_cancel_updates_source_and_tracker(): kiểm cancel đồng bộ source/tracker.
 * - test_import_article_and_image_are_projected_safely(): kiểm projection chung và allowlist metadata.
 * - test_legacy_completed_import_is_projected_as_ready(): kiểm tương thích lifecycle cũ.
 * - test_expired_import_is_reconciled_without_polling_state(): kiểm hết hạn được dừng đúng.
 * - test_cancel_import_is_generation_safe(): kiểm hủy một article không ảnh hưởng run khác.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : fixture analysis, token admin và endpoint tracker.
 * - OUTPUT: assertions cho tracker ổn định, quyền và lifecycle.
 * - SIDE EFFECT: schema/DB/queue cô lập theo test.
 * - EXCEPTION/TRANSACTION: lỗi assertion/dependency truyền ra PHPUnit.
 * =====================================================================
 */
final class AiTaskRunsApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị database/quyền/queue cho test tracker.
     * =====================================================================
     * INPUT: PHPUnit setup. OUTPUT: môi trường test cô lập.
     * SIDE EFFECT: migrate SQLite, seed quyền, chặn HTTP ngoài.
     * EXCEPTION/TRANSACTION: lỗi setup truyền ra PHPUnit.
     * =====================================================================
     */
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('queue.default', 'database');
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();
        Http::preventStrayRequests();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn database cô lập sau test.
     * =====================================================================
     * INPUT: PHPUnit teardown. OUTPUT: schema test được giải phóng.
     * SIDE EFFECT: rollback/xóa connection test, không tác động production.
     * EXCEPTION/TRANSACTION: lỗi teardown truyền ra PHPUnit.
     * =====================================================================
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo actor có quyền quản lý writing profile.
     * =====================================================================
     * INPUT: không có. OUTPUT: bearer token ability admin.
     * SIDE EFFECT: ghi user/token trong DB test.
     * EXCEPTION/TRANSACTION: lỗi fixture truyền ra PHPUnit.
     * =====================================================================
     */
    private function token(): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('ai_settings.manage');
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('ai-task-runs', ['admin'])->plainTextToken;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo source analysis tối thiểu cho adapter.
     * =====================================================================
     * INPUT: actor ID. OUTPUT: analysis queued chưa dispatch provider.
     * SIDE EFFECT: chỉ ghi source fixture.
     * EXCEPTION/TRANSACTION: lỗi fixture truyền ra PHPUnit.
     * =====================================================================
     */
    private function analysis(int $actorId): AiWritingProfileAnalysis
    {
        return AiWritingProfileAnalysis::query()->create([
            'created_by' => $actorId,
            'name' => 'Phong cách kỹ thuật',
            'reference_text' => 'Nội dung mẫu đủ dài để tạo tracker.',
            'source_type' => 'paste',
            'source_hash' => hash('sha256', 'Nội dung mẫu đủ dài để tạo tracker.'),
            'status' => 'queued',
            'connection_snapshot_json' => ['provider' => 'deterministic', 'model' => 'test-model'],
            'prompt_version' => 'test',
            'schema_version' => 'test',
            'expires_at' => now()->addDay(),
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo source import bài/ảnh với dữ liệu nhạy cảm giả lập.
     * =====================================================================
     * INPUT: actor, operation, status và expires_at tùy chọn.
     * OUTPUT: AiImport queued/terminal đủ field cho adapter dùng chung.
     * SIDE EFFECT: ghi fixture vào DB test; không dispatch provider.
     * EXCEPTION/TRANSACTION: lỗi schema/fixture truyền ra PHPUnit.
     * =====================================================================
     */
    private function importRun(int $actorId, string $operation = 'create', string $status = 'queued', ?\DateTimeInterface $expiresAt = null): AiImport
    {
        return AiImport::query()->create([
            'created_by' => $actorId,
            'source_url' => 'https://example.test/private?token=source-secret',
            'source_text' => 'Nội dung nguồn riêng tư không được trả trong summary.',
            'source_hash' => hash('sha256', uniqid('', true)),
            'status' => $status,
            'current_step' => $status,
            'progress' => $status === 'ready' ? 100 : 0,
            'operation' => $operation,
            'provider' => 'fixture-provider',
            'expires_at' => $expiresAt ?? now()->addDay(),
            'input_json' => [
                'target_type' => 'post',
                'prompt' => 'Prompt riêng tư không được trả trong summary.',
                'ai_connection' => [
                    'provider' => 'fixture-provider',
                    'model' => 'fixture-model',
                    'api_key' => 'private-api-key',
                ],
            ],
            'result_json' => ['draft' => ['title' => 'Kết quả nội bộ']],
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm registration idempotent và list chỉ trả owner/quyền.
     * =====================================================================
     * INPUT: hai lần register cùng source và GET tracker.
     * OUTPUT: cùng UUID tracker, một dòng DB, metadata không có payload.
     * SIDE EFFECT: ghi tracker projection và đọc API.
     * EXCEPTION/TRANSACTION: transaction service ngắn; không gọi provider.
     * =====================================================================
     */
    public function test_register_is_idempotent_and_scoped(): void
    {
        $token = $this->token();
        $actor = User::query()->where('email', '!=', '')->latest('id')->firstOrFail();
        $source = $this->analysis((int) $actor->id);
        $service = app(AiTaskRunService::class);

        $first = $service->registerAnalysis($source);
        $second = $service->registerAnalysis($source->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('ai_task_runs', 1);

        $response = $this->withToken($token)->getJson('/api/admin/ai/tasks')->assertOk();
        $response->assertJsonPath('data.0.id', $first->id);
        $response->assertJsonPath('data.0.task_type', 'writing_profile_analysis');
        $this->assertStringNotContainsString('Nội dung mẫu đủ dài', $response->getContent());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm hủy cập nhật nguồn và tracker qua adapter.
     * =====================================================================
     * INPUT: tracker queued thuộc actor hiện tại.
     * OUTPUT: API trả cancelled và source cũng cancelled.
     * SIDE EFFECT: ghi hai projection trong transaction.
     * EXCEPTION/TRANSACTION: completion/permission guard do service xử lý.
     * =====================================================================
     */
    public function test_cancel_updates_source_and_tracker(): void
    {
        $token = $this->token();
        $actor = User::query()->latest('id')->firstOrFail();
        $source = $this->analysis((int) $actor->id);
        $task = app(AiTaskRunService::class)->register($source);

        $this->withToken($token)->postJson('/api/admin/ai/tasks/'.$task->id.'/cancel')
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('cancelled', $source->fresh()->status);
        $this->assertSame('cancelled', $task->fresh()->status);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm article và image cùng xuất hiện trong tracker chung.
     * =====================================================================
     * INPUT: actor có quyền posts.manage/media.upload và hai import khác operation.
     * OUTPUT: task_type đúng, metadata bounded, không lộ URL/source/prompt/key.
     * SIDE EFFECT: ghi hai projection và đọc API list có filter task_type.
     * EXCEPTION/TRANSACTION: service transaction ngắn; provider không được gọi.
     * =====================================================================
     */
    public function test_import_article_and_image_are_projected_safely(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo(['posts.manage', 'media.upload']);
        $this->flushPermissionCache();
        Auth::forgetGuards();
        $token = $user->createToken('ai-import-tasks', ['admin'])->plainTextToken;

        $article = $this->importRun((int) $user->id);
        $image = $this->importRun((int) $user->id, 'image');
        $service = app(AiTaskRunService::class);
        $articleTask = $service->registerImport($article);
        $imageTask = $service->registerImport($image);

        $response = $this->withToken($token)
            ->getJson('/api/admin/ai/tasks?task_type=article_generation,image_generation')
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertSame(['article_generation', 'image_generation'], collect($response->json('data'))->pluck('task_type')->sort()->values()->all());
        $this->assertSame('article_generation', $articleTask->fresh()->task_type);
        $this->assertSame('image_generation', $imageTask->fresh()->task_type);
        $this->assertStringNotContainsString('source-secret', $response->getContent());
        $this->assertStringNotContainsString('Prompt riêng tư', $response->getContent());
        $this->assertStringNotContainsString('private-api-key', $response->getContent());
        $this->assertStringNotContainsString('Kết quả nội bộ', $response->getContent());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa trạng thái legacy completed/succeeded của import.
     * =====================================================================
     * INPUT: import cũ đã hoàn tất nhưng chưa dùng status ready.
     * OUTPUT: tracker ready, không bị popup coi là task đang chạy.
     * SIDE EFFECT: chỉ ghi projection; source nghiệp vụ vẫn giữ nguyên để tương thích.
     * EXCEPTION/TRANSACTION: service transaction ngắn; không gọi provider.
     * =====================================================================
     */
    public function test_legacy_completed_import_is_projected_as_ready(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('posts.manage');
        $this->flushPermissionCache();
        Auth::forgetGuards();
        $token = $user->createToken('ai-legacy-task', ['admin'])->plainTextToken;
        $legacy = $this->importRun((int) $user->id, status: 'completed');
        $task = app(AiTaskRunService::class)->registerImport($legacy);

        $this->withToken($token)->getJson('/api/admin/ai/tasks')->assertOk()
            ->assertJsonPath('data.0.id', $task->id)->assertJsonPath('data.0.status', 'ready');
        $this->assertSame('ready', $task->fresh()->status);

        $this->withToken($token)->postJson('/api/admin/ai/tasks/'.$task->id.'/cancel')
            ->assertOk()->assertJsonPath('data.status', 'ready');
        $this->assertSame('completed', $legacy->fresh()->status);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm task import đã hết hạn được reconcile về terminal.
     * =====================================================================
     * INPUT: import queued có expires_at trong quá khứ.
     * OUTPUT: API trả expired và tracker không còn active để popup poll.
     * SIDE EFFECT: reconcileActive cập nhật projection tracker; source không bị gọi provider.
     * EXCEPTION/TRANSACTION: transaction đọc/ghi ngắn trong service.
     * =====================================================================
     */
    public function test_expired_import_is_reconciled_without_polling_state(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('posts.manage');
        $this->flushPermissionCache();
        Auth::forgetGuards();
        $token = $user->createToken('ai-expired-task', ['admin'])->plainTextToken;
        $task = app(AiTaskRunService::class)->registerImport($this->importRun((int) $user->id, expiresAt: now()->subMinute()));

        $response = $this->withToken($token)->getJson('/api/admin/ai/tasks')->assertOk();

        $response->assertJsonPath('data.0.id', $task->id)->assertJsonPath('data.0.status', 'expired');
        $this->assertSame('expired', $task->fresh()->status);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm hủy một import không làm thay đổi import khác.
     * =====================================================================
     * INPUT: hai task article cùng owner và chỉ hủy task thứ nhất.
     * OUTPUT: task/source thứ nhất cancelled, task/source thứ hai vẫn queued.
     * SIDE EFFECT: adapter lock/cancel đúng source và persist tracker tương ứng.
     * EXCEPTION/TRANSACTION: hủy stale/owner khác phải bị chặn; test dùng owner đúng.
     * =====================================================================
     */
    public function test_cancel_import_is_generation_safe(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('posts.manage');
        $this->flushPermissionCache();
        Auth::forgetGuards();
        $token = $user->createToken('ai-cancel-task', ['admin'])->plainTextToken;
        $first = $this->importRun((int) $user->id);
        $second = $this->importRun((int) $user->id);
        $service = app(AiTaskRunService::class);
        $firstTask = $service->registerImport($first);
        $secondTask = $service->registerImport($second);

        $this->withToken($token)->postJson('/api/admin/ai/tasks/'.$firstTask->id.'/cancel')
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('queued', $second->fresh()->status);
        $this->assertSame('cancelled', $firstTask->fresh()->status);
        $this->assertSame('queued', $secondTask->fresh()->status);
    }
}
