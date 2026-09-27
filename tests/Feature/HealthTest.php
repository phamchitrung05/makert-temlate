<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử liveness endpoint của API
 * =====================================================================
 *
 * Test đảm bảo monitoring và frontend có thể kiểm tra API mà không cần
 * authentication hoặc dữ liệu nghiệp vụ.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - test_api_health_returns_ok_json(): kiểm tra status và JSON contract
 * =====================================================================
 */
class HealthTest extends TestCase
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Xác nhận API health trả response thành công
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 200 và BaseResponse envelope gồm data.status, data.service, data.timestamp
     *
     * SIDE EFFECT:
     * - Không có
     * =====================================================================
     */
    public function test_api_health_returns_ok_json(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.service', 'api')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'status',
                    'service',
                    'timestamp',
                ],
                'errors',
                'meta',
            ]);
    }
}
