<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/** Khóa contract ảnh content bằng URL, Gallery độc lập và chuyển dữ liệu cũ; DB/storage cô lập. */
class PostGalleryTest extends TestCase
{
    use UsesIsolatedDatabase;

    /** Input: test mới. Output: schema/quyền/storage riêng; không gọi provider hoặc DB local. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('media_public');
        config()->set('media-library.asset_disks.public', 'media_public');
    }

    /** Input: test kết thúc. Output: giải phóng schema riêng. */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Input: quyền. Output: token admin fixture và guard/cache mới. */
    private function token(array $permissions = ['posts.manage', 'media.attach']): string
    {
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo($permissions);
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $actor->createToken('post-gallery-test', ['admin'])->plainTextToken;
    }

    /** Input: attributes. Output: ảnh fixture có URL thật theo metadata Spatie, không chạy conversion. */
    private function image(array $attributes = []): MediaAsset
    {
        $asset = MediaAsset::factory()->image()->create($attributes);
        $asset->media()->create([
            'collection_name' => 'library', 'name' => 'Gallery image', 'file_name' => 'gallery.png',
            'disk' => 'media_public', 'conversions_disk' => 'media_public', 'mime_type' => 'image/png',
            'size' => 100, 'manipulations' => [], 'custom_properties' => [],
            'generated_conversions' => [], 'responsive_images' => [], 'order_column' => 1,
        ]);

        return $asset->fresh('media');
    }

    /** Input: link ngoài, link gốc và link lặp. Output: giữ nguyên HTML, không cần quyền media.attach. */
    public function test_content_saves_image_links_and_repeated_occurrences_without_relationships(): void
    {
        $token = $this->token(['posts.manage']);
        $html = '<p>Mở đầu</p><img src="https://images.example.test/photo.jpg" alt="Lần đầu">'
            .'<p>Đoạn khác</p><img src="https://images.example.test/photo.jpg" alt="Lần hai">'
            .'<img src="/images/local.png" alt="Link gốc">';
        $created = $this->withToken($token)->postJson('/api/admin/posts', ['title' => 'Ảnh bằng link', 'content' => $html])
            ->assertCreated()->assertJsonPath('data.content', $html)
            ->assertJsonPath('data.media.gallery_images', [])->assertJsonMissingPath('data.media.content_images');

        $this->withToken($token)->getJson('/api/admin/posts/'.$created->json('data.id'))
            ->assertOk()->assertJsonPath('data.content', $html)->assertJsonPath('data.media.gallery_images', []);
        $this->assertDatabaseCount('media_asset_usages', 0);
        $this->assertDatabaseCount('media_assets', 0);
    }

    /** Input: Gallery gồm một ảnh cũng xuất hiện hai lần trong HTML. Output: các luồng giữ độc lập qua lưu/clear/reorder. */
    public function test_gallery_order_and_content_are_independent_during_create_and_partial_updates(): void
    {
        $token = $this->token();
        $inline = $this->image();
        $first = $this->image();
        $html = '<img src="'.e($inline->getFirstMediaUrl('library')).'" alt="Một">'
            .'<p>Ở đoạn khác</p><img src="'.e($inline->getFirstMediaUrl('library')).'" alt="Hai">';
        $created = $this->withToken($token)->postJson('/api/admin/posts', [
            'title' => 'Hai danh sách riêng', 'content' => $html,
            'media' => ['gallery_image_ids' => [$first->id, $inline->id]],
        ])->assertCreated()->assertJsonPath('data.content', $html)
            ->assertJsonPath('data.media.gallery_images.0.id', $first->id)
            ->assertJsonPath('data.media.gallery_images.1.id', $inline->id);
        $id = $created->json('data.id');
        $this->assertDatabaseCount('media_asset_usages', 2);
        $this->assertDatabaseMissing('media_asset_usages', ['field' => 'post.content_images']);

        $this->withToken($token)->putJson('/api/admin/posts/'.$id, ['content' => '<p>Không có ảnh</p>'])
            ->assertOk()->assertJsonPath('data.media.gallery_images.0.id', $first->id)
            ->assertJsonPath('data.media.gallery_images.1.id', $inline->id);
        $this->withToken($token)->putJson('/api/admin/posts/'.$id, ['media' => ['gallery_image_ids' => [$inline->id, $first->id]]])
            ->assertOk()->assertJsonPath('data.content', '<p>Không có ảnh</p>')
            ->assertJsonPath('data.media.gallery_images.0.id', $inline->id)
            ->assertJsonPath('data.media.gallery_images.1.id', $first->id);
        $this->withToken($token)->putJson('/api/admin/posts/'.$id, ['content' => $html])->assertOk();
        $this->withToken($token)->putJson('/api/admin/posts/'.$id, ['media' => ['gallery_image_ids' => []]])
            ->assertOk()->assertJsonPath('data.content', $html)->assertJsonPath('data.media.gallery_images', []);
        $this->assertDatabaseCount('media_asset_usages', 0);
        $this->assertDatabaseHas('media_assets', ['id' => $inline->id, 'deleted_at' => null]);
    }

