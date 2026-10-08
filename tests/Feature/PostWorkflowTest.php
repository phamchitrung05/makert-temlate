<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử workflow review/publish và quyền chi tiết của Post.
 * =====================================================================
 *
 * Bộ test khóa contract P-01: CRUD không tự đổi lifecycle, từng endpoint
 * yêu cầu permission riêng, publish ghi published_at từ server và activity
 * audit giữ lịch sử chuyển trạng thái.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp()/tearDown(): dựng và dọn database cô lập.
 * - token(): tạo Bearer token admin với permission được chọn.
 * - test_post_permissions_are_split_from_publish(): kiểm boundary CRUD/publish.
 * - test_review_reject_publish_archive_lifecycle_is_audited(): kiểm state flow.
 * - test_crud_cannot_write_lifecycle_fields(): chặn bypass qua PUT/POST create.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTTP request admin và Post fixture.
 * - OUTPUT: assertions về status, published_at, quyền và activity_log.
 * =====================================================================
 */
class PostWorkflowTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị schema cô lập và permission catalog
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Migrate SQLite in-memory và seed permission guard admin.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn database cô lập sau test
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Rollback schema và giải phóng connection test.
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo token admin với permission cụ thể
     * =====================================================================
     *
     * INPUT:
     * - $permissions: danh sách permission guard admin.
     *
     * OUTPUT:
     * - string: plain text token có ability admin.
     *
     * SIDE EFFECT:
     * - Tạo User, permission relation và personal access token.
     */
    private function token(array $permissions): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo($permissions);
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('post-workflow-test', ['admin'])->plainTextToken;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra CRUD và publish có permission độc lập
     * =====================================================================
     *
     * OUTPUT:
     * - posts.create tạo draft; posts.update không được publish; posts.publish được publish.
     */
    public function test_post_permissions_are_split_from_publish(): void
    {
        $createToken = $this->token(['posts.create']);
        $created = $this->withToken($createToken)
            ->postJson('/api/admin/posts', ['title' => 'Bài workflow'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');
        $postId = $created->json('data.id');

        $updateToken = $this->token(['posts.update']);
        $this->withToken($updateToken)
            ->postJson('/api/admin/posts/'.$postId.'/publish')
            ->assertForbidden();
        $this->assertSame(PostStatus::Draft, Post::findOrFail($postId)->status);

        $publishToken = $this->token(['posts.publish']);
        $this->withToken($publishToken)
            ->postJson('/api/admin/posts/'.$postId.'/publish')
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.published_at', fn ($value): bool => is_string($value) && $value !== '');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra chuỗi review → reject → review → publish → archive
     * =====================================================================
     *
     * OUTPUT:
     * - Mỗi transition đúng state; published_at được giữ khi archive; audit có event/reason.
     */
    public function test_review_reject_publish_archive_lifecycle_is_audited(): void
    {
        $post = Post::factory()->create(['status' => PostStatus::Draft]);
        $token = $this->token(['posts.review', 'posts.publish', 'posts.archive']);

        $this->withToken($token)->postJson('/api/admin/posts/'.$post->id.'/submit-review')
            ->assertOk()->assertJsonPath('data.status', 'pending_review');
        $this->withToken($token)->postJson('/api/admin/posts/'.$post->id.'/reject', ['reason' => 'Thiếu dẫn chứng'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->withToken($token)->postJson('/api/admin/posts/'.$post->id.'/submit-review')
            ->assertOk()->assertJsonPath('data.status', 'pending_review');
        $published = $this->withToken($token)->postJson('/api/admin/posts/'.$post->id.'/publish')
            ->assertOk()->assertJsonPath('data.status', 'published');

        $publishedAt = $published->json('data.published_at');
        $this->assertNotEmpty($publishedAt);
        $this->withToken($token)->postJson('/api/admin/posts/'.$post->id.'/archive')
            ->assertOk()->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.published_at', $publishedAt);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'posts', 'subject_id' => $post->id, 'event' => 'review_submitted',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'posts', 'subject_id' => $post->id, 'event' => 'rejected',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'posts', 'subject_id' => $post->id, 'event' => 'published',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'posts', 'subject_id' => $post->id, 'event' => 'archived',
        ]);
        $this->assertSame('Thiếu dẫn chứng', Activity::query()
            ->where('log_name', 'posts')->where('subject_id', $post->id)->where('event', 'rejected')
            ->latest('id')->firstOrFail()->properties->get('reason'));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn status/published_at bypass qua CRUD
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 422 khi create published hoặc PUT lifecycle field; database không đổi.
     */
    public function test_crud_cannot_write_lifecycle_fields(): void
    {
        $token = $this->token(['posts.create', 'posts.update']);
        $this->withToken($token)->postJson('/api/admin/posts', [
            'title' => 'Không được publish trực tiếp', 'status' => 'published',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $post = Post::factory()->create(['status' => PostStatus::Draft]);
        $this->withToken($token)->putJson('/api/admin/posts/'.$post->id, [
            'title' => 'Đổi nội dung', 'status' => 'published', 'published_at' => now()->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['status', 'published_at']);
        $this->assertSame(PostStatus::Draft, $post->fresh()->status);
        $this->assertNull($post->fresh()->published_at);
    }
}
