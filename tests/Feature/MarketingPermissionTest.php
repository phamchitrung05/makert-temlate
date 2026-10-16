<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử permission phạm vi module Marketing.
 * =====================================================================
 * `marketing.view/manage` là quyền cấp module; các permission resource
 * `social_posts.*`/`social_accounts.*` vẫn được chấp nhận để giữ cấp quyền
 * tối thiểu và tương thích role cũ.
 * =====================================================================
 */
final class MarketingPermissionTest extends TestCase
{
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Module permission xuất hiện trong catalog và có thể đọc Marketing. */
    public function test_marketing_module_permission_is_catalogued_and_reads_products(): void
    {
        $actor = $this->actor(['marketing.view', 'users.view']);
        $token = $actor->createToken('marketing-view', ['admin'])->plainTextToken;

        $catalog = $this->withToken($token)->getJson('/api/admin/permissions/catalog')->assertOk();
        $marketing = collect($catalog->json('data.groups'))->firstWhere('name', 'marketing');

        $this->assertSame(['marketing.manage', 'marketing.view'], collect($marketing['permissions'])
            ->pluck('name')->sort()->values()->all());
        $this->withToken($token)->getJson('/api/admin/marketing/products')->assertOk();
    }

    /** Module manage có thể tạo Marketing resource qua route module guard. */
    public function test_marketing_manage_can_create_product(): void
    {
        $actor = $this->actor(['marketing.manage']);
        $token = $actor->createToken('marketing-manage', ['admin'])->plainTextToken;

        $this->withToken($token)->postJson('/api/admin/marketing/products', [
            'name' => 'Product từ quyền Marketing',
            'status' => 'draft',
        ])->assertCreated()->assertJsonPath('data.name', 'Product từ quyền Marketing');
    }

    /** Role API nhận và trả lại các permission Marketing từ catalog dùng chung. */
    public function test_role_and_permission_apis_expose_marketing_permissions(): void
    {
        $actor = $this->actor(['marketing.view', 'marketing.manage', 'roles.create']);
        $token = $actor->createToken('marketing-role', ['admin'])->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/permissions?group=marketing')
            ->assertOk()
            ->assertJsonPath('data.items.0.group', 'marketing');

        $permissionIds = Permission::query()
            ->whereIn('name', ['marketing.view', 'marketing.manage'])
            ->pluck('id')
            ->all();

        $created = $this->withToken($token)->postJson('/api/admin/roles', [
            'name' => 'marketing-api-manager',
            'permission_ids' => $permissionIds,
        ])->assertCreated();

        $this->assertSame(
            ['marketing.manage', 'marketing.view'],
            collect($created->json('data.permissions'))->pluck('name')->sort()->values()->all(),
        );
    }

    /** Input: permission names; Output: token actor đã cấp quyền, không side effect ngoài DB test. */
    private function actor(array $permissions): User
    {
        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo($permissions);
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $actor;
    }
}
