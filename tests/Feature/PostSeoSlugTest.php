<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\Resource;
use App\Models\Slug;
use App\Models\User;
use App\Services\SeoMetadataService;
use App\Services\SlugService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khóa contract Post slug preview và SEO persistence.
 * CÁC HÀM/METHOD TRONG FILE: setUp/tearDown/token; các test_* về slug/SEO/media;
 * test_shared_preview_supports_allowlisted_models_without_writes;
 * test_shared_preview_is_scoped_and_excludes_the_edited_model;
 * test_shared_preview_enforces_model_permissions_and_validates_input.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): HTTP/service trong SQLite cô lập -> assertions.
 * =====================================================================
 */
class PostSeoSlugTest extends TestCase
{
    use UsesIsolatedDatabase;

    /** Input: không có. Output: schema SQLite cô lập và quyền admin. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
    }

    /** Input: test đã chạy. Output: dọn database cô lập, không đụng DB local. */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Input: quyền tùy chọn. Output: token admin đang hoạt động. */
    private function token(array $permissions = ['posts.manage']): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo($permissions);
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('post-test', ['admin'])->plainTextToken;
    }

    /** Input: các alias được hỗ trợ. Output: slug cùng contract, không tạo bản ghi. */
    public function test_shared_preview_supports_allowlisted_models_without_writes(): void
    {
        $token = $this->token(['posts.manage', 'resources.create', 'taxonomy.manage']);
        $before = Slug::query()->count();
        foreach (['post', 'resource', 'category', 'tag', 'technology'] as $type) {
            $this->withToken($token)->postJson('/api/admin/slugs/preview', [
                'title' => 'Đường Đến Thành Công', 'model_type' => $type,
            ])->assertOk()->assertJsonPath('data.slug', 'duong-den-thanh-cong')
                ->assertJsonPath('data.model_type', $type);
        }
        $this->assertDatabaseCount('slugable', $before);
    }

    /** Input: slug trùng giữa model và ID edit. Output: suffix theo type, loại trừ chính model. */
    public function test_shared_preview_is_scoped_and_excludes_the_edited_model(): void
    {
        $token = $this->token(['posts.manage', 'resources.create']);
        $post = Post::factory()->create(['title' => 'Same title']);
        $this->withToken($token)->postJson('/api/admin/slugs/preview', [
            'title' => 'Same title', 'model_type' => 'post',
        ])->assertOk()->assertJsonPath('data.slug', 'same-title-1');
        $this->withToken($token)->postJson('/api/admin/slugs/preview', [
            'title' => 'Same title', 'model_type' => 'resource',
        ])->assertOk()->assertJsonPath('data.slug', 'same-title');
        $this->withToken($token)->postJson('/api/admin/slugs/preview', [
            'title' => 'Same title', 'model_type' => 'post', 'model_id' => $post->id,
        ])->assertOk()->assertJsonPath('data.slug', 'same-title');
    }

    /** Input: thiếu quyền, alias lạ/FQCN, dữ liệu không hợp lệ. Output: 401/403/404/422 đúng scope. */
    public function test_shared_preview_enforces_model_permissions_and_validates_input(): void
    {
        $payload = ['title' => 'Title', 'model_type' => 'post'];
        $this->postJson('/api/admin/slugs/preview', $payload)->assertUnauthorized();
        $this->withToken($this->token([]))->postJson('/api/admin/slugs/preview', $payload)
            ->assertForbidden()->assertJsonPath('meta.code', 'SLUG_PERMISSION_DENIED');
        $token = $this->token();
        foreach (['resource', 'category', 'tag', 'technology'] as $type) {
            $this->withToken($token)->postJson('/api/admin/slugs/preview', [...$payload, 'model_type' => $type])->assertForbidden();
        }
        foreach (['unknown', Post::class, 'posts', ''] as $type) {
            $this->withToken($token)->postJson('/api/admin/slugs/preview', [...$payload, 'model_type' => $type])->assertUnprocessable();
        }
        $this->withToken($token)->postJson('/api/admin/slugs/preview', [...$payload, 'title' => ' '])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/admin/slugs/preview', [...$payload, 'title' => str_repeat('a', 256)])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/admin/slugs/preview', [...$payload, 'model_id' => 0])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/admin/slugs/preview', [...$payload, 'model_id' => 999999])->assertNotFound();
        $resource = Resource::factory()->create();
        $createOnly = $this->token(['resources.create']);
        $this->withToken($createOnly)->postJson('/api/admin/slugs/preview', [
            'title' => 'Title', 'model_type' => 'resource', 'model_id' => $resource->id,
        ])->assertOk()->assertJsonPath('data.slug', 'title');
        $updateOnly = $this->token(['resources.update']);
        $this->withToken($updateOnly)->postJson('/api/admin/slugs/preview', [
            'title' => 'Title', 'model_type' => 'resource', 'model_id' => $resource->id,
        ])->assertOk();
        $this->withToken($updateOnly)->postJson('/api/admin/slugs/preview', [
            'title' => 'Title', 'model_type' => 'resource',
        ])->assertForbidden();
    }

    /** Input: role có permission create của Post. Output: preview slug được phép như user thật. */
    public function test_shared_preview_accepts_create_permission_from_role(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('editor');
        $this->flushPermissionCache();
        $token = $user->createToken('post-role-test', ['admin'])->plainTextToken;

        $this->withToken($token)->postJson('/api/admin/slugs/preview', [
            'title' => 'Role title', 'model_type' => 'post',
        ])->assertOk()->assertJsonPath('data.slug', 'role-title');
    }

    /** Input: permission posts.create riêng, không có posts.manage. Output: Post slug vẫn được preview. */
    public function test_shared_preview_accepts_post_create_permission_alias(): void
    {
        Permission::findOrCreate('posts.create', 'admin');
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('posts.create');
        $this->flushPermissionCache();
        $token = $user->createToken('post-create-test', ['admin'])->plainTextToken;

        $this->withToken($token)->postJson('/api/admin/slugs/preview', [
            'title' => 'Create permission', 'model_type' => 'post',
        ])->assertOk()->assertJsonPath('data.slug', 'create-permission');
    }

    /** Input: title tiếng Việt trùng. Output: preview không ghi DB, save chọn hậu tố mới nhất. */
    public function test_preview_does_not_write_and_save_rechecks_collisions(): void
    {
        $token = $this->token();
        Post::factory()->create(['title' => 'Đường Đến Thành Công']);
        $before = Slug::query()->count();
        $this->withToken($token)->postJson('/api/admin/slugs/preview', ['model_type' => 'post', 'title' => 'Đường Đến Thành Công'])
            ->assertOk()->assertJsonPath('data.slug', 'duong-den-thanh-cong-1');
        $this->assertDatabaseCount('slugable', $before);
        Post::factory()->create(['title' => 'Đường Đến Thành Công']);
        $this->withToken($token)->postJson('/api/admin/posts', ['title' => 'Đường Đến Thành Công'])
            ->assertCreated()->assertJsonPath('data.slug', 'duong-den-thanh-cong-2');
    }

    /** Input: Post edit và slug lịch sử. Output: không tự trùng; không chiếm slug model khác. */
    public function test_edit_excludes_self_and_keeps_history(): void
    {
        $token = $this->token();
        $post = Post::factory()->create(['title' => 'Tên Cũ']);
        $this->withToken($token)->postJson('/api/admin/slugs/preview', ['model_type' => 'post', 'title' => 'Tên Cũ', 'model_id' => $post->id])
            ->assertOk()->assertJsonPath('data.slug', 'ten-cu');
        $this->withToken($token)->putJson('/api/admin/posts/'.$post->id, ['title' => 'Tên Mới'])
            ->assertOk()->assertJsonPath('data.slug', 'ten-moi');
        $this->withToken($token)->postJson('/api/admin/slugs/preview', ['model_type' => 'post', 'title' => 'Tên Cũ'])
            ->assertOk()->assertJsonPath('data.slug', 'ten-cu-1');
        $this->assertDatabaseHas('slugable', ['slug' => 'ten-cu', 'is_primary' => false]);
        $this->assertSame(1, $post->slugs()->where('is_primary', true)->count());
    }

    /** Input: title dài/rỗng và type/locale khác. Output: giới hạn 250 và đúng phạm vi. */
    public function test_service_handles_length_fallback_type_and_locale(): void
    {
        $service = app(SlugService::class);
        Resource::factory()->create(['title' => 'Same title']);
        $this->assertSame('same-title', $service->previewForModel(new Post, 'Same title'));
        $post = Post::factory()->create(['title' => str_repeat('a', 255)]);
        $this->assertSame(250, strlen($post->primarySlug()->slug));
        $next = $service->previewForModel(new Post, str_repeat('a', 255));
        $this->assertSame(250, strlen($next));
        $this->assertStringEndsWith('-1', $next);
        $this->assertSame(str_repeat('a', 250), $service->previewForModel(new Post, str_repeat('a', 255), 'fr'));
        $this->assertSame('item', $service->previewForModel(new Post, '!!!'));
    }

    /** Input: request không quyền/sai title/ID. Output: 401/403/422/404. */
    public function test_preview_authorization_and_validation(): void
    {
        $this->postJson('/api/admin/slugs/preview', ['model_type' => 'post', 'title' => 'A'])->assertUnauthorized();
        $this->withToken($this->token([]))->postJson('/api/admin/slugs/preview', ['model_type' => 'post', 'title' => 'A'])->assertForbidden();
        $token = $this->token();
        $this->withToken($token)->postJson('/api/admin/slugs/preview', ['model_type' => 'post', 'title' => '   '])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/admin/slugs/preview', ['model_type' => 'post', 'title' => str_repeat('a', 256)])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/admin/slugs/preview', ['model_type' => 'post', 'title' => 'A', 'model_id' => 999999])->assertNotFound();
    }

    /** Input: SEO riêng và title/excerpt. Output: lưu/tải độc lập, không nhận score client. */
    public function test_seo_round_trip_and_partial_update(): void
    {
        $token = $this->token();
        $fields = [
            'title' => 'Tiêu đề gốc', 'excerpt' => 'Tóm tắt', 'content' => '<p>Nội dung</p>',
            'focus_keyword' => 'nội dung', 'seo_title' => 'Tiêu đề SEO', 'seo_description' => 'Mô tả SEO',
            'canonical_url' => 'https://example.test/blog/canonical', 'robots_index' => false, 'robots_follow' => true,
            'og_title' => 'Tiêu đề chia sẻ', 'og_description' => 'Mô tả chia sẻ',
        ];
        $created = $this->withToken($token)->postJson('/api/admin/posts', [...$fields, 'seo_score' => 100])->assertCreated();
        $id = $created->json('data.id');
        $this->assertDatabaseHas('seo_metadata', [
            'seoable_type' => 'post',
            'seoable_id' => $id,
            'seo_title' => 'Tiêu đề SEO',
            'canonical_url' => 'https://example.test/blog/canonical',
        ]);
        $shown = $this->withToken($token)->getJson('/api/admin/posts/'.$id)->assertOk();
        foreach ($fields as $key => $value) {
            $shown->assertJsonPath('data.'.$key, $value);
        }
        $shown->assertJsonMissingPath('data.seo_score');
        $this->withToken($token)->putJson('/api/admin/posts/'.$id, ['seo_title' => 'SEO mới'])
            ->assertOk()->assertJsonPath('data.title', 'Tiêu đề gốc')->assertJsonPath('data.excerpt', 'Tóm tắt')
            ->assertJsonPath('data.seo_title', 'SEO mới')->assertJsonPath('data.robots_index', false);
        $this->withToken($token)->putJson('/api/admin/posts/'.$id, ['seo_title' => null, 'canonical_url' => null])
            ->assertOk()->assertJsonPath('data.seo_title', null)->assertJsonPath('data.canonical_url', null);
        $this->withToken($token)->putJson('/api/admin/posts/'.$id, ['canonical_url' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->withToken($token)->putJson('/api/admin/posts/'.$id, ['title' => ''])->assertUnprocessable();
    }

    /** Input: Post chưa có metadata và Resource dùng cùng service. Output: fallback và polymorphic relation đúng. */
    public function test_seo_metadata_fallback_and_reuse_across_models(): void
    {
        $post = Post::factory()->create(['title' => 'Fallback title', 'excerpt' => 'Fallback excerpt']);
        $resolved = app(SeoMetadataService::class)->resolve($post);
        $this->assertSame('Fallback title', $resolved['seo_title']);
        $this->assertSame('Fallback excerpt', $resolved['seo_description']);
        $resource = Resource::factory()->create(['title' => 'Resource title']);
        app(SeoMetadataService::class)->sync($resource, [
            'seo_title' => 'Resource SEO',
            'seo_description' => 'Resource description',
        ]);
        $this->assertDatabaseHas('seo_metadata', [
            'seoable_type' => 'resource',
            'seoable_id' => $resource->id,
            'seo_title' => 'Resource SEO',
        ]);
        $this->assertSame('Resource SEO', app(SeoMetadataService::class)->resolve($resource)['seo_title']);
        $privateImage = MediaAsset::factory()->image()->create(['visibility' => 'private']);
        $this->expectException(ValidationException::class);
        app(SeoMetadataService::class)->sync($post, ['og_image_id' => $privateImage->id]);
    }

    /** Input: collision mô phỏng sau demote. Output: retry hữu hạn, rollback giữ đúng một primary. */
    public function test_unique_collision_retries_and_rolls_back_demotion(): void
    {
        $post = Post::factory()->create(['title' => 'Old title']);
        $attempts = 0;
        Slug::creating(function () use (&$attempts): void {
            $attempts++;
            if ($attempts === 1) {
                throw new UniqueConstraintViolationException('isolated_test', 'insert into slugable', [], new \PDOException('Simulated collision'));
            }
        });
        try {
            $result = app(SlugService::class)->syncForModel($post, 'New title');
            $this->assertSame('new-title', $result->slug);
            $this->assertSame(2, $attempts);
            $this->assertSame(1, $post->slugs()->where('is_primary', true)->count());
        } finally {
            Slug::flushEventListeners();
        }
    }

    /** Input: collision liên tục. Output: dừng sau 5 lần và giữ primary cũ. */
    public function test_retry_exhaustion_preserves_previous_primary(): void
    {
        $post = Post::factory()->create(['title' => 'Old title']);
        $attempts = 0;
        Slug::creating(function () use (&$attempts): void {
            $attempts++;
            throw new UniqueConstraintViolationException('isolated_test', 'insert into slugable', [], new \PDOException('Simulated collision'));
        });
        try {
            app(SlugService::class)->syncForModel($post, 'New title');
            $this->fail('Expected unique collision after bounded retries.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(5, $attempts);
            $this->assertSame('old-title', $post->primarySlug()->slug);
            $this->assertSame(1, $post->slugs()->count());
        } finally {
            Slug::flushEventListeners();
        }
    }

    /** Input: gallery public có thứ tự và asset private. Output: giữ order/clear, từ chối private. */
    public function test_post_gallery_order_clear_and_public_validation(): void
    {
        $token = $this->token(['posts.manage', 'media.attach']);
        $first = MediaAsset::factory()->image()->create();
        $second = MediaAsset::factory()->image()->create();
        $private = MediaAsset::factory()->image()->create(['visibility' => 'private']);
        $created = $this->withToken($token)->postJson('/api/admin/posts', [
            'title' => 'Gallery',
            'media' => ['thumbnail_id' => $first->id, 'gallery_image_ids' => [$second->id, $first->id]],
        ])->assertCreated()->assertJsonPath('data.media.gallery_images.0.id', $second->id)
            ->assertJsonPath('data.media.gallery_images.1.id', $first->id);
        $id = $created->json('data.id');
        $this->withToken($token)->putJson('/api/admin/posts/'.$id, [
            'media' => ['gallery_image_ids' => [$first->id, $second->id]],
        ])->assertOk()->assertJsonPath('data.media.gallery_images.0.id', $first->id);
        $this->withToken($token)->putJson('/api/admin/posts/'.$id, [
            'media' => ['thumbnail_id' => $private->id],
        ])->assertUnprocessable();
        $this->withToken($token)->getJson('/api/admin/posts/'.$id)
            ->assertOk()->assertJsonPath('data.media.thumbnail.id', $first->id);
        $this->withToken($token)->putJson('/api/admin/posts/'.$id, [
            'media' => ['thumbnail_id' => null, 'gallery_image_ids' => []],
        ])->assertOk()->assertJsonPath('data.media.thumbnail', null)->assertJsonPath('data.media.gallery_images', []);
        $this->assertDatabaseHas('media_assets', ['id' => $first->id]);
    }
}
