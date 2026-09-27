<?php

namespace Tests\Feature;

use App\Enums\ResourceStatus;
use App\Enums\ResourceType;
use App\Enums\ResourceVisibility;
use App\Models\Category;
use App\Models\Resource;
use App\Models\Tag;
use App\Models\Technology;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử CRUD resource qua admin API
 * =====================================================================
 *
 * Test bao phủ chuỗi tạo – đọc – sửa – publish – archive của resource, đồng
 * thời kiểm tra taxonomy được đồng bộ và slug được sinh tự động. Mọi request
 * đều đi qua Bearer token thật và đúng permission của Spatie.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - actingAsAdminWithPermissions(): tạo admin và gán permission rồi trả token
 * - test_admin_can_create_resource_with_taxonomy(): tạo kèm category và tag
 * - test_admin_can_list_resources_with_pagination(): phân trang và lọc server
 * - test_admin_can_update_resource_title_and_regenerate_slug(): đổi title
 * - test_admin_can_publish_draft_resource(): publish từ draft
 * - test_publish_rejects_resource_with_invalid_source_status(): từ chối sai
 * - test_admin_can_archive_published_resource(): archive resource đã publish
 * - test_creating_resource_rejects_invalid_payload(): validate 422
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTTP request giả lập tới /api/admin/resources
 * - OUTPUT: assert trên HTTP status và envelope BaseResponse
 * =====================================================================
 */
class ResourceCrudTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị dữ liệu nền cho toàn bộ test trong file
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Migrate SQLite in-memory và seed permission; mỗi test được rollback
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn schema sau mỗi test
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Rollback migration và giải phóng connection
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();

        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo admin, gán permission rồi trả về Bearer token
     * =====================================================================
     *
     * INPUT:
     * - $permissions: danh sách permission cần cấp cho admin
     *
     * OUTPUT:
     * - string: plain text token để dùng cho các request sau
     *
     * SIDE EFFECT:
     * - INSERT users, model_has_roles và personal_access_tokens
     */
    private function actingAsAdminWithPermissions(array $permissions): string
    {
        $admin = User::factory()->create(['status' => 'active']);

        // givePermissionTo nhận variadic nên chỉ truyền tên permission;
        // guard lấy từ User::$guard_name là admin.
        foreach ($permissions as $permission) {
            $admin->givePermissionTo($permission);
        }

        return $admin->createToken('admin-test', ['admin'])->plainTextToken;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra tạo resource kèm taxonomy sinh slug tự động
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 201, resource lưu đúng và slug primary được tạo
     */
    public function test_admin_can_create_resource_with_taxonomy(): void
    {
        $token = $this->actingAsAdminWithPermissions(['resources.create']);
        $category = Category::factory()->create();
        $tag = Tag::factory()->create();
        $technology = Technology::factory()->create();

        $response = $this->withToken($token)->postJson('/api/admin/resources', [
            'type' => ResourceType::Template->value,
            'title' => 'Vue Admin Dashboard',
            'status' => ResourceStatus::Draft->value,
            'visibility' => ResourceVisibility::Public->value,
            'category_ids' => [$category->id],
            'tag_ids' => [$tag->id],
            'technology_ids' => [$technology->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Vue Admin Dashboard')
            ->assertJsonPath('data.status', ResourceStatus::Draft->value)
            ->assertJsonPath('data.slug', 'vue-admin-dashboard');

        $resourceId = $response->json('data.id');

        $this->assertDatabaseHas('resources', ['id' => $resourceId, 'title' => 'Vue Admin Dashboard']);
        $this->assertDatabaseHas('slugable', [
            'sluggable_type' => (new Resource)->getMorphClass(),
            'sluggable_id' => $resourceId,
            'slug' => 'vue-admin-dashboard',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('categorizables', [
            'category_id' => $category->id,
            'categorizable_id' => $resourceId,
        ]);
        $this->assertDatabaseHas('taggables', ['tag_id' => $tag->id, 'taggable_id' => $resourceId]);
        $this->assertDatabaseHas('resource_technology', [
            'resource_id' => $resourceId,
            'technology_id' => $technology->id,
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra danh sách có phân trang và lọc phía server
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 200 với meta.pagination đúng tổng số bản ghi khớp bộ lọc
     */
    public function test_admin_can_list_resources_with_pagination_and_filter(): void
    {
        $token = $this->actingAsAdminWithPermissions(['resources.view']);

        Resource::factory()->published()->count(3)->create();
        Resource::factory()->draft()->count(2)->create();

        $response = $this->withToken($token)
            ->getJson('/api/admin/resources?status=published&per_page=2');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.per_page', 2)
            ->assertJsonCount(2, 'data');

        foreach ($response->json('data') as $item) {
            $this->assertSame(ResourceStatus::Published->value, $item['status']);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra đổi title sinh slug mới và giữ slug cũ
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 200, slug primary đổi theo title mới, slug cũ còn tồn tại
     */
    public function test_admin_can_update_resource_title_and_regenerate_slug(): void
    {
        $token = $this->actingAsAdminWithPermissions(['resources.create', 'resources.update']);

        // Trait HasSlug tự sinh slug 'ten-cu' khi factory tạo resource.
        $resource = Resource::factory()->create(['title' => 'Tên Cũ']);

        $this->assertSame('ten-cu', $resource->primarySlug()->slug);

        $this->withToken($token)
            ->putJson("/api/admin/resources/{$resource->id}", ['title' => 'Tên Mới'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'ten-moi');

        $this->assertDatabaseHas('slugable', [
            'sluggable_id' => $resource->id,
            'slug' => 'ten-cu',
            'is_primary' => false,
        ]);
        $this->assertDatabaseHas('slugable', [
            'sluggable_id' => $resource->id,
            'slug' => 'ten-moi',
            'is_primary' => true,
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra publish resource đang ở trạng thái draft
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 200, status chuyển published và published_at được ghi
     */
    public function test_admin_can_publish_draft_resource(): void
    {
        $token = $this->actingAsAdminWithPermissions(['resources.publish']);
        $resource = Resource::factory()->draft()->create();

        $this->withToken($token)
            ->postJson("/api/admin/resources/{$resource->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', ResourceStatus::Published->value);

        $this->assertNotNull($resource->fresh()->published_at);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra từ chối publish khi trạng thái nguồn không hợp lệ
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 422 vì resource đã published không thể publish lại
     */
    public function test_publish_rejects_resource_with_invalid_source_status(): void
    {
        $token = $this->actingAsAdminWithPermissions(['resources.publish']);
        $resource = Resource::factory()->published()->create();

        $this->withToken($token)
            ->postJson("/api/admin/resources/{$resource->id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra archive resource đã xuất bản
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 200 và status chuyển sang archived
     */
    public function test_admin_can_archive_published_resource(): void
    {
        $token = $this->actingAsAdminWithPermissions(['resources.archive']);
        $resource = Resource::factory()->published()->create();

        $this->withToken($token)
            ->postJson("/api/admin/resources/{$resource->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', ResourceStatus::Archived->value);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra validator từ chối payload không hợp lệ
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 422 với errors theo field, resource không được tạo
     */
    public function test_creating_resource_rejects_invalid_payload(): void
    {
        $token = $this->actingAsAdminWithPermissions(['resources.create']);

        $this->withToken($token)
            ->postJson('/api/admin/resources', [
                'type' => 'khong_ton_tai',
                'title' => '',
                'status' => 'khong_ton_tai',
                'visibility' => 'khong_ton_tai',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'data', 'errors', 'meta'])
            ->assertJsonValidationErrors(['type', 'title', 'status', 'visibility']);

        $this->assertDatabaseCount('resources', 0);
    }
}
