<?php

namespace Tests\Feature;

use App\Enums\ResourceStatus;
use App\Enums\ResourceType;
use App\Enums\ResourceVisibility;
use App\Models\Resource;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử phân quyền trên admin resource API
 * =====================================================================
 *
 * Mỗi route admin resource gắn đúng một permission của Spatie. Test xác nhận
 * admin thiếu permission bị chặn 403, còn admin có permission thì đi qua
 * được — kể cả khi token hợp lệ và role super-admin không liên quan.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp(): migrate SQLite in-memory và seed permission
 * - createAdminWithPermissions(): tạo admin kèm permission rồi trả token
 * - test_viewing_resources_requires_view_permission(): cần resources.view
 * - test_creating_resource_requires_create_permission(): cần resources.create
 * - test_publishing_resource_requires_publish_permission(): cần resources.publish
 * - test_deleting_resource_requires_delete_permission(): cần resources.delete
 * - test_customer_token_cannot_access_admin_resources(): chặn cross-guard
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTTP request với Bearer token của admin hoặc customer
 * - OUTPUT: assert HTTP 200 khi đủ quyền, 403 khi thiếu quyền
 * =====================================================================
 */
class ResourcePermissionTest extends TestCase
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
     * CHỨC NĂNG: Tạo admin với đúng danh sách permission rồi trả token
     * =====================================================================
     *
     * INPUT:
     * - $permissions: danh sách tên permission cần cấp
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
        $this->resetAdminGuard();

        return $admin->createToken('admin-test', ['admin'])->plainTextToken;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xoá user đã resolve khỏi guard admin
     * =====================================================================
     *
     * Guard `admin` dùng driver sanctum nên giữ user của request trước đó.
     * Trong một test có nhiều lần gọi API với admin khác nhau, không reset
     * guard thì request sau vẫn thấy user cũ và từ chối nhầm.
     *
     * SIDE EFFECT:
     * - Gọi Auth::guard('admin')->forgetUser(); không đụng dữ liệu
     */
    private function resetAdminGuard(): void
    {
        \Illuminate\Support\Facades\Auth::guard('admin')->forgetUser();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Payload tạo resource hợp lệ dùng chung cho các test
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, mixed>: dữ liệu tạo resource tối thiểu
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => ResourceType::Template->value,
            'title' => 'Tài nguyên kiểm thử',
            'status' => ResourceStatus::Draft->value,
            'visibility' => ResourceVisibility::Public->value,
        ], $overrides);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra xem danh sách yêu cầu permission resources.view
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 403 khi thiếu quyền, HTTP 200 khi có đủ quyền
     */
    public function test_viewing_resources_requires_view_permission(): void
    {
        Resource::factory()->published()->create();

        $this->withToken($this->createAdminWithPermissions([]))
            ->getJson('/api/admin/resources')
            ->assertForbidden();

        $this->withToken($this->createAdminWithPermissions(['resources.view']))
            ->getJson('/api/admin/resources')
            ->assertOk();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra tạo resource yêu cầu permission resources.create
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 403 khi chỉ có resources.view, HTTP 201 khi có resources.create
     */
    public function test_creating_resource_requires_create_permission(): void
    {
        $this->withToken($this->createAdminWithPermissions(['resources.view']))
            ->postJson('/api/admin/resources', $this->validPayload())
            ->assertForbidden();

        $this->withToken($this->createAdminWithPermissions(['resources.create']))
            ->postJson('/api/admin/resources', $this->validPayload())
            ->assertCreated();

        $this->assertDatabaseCount('resources', 1);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra publish yêu cầu permission resources.publish
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 403 khi thiếu quyền và resource vẫn giữ trạng thái draft
     */
    public function test_publishing_resource_requires_publish_permission(): void
    {
        $resource = Resource::factory()->draft()->create();

        $this->withToken($this->createAdminWithPermissions(['resources.update']))
            ->postJson("/api/admin/resources/{$resource->id}/publish")
            ->assertForbidden();

        $this->assertSame(ResourceStatus::Draft, $resource->fresh()->status);

        $this->withToken($this->createAdminWithPermissions(['resources.publish']))
            ->postJson("/api/admin/resources/{$resource->id}/publish")
            ->assertOk();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra xoá resource yêu cầu permission resources.delete
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 403 khi thiếu quyền, HTTP 204 khi có đủ quyền
     */
    public function test_deleting_resource_requires_delete_permission(): void
    {
        $resource = Resource::factory()->create();

        $this->withToken($this->createAdminWithPermissions(['resources.update']))
            ->deleteJson("/api/admin/resources/{$resource->id}")
            ->assertForbidden();

        $this->assertNull($resource->fresh()->deleted_at);

        $this->withToken($this->createAdminWithPermissions(['resources.delete']))
            ->deleteJson("/api/admin/resources/{$resource->id}")
            ->assertNoContent();

        $this->assertNotNull($resource->fresh()->deleted_at);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra Bearer token của customer không vào được admin API
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 403 vì token customer thiếu ability admin
     */
    public function test_customer_token_cannot_access_admin_resources(): void
    {
        $customer = \App\Models\Customer::factory()->create(['status' => 'active']);
        $token = $customer->createToken('customer-test', ['customer'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/resources')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }
}
