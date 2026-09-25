<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử nền tảng Laravel Sanctum V1
 * =====================================================================
 *
 * Test này chỉ kiểm tra package, migration, tokenable trait và việc User/
 * Customer có thể tạo personal access token. Login endpoint và token issue
 * flow được triển khai ở task Authentication tiếp theo.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp(): tạo schema SQLite in-memory và chạy migrations
 * - tearDown(): rollback schema và purge connection
 * - test_admin_and_customer_can_create_tokens(): kiểm tra hai tokenable model
 * - test_customer_session_can_exchange_for_token(): kiểm tra session-to-token exchange
 * - test_current_token_can_be_revoked(): kiểm tra revoke qua auth:sanctum
 * =====================================================================
 */
class SanctumFoundationTest extends TestCase
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn bị isolated database cho Sanctum test
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Tạo connection auth_test in-memory và chạy toàn bộ migration
     * =====================================================================
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        Config::set('cache.default', 'array');
        Config::set('permission.cache.store', 'array');
        Config::set('database.connections.auth_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        Config::set('database.default', 'auth_test');

        Artisan::call('migrate', [
            '--database' => 'auth_test',
            '--force' => true,
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn schema sau Sanctum test
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Rollback migrations và giải phóng connection auth_test
     * =====================================================================
     */
    protected function tearDown(): void
    {
        Artisan::call('migrate:reset', [
            '--database' => 'auth_test',
            '--force' => true,
        ]);
        DB::purge('auth_test');

        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra User và Customer tạo được Sanctum token
     * =====================================================================
     *
     * OUTPUT:
     * - Mỗi model tạo được plainTextToken một lần
     * - Database lưu token hash và tokenable type/id tương ứng
     * =====================================================================
     */
    public function test_admin_and_customer_can_create_tokens(): void
    {
        $admin = User::query()->create([
            'name' => 'Sanctum Admin',
            'email' => 'sanctum-admin@example.com',
            'password' => Hash::make('a-secure-password'),
            'status' => 'active',
        ]);
        $customer = Customer::query()->create([
            'name' => 'Sanctum Customer',
            'email' => 'sanctum-customer@example.com',
            'status' => 'active',
        ]);

        $adminToken = $admin->createToken('admin-web', ['admin']);
        $customerToken = $customer->createToken('customer-web', ['customer']);

        $this->assertNotEmpty($adminToken->plainTextToken);
        $this->assertNotEmpty($customerToken->plainTextToken);
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $admin->id,
            'name' => 'admin-web',
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => Customer::class,
            'tokenable_id' => $customer->id,
            'name' => 'customer-web',
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Revoke token hiện tại qua API Sanctum
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 204 và token row không còn trong database
     * =====================================================================
     */
    public function test_current_token_can_be_revoked(): void
    {
        $admin = User::query()->create([
            'name' => 'Revoke Admin',
            'email' => 'revoke-admin@example.com',
            'password' => Hash::make('a-secure-password'),
            'status' => 'active',
        ]);
        $token = $admin->createToken('revoke-test', ['admin']);

        $this->withToken($token->plainTextToken)
            ->postJson('/api/auth/token/revoke')
            ->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đổi customer session thành Sanctum Bearer token
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 201, accessToken plain text và token row thuộc Customer
     * =====================================================================
     */
    public function test_customer_session_can_exchange_for_token(): void
    {
        $customer = Customer::query()->create([
            'name' => 'Token Customer',
            'email' => 'token-customer@example.com',
            'status' => 'active',
        ]);

        $response = $this->actingAs($customer, 'customer')
            ->postJson('/api/account/token', ['device_name' => 'browser']);

        $response->assertCreated()->assertJsonPath('tokenType', 'Bearer');
        $this->assertNotEmpty($response->json('accessToken'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => Customer::class,
            'tokenable_id' => $customer->id,
            'name' => 'browser',
        ]);
    }
}
