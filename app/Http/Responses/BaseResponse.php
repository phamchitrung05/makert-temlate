<?php

namespace App\Http\Responses;

use App\Exceptions\MediaSecurityException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Pagination\Paginator as PaginatorInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Prettus\Validator\Exceptions\ValidatorException as PrettusValidatorException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuẩn hóa response JSON và lỗi của API
 * =====================================================================
 *
 * BaseResponse là điểm duy nhất tạo envelope HTTP cho API. Controller chỉ
 * truyền data nghiệp vụ; class này chịu trách nhiệm giữ ổn định các khóa
 * success, message, data, errors và meta cho cả response thành công lẫn lỗi.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - success(): tạo response thành công với status tùy chọn
 * - created(): tạo response thành công HTTP 201
 * - error(): tạo response lỗi theo envelope chung
 * - validation(): tạo response lỗi validation HTTP 422
 * - fromException(): chuyển exception thành response API an toàn
 * - paginated(): tạo response danh sách có metadata phân trang, nhận
 *   ResourceCollection để mọi phần tử đều đi qua JsonResource
 * - dataTable(): tạo response chuẩn cho Vuetify VDataTableServer
 * - noContent(): tạo response HTTP 204 không có body
 * - defaultMessage(): chọn message fallback theo status HTTP
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : data, message, errors, metadata hoặc Throwable từ tầng HTTP
 * - OUTPUT: JsonResponse có envelope thống nhất; noContent() trả response 204
 * =====================================================================
 */
