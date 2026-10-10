<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cấu hình rubric chấm điểm chất lượng bài viết do AI tạo.
 * =====================================================================
 * File này là nguồn duy nhất cho phiên bản rubric, thang điểm, ngưỡng đạt
 * và 5 tiêu chí được dùng bởi ArticleQualityEvaluationService.
 *
 * CÁC HÀM/METHOD TRONG FILE: Không có; file chỉ trả về mảng config.
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : biến môi trường AI_QUALITY_RUBRIC_VERSION và AI_QUALITY_THRESHOLD.
 * - OUTPUT: rubric_version, scale, threshold và criteria cho evaluator.
 * - SIDE EFFECT: không gọi AI, không query và không ghi database.
 * =====================================================================
 */
return [
    'rubric_version' => env('AI_QUALITY_RUBRIC_VERSION', 'article-quality.v1'),
    'scale' => [
        'min' => 0,
        'max' => 5,
    ],
    'threshold' => (float) env('AI_QUALITY_THRESHOLD', 4),
    'criteria' => [
        'accuracy' => 'Độ chính xác và không thêm dữ kiện ngoài nguồn',
        'source_grounding' => 'Mức bám nguồn và dẫn chứng kiểm tra được',
        'clarity' => 'Rõ ràng, hữu ích và phù hợp người đọc',
        'structure' => 'Cấu trúc, mạch lập luận và khả năng đọc',
        'style' => 'Đúng ngôn ngữ, brief và writing profile',
    ],
];
