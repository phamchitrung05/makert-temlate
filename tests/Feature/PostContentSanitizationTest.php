<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khóa boundary sanitize HTML khi tạo/cập nhật Post.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: setUp(), tearDown(), token() và các test_*.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): HTML admin gửi qua API -> HTML allowlist.
 * =====================================================================
 */
class PostContentSanitizationTest extends TestCase
{
    use UsesIsolatedDatabase;

    /** Input: test mới. Output: schema cô lập và role/permission fixture. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
    }

    /** Input: test kết thúc. Output: schema cô lập được giải phóng. */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Input: permission Post. Output: token admin dùng cho request lifecycle. */
    private function token(): string
    {
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo(['posts.manage']);
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $actor->createToken('post-content-sanitization-test', ['admin'])->plainTextToken;
    }

    /**
     * Input: HTML có script, event handler, iframe, URL nguy hiểm và markup hợp lệ.
     * Output: create lưu semantic HTML an toàn và giữ media/link hợp lệ.
     */
    public function test_create_sanitizes_untrusted_html_and_preserves_allowed_markup(): void
    {
        $html = '<h2>Tiêu đề giữ lại</h2><p onclick="bad()">Nội dung <strong>đậm</strong> '
            .'<a href="javascript:alert(1)">link xấu</a> '
            .'<a href="https://example.test/docs" target="_blank">link đúng</a></p>'
            .'<script>alert(1)</script><iframe src="https://evil.test"></iframe>'
            .'<img src="https://images.example.test/a.jpg" alt="Ảnh" data-media-asset-id="7">'
            .'<pre><code>if (true) { echo "ok"; }</code></pre>';

        $content = $this->withToken($this->token())
            ->postJson('/api/admin/posts', ['title' => 'Sanitize create', 'content' => $html])
            ->assertCreated()
            ->json('data.content');

        $this->assertStringContainsString('<h2>Tiêu đề giữ lại</h2>', $content);
        $this->assertStringContainsString('<strong>đậm</strong>', $content);
        $this->assertStringContainsString('href="https://example.test/docs"', $content);
        $this->assertStringContainsString('rel="noopener noreferrer"', $content);
        $this->assertStringContainsString('data-media-asset-id="7"', $content);
        $this->assertStringContainsString('<pre><code>', $content);
        $this->assertStringNotContainsString('<script', $content);
        $this->assertStringNotContainsString('<iframe', $content);
        $this->assertStringNotContainsString('onclick', $content);
        $this->assertStringNotContainsString('javascript:', $content);
    }

    /**
     * Input: Post đã tạo và HTML độc hại gửi ở update.
     * Output: update cũng đi qua cùng sanitizer trước khi ghi đè content.
     */
    public function test_update_uses_the_same_sanitization_boundary(): void
    {
        $token = $this->token();
        $created = $this->withToken($token)->postJson('/api/admin/posts', [
            'title' => 'Sanitize update',
            'content' => '<p>Nội dung ban đầu</p>',
        ])->assertCreated();
        $id = $created->json('data.id');

        $this->withToken($token)->putJson('/api/admin/posts/'.$id, [
            'content' => '<div style="display:none" data-secret="x"><p>Được giữ</p><svg><script>bad()</script></svg></div>',
        ])->assertOk()
            ->assertJsonPath('data.content', '<div><p>Được giữ</p></div>');
    }
}