    /** Input: Gallery sai ID/kind/visibility hoặc ID lặp. Output: rollback giữ nội dung và Gallery đã lưu. */
    public function test_gallery_validation_rolls_back_content_and_preserves_the_existing_collection(): void
    {
        $token = $this->token();
        $image = $this->image();
        $private = $this->image(['visibility' => 'private']);
        $document = MediaAsset::factory()->document()->create();
        $created = $this->withToken($token)->postJson('/api/admin/posts', [
            'title' => 'Gallery hợp lệ', 'content' => '<p>Giữ lại</p>', 'media' => ['gallery_image_ids' => [$image->id]],
        ])->assertCreated();
        $id = $created->json('data.id');
        foreach ([[$private->id], [$document->id], [999999], [$image->id, $image->id]] as $ids) {
            $this->withToken($token)->putJson('/api/admin/posts/'.$id, [
                'content' => '<p>Không được lưu</p>', 'media' => ['gallery_image_ids' => $ids],
            ])->assertUnprocessable();
            $this->withToken($token)->getJson('/api/admin/posts/'.$id)->assertOk()
                ->assertJsonPath('data.content', '<p>Giữ lại</p>')->assertJsonPath('data.media.gallery_images.0.id', $image->id);
        }
        $this->withToken($this->token(['posts.manage']))->putJson('/api/admin/posts/'.$id, [
            'media' => ['gallery_image_ids' => [$image->id]],
        ])->assertForbidden();
    }

    /** Input: ảnh tạm hoặc URL thực thi/file. Output: 422 content; không lưu Post hoặc quan hệ. */
    public function test_content_rejects_temporary_or_executable_urls_without_fetching_images(): void
    {
        $token = $this->token(['posts.manage']);
        foreach (['blob:temporary', 'data:image/png;base64,AAAA', 'javascript:alert(1)', 'file:///C:/image.png', '//images.example.test/photo.jpg', ''] as $url) {
            $this->withToken($token)->postJson('/api/admin/posts', [
                'title' => 'Link không hợp lệ', 'content' => '<img src="'.e($url).'" alt="Ảnh">',
            ])->assertUnprocessable()->assertJsonValidationErrors('content');
        }
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('media_asset_usages', 0);
    }

    /** Input: contract content images cũ qua Post/attach API. Output: từ chối tạo lại quan hệ ảnh content. */
    public function test_legacy_content_image_payload_and_attach_field_are_not_writable(): void
    {
        $token = $this->token();
        $asset = $this->image();
        $post = Post::factory()->create(['content' => '<p>Nội dung</p>']);
        $this->withToken($token)->putJson('/api/admin/posts/'.$post->id, [
            'media' => ['content_image_ids' => [$asset->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors('media');
        $this->withToken($token)->postJson('/api/admin/media-assets/'.$asset->id.'/usages', [
            'linkable_type' => 'post', 'linkable_id' => $post->id, 'field' => 'post.content_images',
        ])->assertUnprocessable()->assertJsonValidationErrors('field');
        $this->assertDatabaseCount('media_asset_usages', 0);
    }

    /** Input: usage cũ lẫn ảnh HTML có/không ID và Gallery riêng. Output: bỏ quan hệ inline, giữ Gallery/order/file và idempotent. */
    public function test_migration_separates_legacy_gallery_and_removes_inline_relationships_without_deleting_files(): void
    {
        $inline = $this->image();
        $urlOnly = $this->image();
        $first = $this->image();
        $second = $this->image();
        $html = '<img data-media-asset-id="'.$inline->id.'" src="'.e($inline->getFirstMediaUrl('library')).'">'
            .'<img src="'.e($urlOnly->getFirstMediaUrl('library')).'">';
        $post = Post::factory()->create(['content' => $html]);
        foreach ([$inline, $second, $urlOnly, $first] as $index => $asset) {
            DB::table('media_asset_usages')->insert([
                'linkable_type' => 'post', 'linkable_id' => $post->id, 'media_asset_id' => $asset->id,
                'field' => 'post.content_images', 'sort_order' => $index, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $migration = require database_path('migrations/2026_10_06_100000_separate_post_gallery_from_content_images.php');
        $migration->up();
        $migration->up();

        $this->assertSame([$second->id, $first->id], DB::table('media_asset_usages')->where('field', 'post.gallery')
            ->orderBy('sort_order')->pluck('media_asset_id')->all());
        $this->assertSame([0, 1], DB::table('media_asset_usages')->where('field', 'post.gallery')
            ->orderBy('sort_order')->pluck('sort_order')->all());
        $this->assertDatabaseMissing('media_asset_usages', ['field' => 'post.content_images']);
        $this->assertDatabaseCount('media_assets', 4);
        $this->assertDatabaseCount('media', 4);
        $this->assertSame($html, $post->fresh()->content);
    }
}
