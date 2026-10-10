<?php

namespace App\Services\Ai\Errors;

use Illuminate\Support\Str;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuẩn hóa lỗi AI thành thông báo tiếng Việt an toàn.
 * =====================================================================
 * Lớp này là allowlist dùng chung cho API Content AI và task queue. Mã lỗi
 * kỹ thuật vẫn được trả riêng để debug, còn người dùng nhận câu giải thích
 * và hướng xử lý phù hợp với giai đoạn thất bại.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - message(): chọn thông báo public theo error code và message bounded.
 * - codeMessages(): trả allowlist mã lỗi và hướng xử lý tiếng Việt.
 * - reasonMessages(): trả allowlist lỗi kiểm chất lượng cụ thể.
 * - fallback(): chọn thông báo mặc định theo loại task.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : error code, message bounded và task type tùy chọn.
 * - OUTPUT: plain text tối đa 500 ký tự, không chứa raw provider response.
 * - SIDE EFFECT: không gọi provider, không đọc/ghi database hoặc mở transaction.
 * =====================================================================
 */
final class AiPublicErrorMessage
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Chọn thông báo lỗi công khai dễ hiểu cho người dùng.
     * =====================================================================
     * INPUT: code/message từ domain exception hoặc tracker và task type.
     * OUTPUT: câu tiếng Việt bounded; mã kỹ thuật không bị trộn vào câu chính.
     * SIDE EFFECT: không gọi API hoặc mutate input.
     * EXCEPTION/TRANSACTION: không ném lỗi nghiệp vụ, không mở transaction.
     * =====================================================================
     */
    public static function message(?string $code, ?string $message = null, ?string $taskType = null, array $validationErrors = []): string
    {
        $code = $code ? strtoupper(trim($code)) : null;
        foreach ($validationErrors as $error) {
            $reason = is_array($error) ? ($error['reason'] ?? '') : '';
            if (is_string($reason) && isset(self::reasonMessages()[$reason])) {
                return self::reasonMessages()[$reason];
            }
        }
        $safeMessage = filled($message) && ! preg_match('/\A[A-Z0-9_]{2,120}\z/', trim($message))
            ? trim($message) : null;
        $genericMessages = [
            'Tác vụ AI thất bại. Hãy kiểm tra cấu hình và thử lại.',
            'Tác vụ AI thất bại. Mở module tương ứng để kiểm tra cấu hình hoặc tạo lại.',
        ];
        // Chỉ nhận message đã được worker bounded; raw code/provider response bị loại bỏ.
        $candidate = $safeMessage && ! in_array($safeMessage, $genericMessages, true)
            ? $safeMessage : (self::codeMessages()[$code] ?? self::fallback($taskType));

        return Str::limit($candidate, 500, '');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả allowlist diễn giải mã lỗi AI thường gặp.
     * =====================================================================
     * INPUT: không có.
     * OUTPUT: map code => thông báo tiếng Việt và hành động gợi ý.
     * SIDE EFFECT: hàm thuần, tạo map mới mỗi lần gọi.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public static function codeMessages(): array
    {
        return [
            'AI_QUALITY_GROUNDING' => 'Bản tạo mới thiếu hoặc làm thay đổi dữ kiện quan trọng trong nguồn. Hãy kiểm tra số liệu, phiên bản, liên kết và đoạn mã rồi tạo lại.',
            'AI_QUALITY_EXACT_COPY' => 'Bản tạo mới sao chép quá sát nội dung nguồn. Hãy tạo lại với yêu cầu diễn đạt khác.',
            'AI_QUALITY_LANGUAGE' => 'Bản tạo mới chưa đúng ngôn ngữ yêu cầu. Hãy chọn lại ngôn ngữ hoặc tạo lại.',
            'AI_QUALITY_EDITOR' => 'Bản tạo mới chưa đạt yêu cầu biên tập. Bản trước vẫn được giữ để bạn kiểm tra.',
            'AI_PROVIDER_MISSING_FIELDS' => 'AI trả về thiếu trường nội dung bắt buộc. Hãy kiểm tra model hoặc tạo lại.',
            'AI_PROVIDER_INVALID_OUTPUT' => 'AI trả về dữ liệu không đúng cấu trúc. Hãy chọn model khác hoặc tạo lại.',
            'AI_PROVIDER_TIMEOUT' => 'AI phản hồi quá lâu. Hãy thử lại sau hoặc chọn model khác.',
            'AI_PROVIDER_NOT_CONFIGURED' => 'Dịch vụ hoặc model AI chưa được cấu hình hợp lệ. Hãy kiểm tra AI Settings và chọn lại model.',
            'AI_PROVIDER_NOT_ALLOWED' => 'Bạn chưa được phép dùng dịch vụ AI đã chọn. Hãy chọn dịch vụ khác hoặc kiểm tra quyền.',
            'AI_PROVIDER_REFUSAL' => 'Dịch vụ AI từ chối xử lý nguồn này. Hãy kiểm tra nội dung và điều chỉnh yêu cầu.',
            'AI_PROVIDER_TOOL_OUTPUT' => 'Model trả về lệnh công cụ thay vì nội dung. Hãy chọn model viết bài phù hợp.',
            'AI_PROVIDER_INCOMPLETE' => 'AI trả nội dung chưa đầy đủ. Hãy rút ngắn yêu cầu hoặc chọn model có giới hạn lớn hơn.',
            'AI_PROVIDER_EMPTY_CONTENT' => 'AI không trả về nội dung. Hãy kiểm tra nguồn và thử lại hoặc đổi model.',
            'AI_PROVIDER_INVALID_JSON' => 'AI trả dữ liệu không đọc được. Hãy thử lại hoặc chọn model khác.',
            'AI_PROVIDER_SCHEMA' => 'Dữ liệu AI trả về không đúng cấu trúc yêu cầu. Hãy thử lại hoặc chọn model khác.',
            'AI_SOURCE_REFERENCE' => 'AI sử dụng dẫn chứng không khớp nguồn. Hãy kiểm tra dữ kiện và tạo lại.',
            'AI_INPUT_BUDGET' => 'Nguồn và yêu cầu vượt giới hạn của model. Hãy rút ngắn nguồn hoặc chọn model có giới hạn lớn hơn.',
            'AI_ARCHIVE_WRITE_FAILED' => 'Nội dung đã tạo nhưng chưa lưu được kết quả. Hãy thử lại để phục hồi kết quả.',
            'SOURCE_EMPTY' => 'Không đọc được nội dung bài từ nguồn. Hãy kiểm tra liên kết hoặc dán nội dung trực tiếp.',
            'SOURCE_TOO_LARGE' => 'Nội dung nguồn quá dài. Hãy chia nhỏ hoặc rút ngắn nguồn rồi thử lại.',
            'AI_IMPORT_FAILED' => 'Không thể tạo nội dung AI do lỗi hệ thống. Hãy kiểm tra cấu hình rồi thử lại.',
            'AI_IMAGE_FAILED' => 'Không thể tạo ảnh AI. Hãy kiểm tra model ảnh rồi thử lại.',
            'AI_IMAGE_CONFIGURATION' => 'Chưa có model ảnh hợp lệ. Hãy chọn model ảnh trước khi tạo lại.',
            'AI_IMAGE_QUEUE_FAILED' => 'Không thể xếp hàng tạo ảnh. Nội dung vẫn được giữ; hãy thử lại ảnh.',
            'AI_WRITING_PROFILE_FAILED' => 'Không thể phân tích văn phong. Hãy kiểm tra model hoặc bài mẫu rồi thử lại.',
            'AI_EVALUATION_FAILED' => 'Không thể chấm chất lượng bài AI. Hãy thử chấm lại sau.',
            'AI_EVALUATION_STALE' => 'Điểm chất lượng không còn khớp phiên bản hiện tại. Hãy tải lại và chấm lại.',
            'CANCELLED' => 'Tác vụ đã được hủy.',
            'EXPIRED' => 'Tác vụ đã hết hạn. Hãy tạo lại nội dung.',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Diễn giải lý do quality gate cụ thể mà không trả excerpt nguồn.
     * =====================================================================
     * INPUT: không có. OUTPUT: map reason => câu tiếng Việt và hướng khắc phục.
     * SIDE EFFECT: hàm thuần. EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public static function reasonMessages(): array
    {
        return [
            'source_code_changed' => 'Bản tạo mới làm mất hoặc thay đổi đoạn mã trong nguồn. Hãy tạo lại với yêu cầu giữ nguyên code.',
            'important_number_missing' => 'Bản tạo mới thiếu số liệu hoặc phiên bản quan trọng từ nguồn. Hãy đối chiếu nguồn và tạo lại.',
            'source_link_missing' => 'Bản tạo mới thiếu liên kết tham khảo từ nguồn. Hãy tạo lại với yêu cầu giữ các liên kết.',
            'source_link_unknown' => 'Bản tạo mới có liên kết tham khảo không khớp nguồn. Hãy kiểm tra liên kết và tạo lại.',
            'evidence_not_in_source' => 'Dẫn chứng AI sử dụng không có trong nguồn. Hãy kiểm tra nội dung và tạo lại.',
            'missing_important_fact_reference' => 'Bản tạo mới bỏ sót dữ kiện quan trọng từ nguồn. Hãy đối chiếu nguồn và tạo lại.',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chọn thông báo mặc định theo loại task khi thiếu mã lỗi.
     * =====================================================================
     * INPUT: task type tracker.
     * OUTPUT: thông báo tiếng Việt bounded.
     * SIDE EFFECT: hàm thuần.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public static function fallback(?string $taskType): string
    {
        return match ($taskType) {
            'writing_profile_analysis' => 'Không thể phân tích văn phong. Hãy kiểm tra bài mẫu và thử lại.',
            'image_generation' => 'Không thể tạo ảnh AI. Hãy kiểm tra model ảnh và thử lại.',
            default => 'Không thể tạo nội dung AI. Hãy kiểm tra nguồn/model và thử lại.',
        };
    }
}
