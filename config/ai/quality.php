<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cấu hình runtime cho bộ chấm chất lượng bài AI trước duyệt.
 * =====================================================================
 * Rubric, thang điểm, 5 tiêu chí và ngưỡng được khai báo tập trung tại
 * config/ai/scoring.php. File này chỉ giữ phiên bản prompt/schema và vòng
 * đời xử lý evaluator; các giá trị được snapshot vào evaluation.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : biến môi trường và cấu hình queue/AI hiện tại.
 * - OUTPUT: phiên bản rubric, schema, timeout và retention cho evaluator.
 * =====================================================================
 */
return [
    'prompt_version' => env('AI_QUALITY_PROMPT_VERSION', '1.0'),
    'schema_version' => env('AI_QUALITY_SCHEMA_VERSION', 'article-quality.evaluation.v1'),
    'retention_days' => max(1, (int) env('AI_QUALITY_RETENTION_DAYS', 2)),
    'request_timeout' => max(15, (int) env('AI_QUALITY_REQUEST_TIMEOUT', 45)),
    'max_attempts' => max(1, (int) env('AI_QUALITY_MAX_ATTEMPTS', 2)),
];
