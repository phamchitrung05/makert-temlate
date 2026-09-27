<?php

namespace Tests\Unit;

use App\Exceptions\MediaSecurityException;
use App\Http\Responses\BaseResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Unit\Fixtures\ResourceStub;
use Tests\Unit\Fixtures\TestResourceItem;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử API response envelope dùng chung
 * =====================================================================
 *
 * Test xác nhận các helper response giữ nguyên contract success, validation,
 * pagination và no-content trước khi các module Đợt 2 sử dụng chúng.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - test_success_response_uses_common_envelope(): kiểm tra response thành công
 * - test_validation_exception_uses_common_error_envelope(): kiểm tra lỗi field
 * - test_paginated_response_contains_pagination_meta(): kiểm tra metadata phân trang
 * - test_paginated_response_merges_extra_meta(): kiểm tra metadata bổ sung từ controller
 * - test_data_table_response_contains_items_and_items_length(): kiểm tra contract DataTableServer
 * - test_no_content_response_has_empty_body(): kiểm tra response 204
 * - test_media_security_exception_uses_validation_envelope(): kiểm tra lỗi file nguy hiểm
 * =====================================================================
 */
class BaseResponseTest extends TestCase
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra response thành công dùng envelope thống nhất
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 200 với đủ success, message, data, errors và meta
     *
     * SIDE EFFECT:
     * - Không có
     * =====================================================================
     */
    public function test_success_response_uses_common_envelope(): void
    {
        $response = BaseResponse::success(['id' => 1], 'OK', meta: ['request_id' => 'test']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'success' => true,
            'message' => 'OK',
            'data' => ['id' => 1],
            'errors' => [],
            'meta' => ['request_id' => 'test'],
        ], $response->getData(true));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra validation exception được chuyển thành lỗi chuẩn
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 422 với errors theo tên field và không làm lộ exception nội bộ
     *
     * SIDE EFFECT:
     * - Không có
     * =====================================================================
     */
    public function test_validation_exception_uses_common_error_envelope(): void
    {
        try {
            Validator::make([], ['email' => ['required', 'email']])->validate();
        } catch (ValidationException $exception) {
            $response = BaseResponse::fromException($exception);

            $this->assertSame(422, $response->getStatusCode());
            $this->assertSame(false, $response->getData(true)['success']);
            $this->assertArrayHasKey('email', $response->getData(true)['errors']);

            return;
        }

        $this->fail('ValidationException was not thrown.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra response phân trang có metadata chuẩn
     * =====================================================================
     *
     * INPUT:
     * - ResourceItem bọc một Paginator giả lập, dùng để chứng minh mọi phần
     *   tử đều đi qua JsonResource trước khi vào data
     *
     * OUTPUT:
     * - HTTP 200 với items đã định hình trong data và pagination trong meta
     *
     * SIDE EFFECT:
     * - Không có
     * =====================================================================
     */
    public function test_paginated_response_contains_pagination_meta(): void
    {
        $paginator = new LengthAwarePaginator(
            [new ResourceStub(['id' => 1, 'title' => 'Demo'])],
            total: 3,
            perPage: 1,
            currentPage: 2,
        );

        $payload = BaseResponse::paginated(
            TestResourceItem::collection($paginator),
        )->getData(true);

        $this->assertSame([['id' => 1, 'title' => 'Demo']], $payload['data']);
        $this->assertSame(2, $payload['meta']['pagination']['current_page']);
        $this->assertSame(3, $payload['meta']['pagination']['total']);
        $this->assertSame(3, $payload['meta']['pagination']['last_page']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra paginated vẫn nhận metadata bổ sung từ controller
     * =====================================================================
     *
     * INPUT:
     * - ResourceItem bọc Paginator kèm message và meta tùy chọn
     *
     * OUTPUT:
     * - data giữ nguyên, meta.pagination và meta.filters cùng tồn tại
     *
     * SIDE EFFECT:
     * - Không có
     * =====================================================================
     */
    public function test_paginated_response_merges_extra_meta(): void
    {
        $paginator = new LengthAwarePaginator(
            [new ResourceStub(['id' => 1, 'title' => 'Demo'])],
            total: 1,
            perPage: 15,
            currentPage: 1,
        );

        $payload = BaseResponse::paginated(
            TestResourceItem::collection($paginator),
            'Danh sách tài nguyên.',
            ['filters' => ['status' => 'draft']],
        )->getData(true);

        $this->assertSame('Danh sách tài nguyên.', $payload['message']);
        $this->assertSame(['status' => 'draft'], $payload['meta']['filters']);
        $this->assertSame(1, $payload['meta']['pagination']['total']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra response chuyên dụng cho Vuetify DataTableServer
     * =====================================================================
     *
     * INPUT:
     * - ResourceItem bọc paginator có 5 dòng, trang hiện tại là 2
     *
     * OUTPUT:
     * - data.items chứa dòng đã resolve và data.itemsLength chứa tổng số dòng
     * - meta.pagination vẫn giữ metadata phân trang chuẩn
     *
     * SIDE EFFECT:
     * - Không có
     * =====================================================================
     */
    public function test_data_table_response_contains_items_and_items_length(): void
    {
        $paginator = new LengthAwarePaginator(
            [new ResourceStub(['id' => 2, 'title' => 'Trang hai'])],
            total: 5,
            perPage: 1,
            currentPage: 2,
        );

        $payload = BaseResponse::dataTable(
            TestResourceItem::collection($paginator),
            'Danh sách bảng.',
        )->getData(true);

        $this->assertSame('Danh sách bảng.', $payload['message']);
        $this->assertSame([['id' => 2, 'title' => 'Trang hai']], $payload['data']['items']);
        $this->assertSame(5, $payload['data']['itemsLength']);
        $this->assertSame(2, $payload['meta']['pagination']['current_page']);
        $this->assertSame(5, $payload['meta']['pagination']['total']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra helper noContent trả body rỗng
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 204 và body rỗng
     *
     * SIDE EFFECT:
     * - Không có
     * =====================================================================
     */
    public function test_no_content_response_has_empty_body(): void
    {
        $response = BaseResponse::noContent();

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra lỗi security upload trả về HTTP 422 an toàn
     * =====================================================================
     *
     * OUTPUT:
     * - HTTP 422 với lỗi field file, không trả stack trace
     */
    public function test_media_security_exception_uses_validation_envelope(): void
    {
        $response = BaseResponse::fromException(new MediaSecurityException('File không hợp lệ.'));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['File không hợp lệ.'], $response->getData(true)['errors']['file']);
    }
}
