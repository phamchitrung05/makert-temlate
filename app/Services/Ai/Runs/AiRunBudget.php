<?php

namespace App\Services\Ai\Runs;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tính ngân sách queue theo số lượt AI đã chọn cho run.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - calls(): xác định một hoặc ba lượt từ target/fields/pipeline snapshot.
 * - timeout(): cộng ngân sách HTTP và phần xử lý nguồn/media.
 * =====================================================================
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : input run bất biến và request timeout đã được kiểm tra.
 * - OUTPUT: số lượt tối đa và job timeout, không phải ETA.
 * - SIDE EFFECT: không gọi provider hoặc ghi database.
 * =====================================================================
 */
final class AiRunBudget
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tính số lượt theo nhánh generation thật sự
     * =====================================================================
     * INPUT: target, fields, provider và pipeline mode của run.
     * OUTPUT: một lượt cho nhánh cũ/ngắn hoặc ba lượt cho Post content.
     * SIDE EFFECT: chỉ đọc snapshot/config; không gọi AI.
     * =====================================================================
     */
    public static function calls(array $input): int
    {
        $fields = (array) ($input['fields'] ?? $input['requested_outputs'] ?? ['title', 'content']);
        $mode = data_get($input, 'pipeline_snapshot.pipeline', config('ai-content.pipeline', 'three_step'));

        return ($input['target_type'] ?? 'post') === 'post'
            && ($input['provider'] ?? '') !== 'deterministic'
            && ($fields === [] || in_array('content', $fields, true))
            && $mode === 'three_step' ? 3 : 1;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tính timeout bao đủ các lượt HTTP và xử lý nguồn/media
     * =====================================================================
     * INPUT: request timeout giây và số lượt đã snapshot.
     * OUTPUT: số giây timeout cho queue job.
     * SIDE EFFECT: không gọi network/DB; queue retry_after phải lớn hơn giá trị này.
     * =====================================================================
     */
    public static function timeout(int $requestTimeout, int $calls = 1): int
    {
        return max((int) config('ai-import.job_timeout', 180), max(5, min(600, $requestTimeout)) * max(1, min(3, $calls)) + 120);
    }
}
