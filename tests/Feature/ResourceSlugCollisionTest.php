<?php

namespace Tests\Feature;

use App\Actions\Resources\CreateResourceAction;
use App\Enums\ResourceStatus;
use App\Enums\ResourceType;
use App\Enums\ResourceVisibility;
use App\Models\Resource;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Prettus\Validator\Exceptions\ValidatorException as PrettusValidatorException;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử xử lý trùng slug
 * =====================================================================
 *
 * Bảng `slugable` có unique index trên (sluggable_type, slug, locale) nên
 * hai resource cùng tên không được dùng chung một slug. SlugService phải tự
 * thêm hậu tố `-2`, `-3`... và luôn giữ slug cũ để phục vụ redirect 301.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp(): migrate SQLite in-memory và seed permission
 * - test_two_resources_with_same_title_get_distinct_slugs(): trùng tên lần 1
 * - test_third_duplicate_gets_suffix_three(): trùng tên lần 3
 * - test_slug_is_unique_per_locale_not_across_models(): cùng tên khác loại
 * - test_creating_resource_rejects_duplicate_code(): code trùng bị từ chối
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dữ liệu tạo resource qua CreateResourceAction
 * - OUTPUT: assert trên cột slug của bảng `slugable`
 * =====================================================================
 */
class ResourceSlugCollisionTest extends TestCase
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
     * CHỨC NĂNG: Tạo resource qua Action với dữ liệu tối thiểu hợp lệ
     * =====================================================================
     *
     * INPUT:
     * - $title: tiêu đề sẽ sinh slug
     * - $actorId: id admin thực hiện
     *
     * OUTPUT:
     * - Resource: resource vừa tạo kèm slug
     */
    private function createResource(string $title, ?int $actorId): Resource
    {
        return app(CreateResourceAction::class)->handle([
            'type' => ResourceType::Template->value,
            'title' => $title,
            'status' => ResourceStatus::Draft->value,
            'visibility' => ResourceVisibility::Public->value,
        ], $actorId);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra hai resource cùng tên nhận slug khác nhau
     * =====================================================================
     *
     * OUTPUT:
     * - Slug thứ nhất giữ nguyên, slug thứ hai có hậu tố `-2`
     */
    public function test_two_resources_with_same_title_get_distinct_slugs(): void
    {
        $actorId = User::factory()->create()->id;

        $first = $this->createResource('Vue Admin', $actorId);
        $second = $this->createResource('Vue Admin', $actorId);

        $this->assertSame('vue-admin', $first->primarySlug()->slug);
        $this->assertSame('vue-admin-2', $second->primarySlug()->slug);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra lần trùng thứ ba nhận hậu tố `-3`
     * =====================================================================
     *
     * OUTPUT:
     * - Slug thứ ba có hậu tố `-3`
     */
    public function test_third_duplicate_gets_suffix_three(): void
    {
        $actorId = User::factory()->create()->id;

        $this->createResource('Landing Page', $actorId);
        $this->createResource('Landing Page', $actorId);
        $third = $this->createResource('Landing Page', $actorId);

        $this->assertSame('landing-page-3', $third->primarySlug()->slug);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra trait tự sinh slug khi model được tạo
     * =====================================================================
     *
     * OUTPUT:
     * - Tạo resource bằng factory không cần Action vẫn có slug primary
     */
    public function test_slug_is_generated_automatically_on_create(): void
    {
        $resource = Resource::factory()->create(['title' => 'Tự Động Sinh Slug']);

        $this->assertSame('tu-dong-sinh-slug', $resource->primarySlug()->slug);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra slug cũ được giữ lại làm primary cho redirect 301
     * =====================================================================
     *
     * OUTPUT:
     * - Mỗi resource có đúng một slug primary, các slug cũ is_primary = false
     */
    public function test_old_slug_is_demoted_when_title_changes(): void
    {
        $actorId = User::factory()->create()->id;
        $resource = $this->createResource('Tên Cũ', $actorId);

        // Trait HasSlug tự sinh lại slug trong hook updated khi tên đổi.
        $resource->update(['title' => 'Tên Mới']);

        $this->assertSame(2, $resource->slugs()->count());
        $this->assertSame(1, $resource->slugs()->where('is_primary', true)->count());
        $this->assertFalse($resource->slugs()->where('slug', 'ten-cu')->first()->is_primary);
        $this->assertSame('ten-moi', $resource->fresh()->primarySlug()->slug);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra code trùng bị validator của repository từ chối
     * =====================================================================
     *
     * OUTPUT:
     * - Prettus ValidatorException với lỗi theo field code
     */
    public function test_creating_resource_rejects_duplicate_code(): void
    {
        $actorId = User::factory()->create()->id;
        $action = app(CreateResourceAction::class);

        $action->handle([
            'type' => ResourceType::Template->value,
            'title' => 'Sản phẩm A',
            'code' => 'san-pham-a',
            'status' => ResourceStatus::Draft->value,
            'visibility' => ResourceVisibility::Public->value,
        ], $actorId);

        $this->expectException(PrettusValidatorException::class);

        $action->handle([
            'type' => ResourceType::Template->value,
            'title' => 'Sản phẩm B',
            'code' => 'san-pham-a',
            'status' => ResourceStatus::Draft->value,
            'visibility' => ResourceVisibility::Public->value,
        ], $actorId);
    }
}
