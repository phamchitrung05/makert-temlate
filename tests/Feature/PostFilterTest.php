<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử bộ lọc tác giả và ngày của Post Admin.
 * =====================================================================
 *
 * P-04 chỉ bao phủ author/date filter; post_type và Gallery được tách sang
 * kế hoạch riêng để không làm thay đổi phạm vi danh sách Post hiện tại.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp()/tearDown(): dựng và dọn database cô lập.
 * - token(): tạo Bearer token có quyền xem Post.
 * - test_filters_posts_by_author_and_inclusive_creation_date(): lọc kết hợp.
 * - test_rejects_reversed_creation_date_range(): chặn khoảng ngày đảo chiều.
 * - test_authors_returns_only_users_used_by_posts(): trả option tác giả đúng.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : query filter trên API Admin Post.
 * - OUTPUT: danh sách Post/tác giả và lỗi validation đúng contract.
 * =====================================================================
 */
class PostFilterTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị schema cô lập và permission catalog
     * =====================================================================
     * SIDE EFFECT: migrate SQLite in-memory và seed permission guard admin.
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
     * CHỨC NĂNG: Dọn database cô lập sau test
     * =====================================================================
     * SIDE EFFECT: rollback schema và giải phóng connection test.
     * =====================================================================
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo token admin với permission xem Post
     * =====================================================================
     * OUTPUT: plain text token có ability admin.
     * SIDE EFFECT: tạo User, permission relation và personal access token.
     * =====================================================================
     */
    private function token(): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('posts.view');
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('post-filter-test', ['admin'])->plainTextToken;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc Post theo tác giả và ngày tạo, hai đầu inclusive
     * =====================================================================
     * OUTPUT: chỉ Post đúng author_id và created_at trong khoảng ngày.
     * =====================================================================
     */
    public function test_filters_posts_by_author_and_inclusive_creation_date(): void
    {
        $author = User::factory()->create(['name' => 'Author Alpha']);
        $otherAuthor = User::factory()->create(['name' => 'Author Beta']);
        $inside = Post::factory()->create([
            'created_by' => $author->id,
            'title' => 'Bài nằm trong khoảng',
            'created_at' => '2026-10-05 12:00:00',
        ]);
        Post::factory()->create([
            'created_by' => $author->id,
            'title' => 'Bài trước khoảng',
            'created_at' => '2026-10-04 23:59:59',
        ]);
        Post::factory()->create([
            'created_by' => $otherAuthor->id,
            'title' => 'Khác tác giả',
            'created_at' => '2026-10-05 12:00:00',
        ]);

        $this->withToken($this->token())
            ->getJson('/api/admin/posts?author_id='.$author->id.'&created_from=2026-10-05&created_to=2026-10-05')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inside->id)
            ->assertJsonPath('data.0.created_by', $author->id)
            ->assertJsonPath('data.0.author.id', $author->id)
            ->assertJsonPath('data.0.author.name', 'Author Alpha');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn khoảng ngày tạo bị đảo chiều
     * =====================================================================
     * OUTPUT: HTTP 422 và lỗi gắn vào created_to.
     * =====================================================================
     */
    public function test_rejects_reversed_creation_date_range(): void
    {
        $this->withToken($this->token())
            ->getJson('/api/admin/posts?created_from=2026-10-06&created_to=2026-10-05')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('created_to');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả option tác giả đang được dùng bởi Post
     * =====================================================================
     * OUTPUT: user có Post được trả một lần; user chưa có Post bị loại.
     * =====================================================================
     */
    public function test_authors_returns_only_users_used_by_posts(): void
    {
        $author = User::factory()->create(['name' => 'Author Alpha']);
        $unused = User::factory()->create(['name' => 'Unused User']);
        Post::factory()->create(['created_by' => $author->id]);

        $response = $this->withToken($this->token())
            ->getJson('/api/admin/posts/authors')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $response->assertJsonPath('data.0.id', $author->id)
            ->assertJsonPath('data.0.name', 'Author Alpha')
            ->assertJsonMissing(['id' => $unused->id]);
    }
}
