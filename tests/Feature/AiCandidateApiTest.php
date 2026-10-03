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
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị schema và quyền trong database test cô lập
     * =====================================================================
     * INPUT: PHPUnit lifecycle.
     * OUTPUT: Schema test và permission seed.
     * SIDE EFFECT: Tạo database test; không tác động dữ liệu ứng dụng thật.
     * EXCEPTION/TRANSACTION: Chỉ test setup; cleanup trong tearDown.
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
     * CHỨC NĂNG: Giải phóng tài nguyên database sau test
     * =====================================================================
     * INPUT: PHPUnit lifecycle.
     * OUTPUT: Database connection/test schema được dọn.
     * SIDE EFFECT: Dọn database test cô lập rồi gọi parent teardown.
     * EXCEPTION/TRANSACTION: Chỉ test cleanup; không tác động production.
     * =====================================================================
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo admin token có posts.manage cho test candidate
     * =====================================================================
     * INPUT: Fixture user active.
     * OUTPUT: Personal token chỉ dùng trong test.
     * SIDE EFFECT: Ghi user/permission/token trong DB test và xóa permission cache.
     * EXCEPTION/TRANSACTION: Không gọi AI thật; DB test được dọn sau test.
     * =====================================================================
     */
    private function token(): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('posts.manage');
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('ai-candidate-test', ['admin'])->plainTextToken;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng regenerate không ghi đè candidate gốc
     * =====================================================================
     * INPUT: Run ready thuộc actor và instructions override.
     * OUTPUT: Child run cùng session; result của parent không đổi.
     * SIDE EFFECT: Gọi API nội bộ/Queue fake và ghi run trong DB test.
     * EXCEPTION/TRANSACTION: Không gọi AI thật; DB test được dọn sau test.
     * =====================================================================
     */
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
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng chỉ field được chọn bị thay khi regenerate
     * =====================================================================
     * INPUT: Parent ready và source HTML từ HTTP fake; chỉ chọn title.
     * OUTPUT: Title mới, content giữ theo parent, result parent không đổi.
     * SIDE EFFECT: Child run đọc source qua HTTP fake; dùng deterministic provider.
     * EXCEPTION/TRANSACTION: Không gọi AI thật; DB test được dọn sau test.
     * =====================================================================
     */
    public function test_regenerate_selected_field_preserves_unselected_parent_fields(): void
    {
        Queue::fake();
        config()->set('ai-providers.connections.http-json.endpoint', null);
        config()->set('ai-providers.connections.http-json.key', null);
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

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng retry kỹ thuật giữ nguyên run UUID
     * =====================================================================
     * INPUT: Run failed và Queue fake.
     * OUTPUT: Một run duy nhất được requeue; không tạo candidate trùng.
     * SIDE EFFECT: Gọi API nội bộ và cập nhật run trong DB test.
     * EXCEPTION/TRANSACTION: Không gọi AI thật; DB test được dọn sau test.
     * =====================================================================
     */
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
     * =====================================================================
     * CHỨC NĂNG: Áp dụng field title mà không ghi đè content hiện có
     * =====================================================================
     * INPUT: Post hiện có, candidate ready và chỉ chọn field title.
     * OUTPUT: title đổi, content cũ giữ nguyên; provenance chỉ ghi field đã chọn.
     * SIDE EFFECT: update Post trong transaction và không tạo slug từ client.
     * EXCEPTION/TRANSACTION: rollback nếu candidate/field không hợp lệ.
     * =====================================================================
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

    /**
     * =====================================================================
     * CHỨC NĂNG: Giữ lineage content và thumbnail từ hai run riêng
     * =====================================================================
     * INPUT: hai run ready cùng actor và ai_runs không overlap field.
     * OUTPUT: một Post có provenance đúng owner theo field.
     * SIDE EFFECT: tạo post/audit trong transaction; không gọi provider thật.
     * EXCEPTION/TRANSACTION: overlap hoặc image asset mismatch bị rollback.
     * =====================================================================
     */
    public function test_post_create_accepts_multiple_non_overlapping_ai_runs(): void
    {
        Queue::fake();
        $token = $this->token();
        $runIds = [];
        foreach (['content-run', 'seo-run'] as $slug) {
            $this->withToken($token)->postJson('/api/admin/posts/ai/import', ['url' => 'https://example.test/'.$slug])->assertStatus(202);
            $run = AiImport::query()->latest('created_at')->firstOrFail();
            $runIds[] = $run->id;
            $run->update([
                'status' => 'ready', 'result_json' => ['provider' => 'deterministic', 'model' => 'deterministic', 'draft' => []],
            ]);
        }

        $this->withToken($token)->postJson('/api/admin/posts', [
            'title' => 'Multi run', 'content' => '<p>Content</p>', 'status' => 'draft',
            'ai_runs' => [
                ['run_id' => $runIds[0], 'fields' => ['content']],
                ['run_id' => $runIds[1], 'fields' => ['seo']],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('ai_provenances', ['run_id' => $runIds[0], 'field' => 'content']);
        $this->assertDatabaseHas('ai_provenances', ['run_id' => $runIds[1], 'field' => 'seo']);
    }
}
