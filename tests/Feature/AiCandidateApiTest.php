<?php

namespace Tests\Feature;

use App\Jobs\ProcessAiImportJob;
use App\Models\AiImport;
use App\Models\Post;
use App\Models\User;
use App\Services\Ai\ArticleImportService;
use App\Services\Ai\StructuredAiProvider;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khóa contract candidate lineage, regenerate và retry.
 * =====================================================================
 *
 * CÁC HÀM/METHOD TRONG FILE: setUp(), tearDown(), token() và các test lifecycle/apply.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : run ready/failed thuộc admin.
 * - OUTPUT: candidate mới hoặc retry cùng UUID và provenance apply.
 * - SIDE EFFECT: database SQLite cô lập, queue fake; không gọi provider thật.
 * =====================================================================
 */
class AiCandidateApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * INPUT: PHPUnit lifecycle. OUTPUT: isolated schema and seeded permissions.
     * SIDE EFFECT: reset database and queue-related state. EXCEPTION/TRANSACTION: test setup only.
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

    /** Input: user permission. Output: personal admin token. */
    private function token(): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('posts.manage');
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('ai-candidate-test', ['admin'])->plainTextToken;
    }

    /** Input: ready import. Output: immutable child candidate in same session. */
    public function test_regenerate_preserves_original_candidate(): void
    {
        Queue::fake();
        $token = $this->token();
        $this->withToken($token)->postJson('/api/admin/posts/ai/import', ['url' => 'https://example.test/article'])->assertStatus(202);
        $original = AiImport::query()->firstOrFail();
        $original->update(['status' => 'ready', 'result_json' => ['draft' => ['title' => 'A']]]);

        $response = $this->withToken($token)->postJson('/api/admin/posts/ai/import/'.$original->id.'/regenerate', ['instructions' => 'Giữ nguyên code.']);
        $response->assertStatus(202)->assertJsonPath('data.operation', 'regenerate');
        $child = AiImport::query()->whereKey($response->json('data.job_id'))->firstOrFail();
        $this->assertSame($original->id, $child->parent_id);
        $this->assertSame($original->id, $child->session_id);
        $this->assertSame(['draft' => ['title' => 'A']], $original->fresh()->result_json);
        $this->assertDatabaseCount('posts', 0);
        Queue::assertPushed(ProcessAiImportJob::class, 2);
    }

    /**
     * Input: regenerate chỉ field title. Output: title mới nhưng content parent giữ nguyên.
     * Side effect: child run đọc lại source; không mutate result_json của candidate gốc.
     */
    public function test_regenerate_selected_field_preserves_unselected_parent_fields(): void
    {
        Queue::fake();
        config()->set('ai-import.endpoint', null);
        config()->set('ai-import.key', null);
        Http::fake([
            'https://example.test/partial-regenerate' => Http::response(
                '<html><head><title>Tiêu đề mới</title></head><body><article><p>Nội dung mới không được chọn.</p></article></body></html>',
            ),
        ]);

        $token = $this->token();
        $this->withToken($token)->postJson('/api/admin/posts/ai/import', [
            'url' => 'https://example.test/partial-regenerate',
        ])->assertStatus(202);
        $parent = AiImport::query()->firstOrFail();
        $parent->update([
            'status' => 'ready',
            'result_json' => [
                'draft' => [
                    'title' => 'Tiêu đề cũ',
                    'content' => '<p>Nội dung do người dùng giữ.</p>',
                    'content_html' => '<p>Nội dung do người dùng giữ.</p>',
                ],
            ],
        ]);

        $response = $this->withToken($token)->postJson('/api/admin/posts/ai/import/'.$parent->id.'/regenerate', [
            'fields' => ['title'],
        ])->assertStatus(202);
        $child = AiImport::query()->findOrFail($response->json('data.job_id'));
        $result = (new ArticleImportService(new StructuredAiProvider))->run($child);

        $this->assertSame(['title'], $child->input_json['fields']);
        $this->assertSame('Tiêu đề mới', $result['draft']['title']);
        $this->assertSame('<p>Nội dung do người dùng giữ.</p>', $result['draft']['content']);
        $this->assertSame('Tiêu đề cũ', data_get($parent->fresh()->result_json, 'draft.title'));
    }

    /** Input: failed import. Output: same run requeued, not a duplicate candidate. */
    public function test_retry_reuses_failed_run(): void
    {
        Queue::fake();
        $token = $this->token();
        $this->withToken($token)->postJson('/api/admin/posts/ai/import', ['url' => 'https://example.test/retry'])->assertStatus(202);
        $import = AiImport::query()->firstOrFail();
        $import->update(['status' => 'failed', 'error_code' => 'TEMP']);

        $this->withToken($token)->postJson('/api/admin/posts/ai/import/'.$import->id.'/retry')->assertStatus(200)->assertJsonPath('data.job_id', $import->id);
        $this->assertSame(1, AiImport::query()->count());
        $this->assertSame('queued', $import->fresh()->status);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Áp dụng candidate vào Post draft và ghi provenance
     * =====================================================================
     * INPUT: candidate ready có title/content và field selection.
     * OUTPUT: Post draft mới cùng provenance theo field.
     * SIDE EFFECT: transaction tạo Post, SEO/taxonomy và audit provenance.
     * EXCEPTION/TRANSACTION: rollback toàn bộ khi payload hoặc candidate không hợp lệ.
     * =====================================================================
     */
    public function test_apply_candidate_creates_draft_and_provenance(): void
    {
        Queue::fake();
        $token = $this->token();
        $this->withToken($token)->postJson('/api/admin/posts/ai/import', [
            'url' => 'https://example.test/apply',
        ])->assertStatus(202);

        $import = AiImport::query()->firstOrFail();
        $import->update([
            'status' => 'ready',
            'result_json' => [
                'provider' => 'deterministic',
                'model' => 'deterministic',
                'prompt_key' => 'post.create.from_url',
                'prompt_version' => '1.0',
                'draft' => [
                    'title' => 'AI Draft',
                    'content_html' => '<p>Generated content</p>',
                ],
            ],
        ]);

        $response = $this->withToken($token)->postJson('/api/admin/posts/ai/import/'.$import->id.'/apply', [
            'fields' => ['title', 'content'],
        ]);

        $response->assertOk()->assertJsonPath('data.fields.0', 'title');
        $this->assertDatabaseHas('posts', ['title' => 'AI Draft', 'status' => 'draft']);
        $this->assertDatabaseHas('ai_provenances', ['run_id' => $import->id, 'field' => 'title']);
        $this->assertDatabaseHas('ai_provenances', ['run_id' => $import->id, 'field' => 'content']);
    }

    /**
     * Input: Post hiện có và chỉ chọn field title.
     * Output: title đổi, content cũ giữ nguyên; provenance chỉ ghi field đã chọn.
     * Side effect: update Post trong transaction và không tạo slug từ client.
     */
    public function test_apply_selected_fields_does_not_overwrite_unselected_fields(): void
    {
        Queue::fake();
        $token = $this->token();
        $actor = User::query()->where('email', '!=', '')->latest('id')->firstOrFail();
        $post = Post::factory()->create([
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'title' => 'Tiêu đề cũ',
            'content' => '<p>Nội dung cũ</p>',
        ]);

        $this->withToken($token)->postJson('/api/admin/posts/ai/import', [
            'url' => 'https://example.test/partial',
        ])->assertStatus(202);
        $import = AiImport::query()->firstOrFail();
        $import->update([
            'status' => 'ready',
            'result_json' => [
                'provider' => 'deterministic',
                'model' => 'deterministic',
                'draft' => [
                    'title' => 'Tiêu đề mới',
                    'content_html' => '<p>Nội dung AI không được chọn</p>',
                ],
            ],
        ]);

        $this->withToken($token)->postJson('/api/admin/posts/ai/import/'.$import->id.'/apply', [
            'target_id' => $post->id,
            'fields' => ['title'],
        ])->assertOk();

        $post->refresh();
        $this->assertSame('Tiêu đề mới', $post->title);
        $this->assertSame('<p>Nội dung cũ</p>', $post->content);
        $this->assertDatabaseHas('ai_provenances', ['run_id' => $import->id, 'field' => 'title']);
        $this->assertDatabaseMissing('ai_provenances', ['run_id' => $import->id, 'field' => 'content']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giữ provenance khi candidate được merge vào PostForm
     * =====================================================================
     * INPUT: Post create payload có ai_run_id/ai_fields từ form frontend.
     * OUTPUT: Post được lưu và audit lineage lấy metadata server-side.
     * SIDE EFFECT: tạo Post, provenance và cập nhật applied metadata của run.
     * EXCEPTION/TRANSACTION: candidate sai ownership/status phải rollback create.
     * =====================================================================
     */
    public function test_regular_post_create_records_ai_provenance_from_form_metadata(): void
    {
        Queue::fake();
        $token = $this->token();
        $this->withToken($token)->postJson('/api/admin/posts/ai/import', [
            'url' => 'https://example.test/form-provenance',
        ])->assertStatus(202);

        $import = AiImport::query()->firstOrFail();
        $import->update([
            'status' => 'ready',
            'result_json' => [
                'provider' => 'deterministic',
                'model' => 'deterministic',
                'prompt_key' => 'post.create.from_url',
                'prompt_version' => '1.0',
                'draft' => [
                    'title' => 'Form AI title',
                    'content_html' => '<p>Form AI content</p>',
                ],
            ],
        ]);

        $this->withToken($token)->postJson('/api/admin/posts', [
            'title' => 'Form AI title',
            'content' => '<p>Form AI content</p>',
            'status' => 'draft',
            'ai_run_id' => $import->id,
            'ai_fields' => ['title', 'content'],
        ])->assertCreated();

        $post = Post::query()->where('title', 'Form AI title')->firstOrFail();
        $this->assertDatabaseHas('ai_provenances', [
            'run_id' => $import->id,
            'target_id' => $post->id,
            'field' => 'title',
            'provider' => 'deterministic',
        ]);
        $this->assertDatabaseHas('ai_provenances', [
            'run_id' => $import->id,
            'target_id' => $post->id,
            'field' => 'content',
        ]);
        $this->assertSame($post->id, $import->fresh()->applied_target_id);
        $this->assertSame(['title', 'content'], $import->fresh()->applied_fields);
    }
}
