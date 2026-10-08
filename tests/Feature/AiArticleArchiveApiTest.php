<?php

namespace Tests\Feature;

use App\Models\AiArticleArchive;
use App\Models\Post;
use App\Models\User;
use App\Services\Ai\Content\Data\ArticleInputHasher;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm API đọc kho AI Approved đa model.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: setUp(), tearDown(), token(), archive(),
 * test_index_returns_approved_generated_archives_across_targets(),
 * test_show_returns_allowlisted_sanitized_snapshot(),
 * test_filters_by_title_and_archive_date(), test_requires_posts_manage().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : archive fixture approved/unapproved và request GET admin.
 * - OUTPUT: list/detail allowlist, phân trang và permission đúng contract.
 * - SIDE EFFECT: chỉ DB SQLite cô lập; không gọi AI/network.
 * =====================================================================
 */
class AiArticleArchiveApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    /** Input: PHPUnit setup. Output: schema + permission catalog cô lập. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
    }

    /** Input: PHPUnit teardown. Output: database test được giải phóng. */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Input: permission posts.manage. Output: Bearer token admin test. */
    private function token(bool $withPermission = true): string
    {
        $user = User::factory()->create(['status' => 'active']);
        if ($withPermission) {
            $user->givePermissionTo('posts.manage');
            $this->flushPermissionCache();
        }
        Auth::forgetGuards();

        return $user->createToken('ai-approved-api-test', ['admin'])->plainTextToken;
    }

    /** Input: archive overrides. Output: snapshot fixture có hash hợp lệ. */
    private function archive(array $overrides = []): AiArticleArchive
    {
        $source = ['title' => 'Nguồn thử', 'content_html' => '<p>Nội dung nguồn</p>', 'source_url' => 'https://example.test/source'];
        $draft = ['title' => 'Bài AI đã duyệt', 'content_html' => '<p>Bài <script>alert(1)</script> sạch</p>', 'excerpt' => 'Tóm tắt'];
        $context = ['provider' => 'fixture', 'model' => 'writer-test', 'writing_profile' => ['name' => 'Mẫu thử', 'version' => 1]];
        $payload = [
            'run_id' => (string) Str::uuid(), 'session_id' => (string) Str::uuid(), 'parent_run_id' => null,
            'generation_no' => 1, 'snapshot_version' => 1, 'created_by' => null,
            'target_type' => 'post', 'operation' => 'create', 'generation_status' => 'ready',
            'content_origin' => 'ai_original', 'has_generated_content' => true,
            'source_hash' => ArticleInputHasher::hash($source), 'content_hash' => ArticleInputHasher::hash($draft),
            'source_snapshot_json' => $source, 'draft_snapshot_json' => $draft, 'context_snapshot_json' => $context,
            'diagnostics_json' => null, 'failure_code' => null,
            'generation_started_at' => '2026-10-08 08:00:00', 'generation_completed_at' => '2026-10-08 08:05:00',
            'lifecycle_json' => ['review_status' => 'approved', 'reviewed_at' => '2026-10-08T08:10:00+00:00'],
            'applied_target_id' => null,
        ];
        $payload['payload_hash'] = ArticleInputHasher::hash(Arr::only($payload, AiArticleArchive::SNAPSHOT_FIELDS));

        $archive = AiArticleArchive::query()->create(array_replace($payload, $overrides));
        if (array_key_exists('created_at', $overrides)) {
            $archive->forceFill(['created_at' => $overrides['created_at']])->saveQuietly();
        }

        return $archive;
    }

    /** Input: nhiều target và lifecycle. Output: chỉ approved/generated rows, gồm resource target. */
    public function test_index_returns_approved_generated_archives_across_targets(): void
    {
        $post = Post::factory()->create(['title' => 'Post liên kết']);
        $this->archive(['applied_target_id' => $post->id]);
        $this->archive(['target_type' => 'resource', 'applied_target_id' => 77]);
        $this->archive(['lifecycle_json' => ['review_status' => 'rejected']]);
        $this->archive(['has_generated_content' => false, 'content_origin' => 'deterministic']);

        $response = $this->withToken($this->token())->getJson('/api/admin/ai-agent/approved-archives?per_page=100');

        $response->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.target_type', 'resource')
            ->assertJsonPath('data.1.target_type', 'post')
            ->assertJsonPath('meta.pagination.total', 2);
    }

    /** Input: archive có HTML nguy hiểm. Output: detail sanitize và không lộ JSON private. */
    public function test_show_returns_allowlisted_sanitized_snapshot(): void
    {
        $archive = $this->archive();

        $response = $this->withToken($this->token())->getJson('/api/admin/ai-agent/approved-archives/'.$archive->id)
            ->assertOk()
            ->assertJsonPath('data.id', $archive->id)
            ->assertJsonPath('data.original_available', true)
            ->assertJsonPath('data.target_type', 'post')
            ->assertJsonPath('data.context.model', 'writer-test')
            ->assertJsonMissingPath('data.diagnostics_json')
            ->assertJsonMissingPath('data.draft_snapshot_json');
        $this->assertStringNotContainsString('<script>', $response->getContent());
        $this->assertStringContainsString('<p>Bài', $response->json('data.draft.content_html'));
    }

    /** Input: title/date query. Output: filter inclusive và validation khoảng ngày. */
    public function test_filters_by_title_and_archive_date(): void
    {
        $this->archive(['created_at' => '2026-10-06 12:00:00']);
        $this->archive(['created_at' => '2026-10-08 12:00:00', 'draft_snapshot_json' => ['title' => 'Bài khác']]);

        $this->withToken($this->token())->getJson('/api/admin/ai-agent/approved-archives?search=khác&created_from=2026-10-08&created_to=2026-10-08')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Bài khác');
        $this->withToken($this->token())->getJson('/api/admin/ai-agent/approved-archives?created_from=2026-10-09&created_to=2026-10-08')
            ->assertUnprocessable()->assertJsonPath('errors.created_to.0', 'Ngày kết thúc phải lớn hơn hoặc bằng ngày bắt đầu.');
    }

    /** Input: token không có posts.manage. Output: route từ chối đọc kho. */
    public function test_requires_posts_manage(): void
    {
        $this->withToken($this->token(false))->getJson('/api/admin/ai-agent/approved-archives')->assertForbidden();
    }
}
