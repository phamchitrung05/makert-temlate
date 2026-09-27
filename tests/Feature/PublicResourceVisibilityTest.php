<?php

namespace Tests\Feature;

use App\Enums\ResourceStatus;
use App\Enums\ResourceVisibility;
use App\Models\Resource;
use App\Repositories\Contracts\ResourceRepositoryInterface;
use App\Repositories\Criteria\PubliclyVisibleResourceCriteria;
use Database\Seeders\RolePermissionSeeder;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử resource lọc cho public catalog
 * =====================================================================
 *
 * Public catalog phải chỉ trả về resource đã published và công khai. Bộ lọc
 * nằm ở PubliclyVisibleResourceCriteria nên áp dụng cùng một criteria vào mọi
 * truy vấn public, bất kể controller có quên kiểm tra hay không.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp(): migrate SQLite in-memory và seed permission
 * - test_public_criteria_only_returns_published_and_public_resources(): lọc đúng
 * - test_public_criteria_excludes_draft_and_non_public_resources(): loại trừ rác
 * - test_suspended_resource_is_excluded(): loại trừ tạm dừng
 * - test_published_criteria_keeps_non_public_resources(): criteria published
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : resource mẫu ở nhiều trạng thái
 * - OUTPUT: danh sách id trả về sau khi áp criteria
 * =====================================================================
 */
class PublicResourceVisibilityTest extends TestCase
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
     * CHỨC NĂNG: Chạy truy vấn repository với criteria được chỉ định
     * =====================================================================
     *
     * INPUT:
     * - $criteria: instance CriteriaInterface hoặc null để không lọc
     *
     * OUTPUT:
     * - list<int>: danh sách id resource trả về
     */
    private function resourceIdsWithCriteria(?object $criteria = null): array
    {
        $repository = app(ResourceRepositoryInterface::class);

        if ($criteria !== null) {
            $repository->pushCriteria($criteria);
        }

        return $repository->all()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra criteria public chỉ trả resource hợp lệ
     * =====================================================================
     *
     * OUTPUT:
     * - Danh sách chỉ chứa resource published và visibility public
     */
    public function test_public_criteria_only_returns_published_and_public_resources(): void
    {
        $visible = Resource::factory()->published()->create();

        $this->assertSame([$visible->id], $this->resourceIdsWithCriteria(
            new PubliclyVisibleResourceCriteria,
        ));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra draft và resource không công khai bị loại
     * =====================================================================
     *
     * OUTPUT:
     * - Không resource nào trong danh sách là draft hoặc visibility khác public
     */
    public function test_public_criteria_excludes_draft_and_non_public_resources(): void
    {
        Resource::factory()->draft()->create();
        Resource::factory()->create([
            'status' => ResourceStatus::Published,
            'visibility' => ResourceVisibility::Members,
        ]);
        Resource::factory()->create([
            'status' => ResourceStatus::Published,
            'visibility' => ResourceVisibility::Private,
        ]);

        $this->assertSame([], $this->resourceIdsWithCriteria(
            new PubliclyVisibleResourceCriteria,
        ));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra resource bị tạm dừng không lọt ra public
     * =====================================================================
     *
     * OUTPUT:
     * - Danh sách rỗng vì status suspended không phải published
     */
    public function test_suspended_resource_is_excluded(): void
    {
        Resource::factory()->create([
            'status' => ResourceStatus::Suspended,
            'visibility' => ResourceVisibility::Public,
        ]);

        $this->assertSame([], $this->resourceIdsWithCriteria(
            new PubliclyVisibleResourceCriteria,
        ));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra criteria published vẫn giữ resource thành viên
     * =====================================================================
     *
     * OUTPUT:
     * - Resource members-only vẫn xuất hiện vì criteria chỉ lọc theo status
     */
    public function test_published_criteria_keeps_non_public_resources(): void
    {
        $membersOnly = Resource::factory()->create([
            'status' => ResourceStatus::Published,
            'visibility' => ResourceVisibility::Members,
        ]);

        $this->assertSame([$membersOnly->id], $this->resourceIdsWithCriteria(
            new \App\Repositories\Criteria\PublishedResourceCriteria,
        ));
    }
}
