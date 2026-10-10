<?php

namespace Tests\Feature;

use App\Enums\TaxonomyStatus;
use App\Enums\TechnologyType;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Tag;
use App\Models\Technology;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử CRUD taxonomy qua admin API
 * =====================================================================
 *
 * Test bao phủ cả ba loại taxonomy dùng chung TaxonomyController nhưng mỗi
 * loại có FormRequest riêng. Kiểm tra việc validate chạy đúng ở cổng HTTP
 * (422 với errors theo field) và dữ liệu ghi đúng vào database.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp(): migrate SQLite in-memory và seed permission
 * - createAdminWithPermissions(): tạo admin kèm permission rồi trả token
 * - test_admin_can_create_category_and_generate_slug(): tạo danh mục
 * - test_creating_category_rejects_duplicate_name(): trùng tên bị 422
 * - test_creating_category_rejects_invalid_status(): status sai bị 422
 * - test_admin_can_create_tag_and_technology(): tạo tag và công nghệ
 * - test_creating_technology_requires_type(): thiếu type bị 422
 * - test_admin_can_list_taxonomy_with_pagination(): danh sách có phân trang
 * - test_admin_can_show_and_update_category(): resolve route model và cập nhật
 * - test_taxonomy_delete_uses_model_delete_semantics(): soft/hard delete đúng model
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTTP request giả lập tới /api/admin/categories|tag|technologies
 * - OUTPUT: assert trên HTTP status, envelope BaseResponse và dữ liệu DB
 * =====================================================================
 */
class TaxonomyCrudTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị database cô lập và permission cho test
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Migrate SQLite in-memory và seed permission
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
     * CHỨC NĂNG: Tạo admin với permission rồi trả Bearer token
     * =====================================================================
     *
     * INPUT:
     * - $permissions: danh sách permission cần cấp
     *
     * OUTPUT:
     * - string: plain text Sanctum token
     *
     * SIDE EFFECT:
     * - INSERT users, model_has_roles và personal_access_tokens
     */
    private function createAdminWithPermissions(array $permissions): string
    {
        $admin = User::factory()->create(['status' => 'active']);

        foreach ($permissions as $permission) {
            $admin->givePermissionTo($permission);
        }

        $this->flushPermissionCache();
        \Illuminate\Support\Facades\Auth::guard('admin')->forgetUser();

        return $admin->createToken('admin-test', ['admin'])->plainTextToken;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra tạo danh mục và slug tự sinh
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 201, danh mục lưu đúng và có slug primary từ trait HasSlug
     */
    public function test_admin_can_create_category_and_generate_slug(): void
    {
        $token = $this->createAdminWithPermissions(['taxonomy.manage']);

        $this->withToken($token)
            ->postJson('/api/admin/categories', [
                'name' => 'Giao diện quản trị',
                'status' => TaxonomyStatus::Active->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Giao diện quản trị')
            ->assertJsonPath('data.status', TaxonomyStatus::Active->value);

        $this->assertDatabaseHas('categories', ['name' => 'Giao diện quản trị']);
        $this->assertDatabaseHas('slugable', [
            'sluggable_type' => (new Category)->getMorphClass(),
            'slug' => 'giao-dien-quan-tri',
            'is_primary' => true,
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra field cây/menu và chặn vòng lặp Category
     * =====================================================================
     *
     * INPUT:
     * - Category gốc, Category con và request cập nhật parent_id.
     *
     * OUTPUT:
     * - Field show_on_menu/parent_id trả đúng qua resource; self-parent bị 422.
     * =====================================================================
     */
    public function test_category_persists_tree_visibility_and_rejects_self_parent(): void
    {
        $token = $this->createAdminWithPermissions(['resources.view', 'taxonomy.manage']);

        $rootResponse = $this->withToken($token)
            ->postJson('/api/admin/categories', [
                'name' => 'Root Category',
                'description' => 'Root description',
                'show_on_menu' => false,
                'sort_order' => 2,
                'status' => TaxonomyStatus::Active->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.show_on_menu', false)
            ->assertJsonPath('data.parent_id', null);

        $rootId = $rootResponse->json('data.id');

        $this->withToken($token)
            ->postJson('/api/admin/categories', [
                'name' => 'Child Category',
                'parent_id' => $rootId,
                'status' => TaxonomyStatus::Active->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $rootId);

        $this->withToken($token)
            ->putJson("/api/admin/categories/{$rootId}", ['parent_id' => $rootId])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);

        $this->withToken($token)
            ->putJson("/api/admin/categories/{$rootId}", [
                'name' => 'Root Category',
                'status' => TaxonomyStatus::Active->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Root Category');

        $this->assertDatabaseHas('categories', [
            'id' => $rootId,
            'show_on_menu' => 0,
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra Category đồng bộ thumbnail qua Media Library
     * =====================================================================
     *
     * INPUT:
     * - MediaAsset ảnh public và payload media.thumbnail_id.
     *
     * OUTPUT:
     * - HTTP 201, usage field category.thumbnail được ghi đúng model.
     * =====================================================================
     */
    public function test_category_can_attach_public_thumbnail(): void
    {
        $token = $this->createAdminWithPermissions(['resources.view', 'taxonomy.manage', 'media.attach']);
        $asset = MediaAsset::factory()->image()->create();

        $this->withToken($token)
            ->postJson('/api/admin/categories', [
                'name' => 'Category có ảnh',
                'status' => TaxonomyStatus::Active->value,
                'media' => ['thumbnail_id' => $asset->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.media.thumbnail.id', $asset->id);

        $this->assertDatabaseHas('media_asset_usages', [
            'media_asset_id' => $asset->id,
            'linkable_type' => 'category',
            'field' => 'category.thumbnail',
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra tên danh mục trùng bị FormRequest chặn
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 422 với lỗi theo field name, không tạo bản ghi thứ hai
     */
    public function test_creating_category_rejects_duplicate_name(): void
    {
        $token = $this->createAdminWithPermissions(['taxonomy.manage']);
        Category::factory()->create(['name' => 'Trùng tên']);

        $this->withToken($token)
            ->postJson('/api/admin/categories', [
                'name' => 'Trùng tên',
                'status' => TaxonomyStatus::Active->value,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['name']);

        $this->assertSame(1, Category::query()->where('name', 'Trùng tên')->count());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra status không thuộc enum bị từ chối
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 422 với lỗi theo field status
     */
    public function test_creating_category_rejects_invalid_status(): void
    {
        $token = $this->createAdminWithPermissions(['taxonomy.manage']);

        $this->withToken($token)
            ->postJson('/api/admin/categories', [
                'name' => 'Danh mục lỗi',
                'status' => 'khong_ton_tai',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseCount('categories', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra tạo tag và công nghệ kèm slug tự sinh
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 201 cho cả hai, slug primary được sinh từ tên
     */
    public function test_admin_can_create_tag_and_technology(): void
    {
        $token = $this->createAdminWithPermissions(['taxonomy.manage']);

        $this->withToken($token)
            ->postJson('/api/admin/tags', [
                'name' => 'Dark Mode',
                'status' => TaxonomyStatus::Active->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Dark Mode');

        $this->withToken($token)
            ->postJson('/api/admin/technologies', [
                'name' => 'Vue',
                'type' => TechnologyType::Framework->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', TechnologyType::Framework->value);

        $this->assertDatabaseHas('tags', ['name' => 'Dark Mode']);
        $this->assertDatabaseHas('technologies', [
            'name' => 'Vue',
            'type' => TechnologyType::Framework->value,
        ]);
        $this->assertDatabaseHas('slugable', [
            'sluggable_type' => (new Tag)->getMorphClass(),
            'slug' => 'dark-mode',
        ]);
        $this->assertDatabaseHas('slugable', [
            'sluggable_type' => (new Technology)->getMorphClass(),
            'slug' => 'vue',
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra tạo công nghệ bắt buộc có type
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 422 với lỗi theo field type
     */
    public function test_creating_technology_requires_type(): void
    {
        $token = $this->createAdminWithPermissions(['taxonomy.manage']);

        $this->withToken($token)
            ->postJson('/api/admin/technologies', ['name' => 'Không có type'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);

        $this->assertDatabaseCount('technologies', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra danh sách taxonomy có phân trang
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 200 với meta.pagination đúng tổng số bản ghi
     */
    public function test_admin_can_list_taxonomy_with_pagination(): void
    {
        $token = $this->createAdminWithPermissions(['resources.view', 'taxonomy.manage']);
        Category::factory()->count(4)->create();

        $this->withToken($token)
            ->getJson('/api/admin/categories?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 4)
            ->assertJsonPath('meta.pagination.per_page', 2)
            ->assertJsonCount(2, 'data');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra CRUD base resolve route model và cập nhật category
     * =====================================================================
     *
     * INPUT:
     * - Category đã tồn tại và payload name mới
     *
     * OUTPUT:
     * - HTTP 200 cho show/update, dữ liệu và database dùng name mới
     *
     * SIDE EFFECT:
     * - SELECT và UPDATE category qua BaseCrudController/repository
     * =====================================================================
     */
    public function test_admin_can_show_and_update_category(): void
    {
        $token = $this->createAdminWithPermissions(['resources.view', 'taxonomy.manage']);
        $category = Category::factory()->create(['name' => 'Danh mục cũ']);

        $this->withToken($token)
            ->getJson("/api/admin/categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $category->id)
            ->assertJsonPath('data.name', 'Danh mục cũ');

        $this->withToken($token)
            ->putJson("/api/admin/categories/{$category->id}", [
                'name' => 'Danh mục mới',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Danh mục mới');

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Danh mục mới',
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra soft delete và hard delete theo model taxonomy
     * =====================================================================
     *
     * INPUT:
     * - Category dùng SoftDeletes và Technology không dùng SoftDeletes
     *
     * OUTPUT:
     * - HTTP 204 cho cả hai endpoint delete
     * - Category còn trong database với deleted_at; Technology bị xóa hẳn
     *
     * SIDE EFFECT:
     * - Gọi deleteModel() của BaseCrudController rồi repository delete
     * =====================================================================
     */
    public function test_taxonomy_delete_uses_model_delete_semantics(): void
    {
        $token = $this->createAdminWithPermissions(['taxonomy.manage']);
        $category = Category::factory()->create();
        $technology = Technology::factory()->create();

        $this->withToken($token)
            ->deleteJson("/api/admin/categories/{$category->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('categories', ['id' => $category->id]);

        $this->withToken($token)
            ->deleteJson("/api/admin/technologies/{$technology->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('technologies', ['id' => $technology->id]);
    }
}