final class BaseResponse
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo response thành công theo API envelope chung
     * =====================================================================
     *
     * INPUT:
     * - $data: payload nghiệp vụ bất kỳ, có thể là array, object hoặc null
     * - $message: message hiển thị tùy chọn
     * - $status: HTTP status thành công, mặc định 200
     * - $meta: metadata bổ sung như pagination hoặc request context
     *
     * OUTPUT:
     * - JsonResponse gồm success=true, message, data, errors rỗng và meta
     *
     * SIDE EFFECT:
     * - Không ghi database, không phát event và không thay đổi request
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; Laravel có thể ném exception nếu payload không serialize được
     * =====================================================================
     */
    public static function success(
        mixed $data = null,
        ?string $message = null,
        int $status = Response::HTTP_OK,
        array $meta = [],
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => [],
            'meta' => $meta,
        ], $status);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo response thành công cho resource vừa được tạo
     * =====================================================================
     *
     * INPUT:
     * - $data: resource hoặc payload vừa tạo
     * - $message: message thành công tùy chọn
     * - $meta: metadata bổ sung
     *
     * OUTPUT:
     * - JsonResponse HTTP 201 theo cùng envelope với success()
     *
     * SIDE EFFECT:
     * - Không có; method chỉ ủy quyền việc tạo response cho success()
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction
     * =====================================================================
     */
    public static function created(
        mixed $data = null,
        ?string $message = null,
        array $meta = [],
    ): JsonResponse {
        return self::success($data, $message, Response::HTTP_CREATED, $meta);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo response lỗi theo API envelope chung
     * =====================================================================
     *
     * INPUT:
     * - $message: message an toàn để client hiển thị hoặc ghi nhận
     * - $status: HTTP status lỗi
     * - $errors: lỗi theo field hoặc chi tiết máy đọc được
     * - $meta: metadata lỗi không nhạy cảm
     *
     * OUTPUT:
     * - JsonResponse gồm success=false, data=null, errors và meta
     *
     * SIDE EFFECT:
     * - Không ghi exception hoặc secret vào response ngoài dữ liệu caller truyền vào
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction
     * =====================================================================
     */
    public static function error(
        string $message,
        int $status = Response::HTTP_BAD_REQUEST,
        array $errors = [],
        array $meta = [],
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
            'meta' => $meta,
        ], $status);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo response cho lỗi validation của Form Request
     * =====================================================================
     *
     * INPUT:
     * - $errors: mảng lỗi theo tên field do Validator cung cấp
     * - $message: message tổng quát tùy chọn
     *
     * OUTPUT:
     * - JsonResponse HTTP 422 với errors giữ nguyên cấu trúc theo field
     *
     * SIDE EFFECT:
     * - Không có
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction
     * =====================================================================
     */
    public static function validation(
        array $errors,
        ?string $message = null,
    ): JsonResponse {
        return self::error(
            $message ?? config('messages.common.validation'),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $errors,
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuyển exception thành response API an toàn
     * =====================================================================
     *
     * INPUT:
     * - $exception: Throwable phát sinh trong request API
     *
     * OUTPUT:
     * - JsonResponse có status/message/errors phù hợp với loại exception
     * - MediaSecurityException được chuyển thành validation error cho field file
     * - Exception nội bộ HTTP 5xx không làm lộ message hoặc stack trace
     *
     * SIDE EFFECT:
     * - Không log; việc report exception vẫn do Laravel exception handler đảm nhiệm
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction và không ném lại exception
     * =====================================================================
     */
    public static function fromException(Throwable $exception): JsonResponse
    {
        if ($exception instanceof ValidationException) {
            return self::validation($exception->errors());
        }

        if ($exception instanceof MediaSecurityException) {
            return self::validation([
                'file' => [$exception->getMessage()],
            ]);
        }

        if ($exception instanceof PrettusValidatorException) {
            return self::validation($exception->getMessageBag()->toArray());
        }

        if ($exception instanceof AuthenticationException) {
            return self::error(config('messages.common.unauthenticated'), Response::HTTP_UNAUTHORIZED);
        }

        if ($exception instanceof AuthorizationException) {
            return self::error(
                $exception->getMessage() ?: self::defaultMessage(Response::HTTP_FORBIDDEN),
                Response::HTTP_FORBIDDEN,
            );
        }

        // DomainException dùng để báo trạng thái nghiệp vụ không hợp lệ,
        // ví dụ publish resource đã published; đây là lỗi client nên trả 422
        // kèm message gốc thay vì 500.
        if ($exception instanceof \DomainException) {
            return self::error(
                $exception->getMessage() ?: self::defaultMessage(Response::HTTP_UNPROCESSABLE_ENTITY),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($exception instanceof ModelNotFoundException) {
            return self::error(self::defaultMessage(Response::HTTP_NOT_FOUND), Response::HTTP_NOT_FOUND);
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $message = $status >= Response::HTTP_INTERNAL_SERVER_ERROR
                ? self::defaultMessage($status)
                : ($exception->getMessage() ?: self::defaultMessage($status));

            return self::error($message, $status);
        }

        return self::error(
            self::defaultMessage(Response::HTTP_INTERNAL_SERVER_ERROR),
            Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đóng gói danh sách có metadata phân trang
     * =====================================================================
     *
     * INPUT:
     * - $collection: ResourceCollection đã bọc Paginator, mang cả items lẫn
     *   thông tin phân trang. Bắt buộc dùng ResourceCollection thay vì
     *   Paginator thô để mọi phần tử đều đi qua JsonResource.
     * - $message: message thành công tùy chọn
     * - $meta: metadata bổ sung ngoài thông tin phân trang
     *
     * OUTPUT:
     * - JsonResponse HTTP 200 với data đã định hình và meta.pagination theo
     *   shape chuẩn Laravel (current_page, per_page, total, last_page, from,
     *   to, path, links). Khoá data của paginator bị loại bỏ vì đã nằm ở
     *   trường data của envelope.
     *
     * SIDE EFFECT:
     * - Không truy vấn thêm database; chỉ đọc dữ liệu đã nạp trong collection
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction
     * =====================================================================
     */
    public static function paginated(
        AnonymousResourceCollection $collection,
        ?string $message = null,
        array $meta = [],
    ): JsonResponse {
        $items = $collection->resolve();
        $pagination = self::resolvePagination($collection, $items);

        unset($pagination['data']);

        return self::success(
            $items,
            $message,
            Response::HTTP_OK,
            array_merge([
                'pagination' => $pagination,
            ], $meta),
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đóng gói danh sách theo contract của Vuetify DataTableServer
     * =====================================================================
     *
     * INPUT:
     * - $collection: ResourceCollection bọc paginator; mỗi item được resolve
     *   qua JsonResource trước khi đưa vào data.items
     * - $message: message thành công tùy chọn
     * - $meta: metadata bổ sung ngoài pagination
     *
     * OUTPUT:
     * - JsonResponse HTTP 200 với data.items là các dòng và data.itemsLength
     *   là tổng số dòng để bind vào prop `items-length` của VDataTableServer
     * - meta.pagination vẫn giữ đầy đủ current_page, per_page, total và links
     *   để service frontend có thể dùng thêm khi cần
     *
     * SIDE EFFECT:
     * - Không truy vấn thêm database; chỉ resolve collection đã nạp
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; Laravel có thể ném lỗi nếu resource không serialize được
     * =====================================================================
     */
    public static function dataTable(
        AnonymousResourceCollection $collection,
        ?string $message = null,
        array $meta = [],
    ): JsonResponse {
        $items = $collection->resolve();
        $pagination = self::resolvePagination($collection, $items);

        unset($pagination['data']);

        return self::success(
            [
                'items' => $items,
                'itemsLength' => (int) ($pagination['total'] ?? count($items)),
            ],
            $message,
            Response::HTTP_OK,
            array_merge([
                'pagination' => $pagination,
            ], $meta),
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo response thành công không có nội dung
     * =====================================================================
     *
     * INPUT:
     * - Không có
     *
     * OUTPUT:
     * - Response HTTP 204 với body rỗng
     *
     * SIDE EFFECT:
     * - Không có
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction
     * =====================================================================
     */
    public static function noContent(): Response
    {
        return response()->noContent();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa metadata phân trang từ ResourceCollection
     * =====================================================================
     *
     * INPUT:
     * - $collection: ResourceCollection có thể bọc Paginator hoặc collection thường
     * - $items: mảng item đã resolve qua JsonResource
     *
     * OUTPUT:
     * - array<string, mixed>: metadata paginator hoặc total fallback
     *
     * SIDE EFFECT:
     * - Không có; chỉ đọc resource và paginator
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction
     * =====================================================================
     */
    private static function resolvePagination(
        AnonymousResourceCollection $collection,
        array $items,
    ): array {
        $paginator = $collection->resource;

        return $paginator instanceof PaginatorInterface
            ? $paginator->toArray()
            : ['total' => count($items)];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chọn message fallback theo HTTP status
     * =====================================================================
     *
     * INPUT:
     * - $status: HTTP status cần mô tả
     *
     * OUTPUT:
     * - string: message an toàn, không chứa chi tiết nội bộ
     *
     * SIDE EFFECT:
     * - Không có
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction
     * =====================================================================
     */
    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            Response::HTTP_UNAUTHORIZED => config('messages.common.unauthenticated'),
            Response::HTTP_FORBIDDEN => config('messages.common.forbidden'),
            Response::HTTP_NOT_FOUND => config('messages.common.not_found'),
            Response::HTTP_TOO_MANY_REQUESTS => config('messages.common.too_many_requests'),
            Response::HTTP_UNPROCESSABLE_ENTITY => config('messages.common.validation'),
            default => $status >= Response::HTTP_INTERNAL_SERVER_ERROR
                ? config('messages.common.server_error')
                : config('messages.common.bad_request'),
        };
    }
}
