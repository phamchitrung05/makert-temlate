<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điểm lắp registry AI theo target/module.
 * =====================================================================
 *
 * Capability được chia theo output, target và prompt/schema để frontend,
 * pipeline và worker dùng cùng một contract. Resource/Sound không thuộc catalog
 * hiện tại; TikTok được khai báo profile nhưng chưa bật.
 *
 * INPUT/OUTPUT: các file config/ai/*.php -> registry config immutable.
 * SIDE EFFECT: chỉ đọc file cấu hình; không gọi provider hoặc ghi database.
 * =====================================================================
 */
return [
    'legacy_required_outputs' => ['title', 'content'],
    'output_aliases' => ['content' => 'content_html'],
    'output_definitions' => require __DIR__.'/outputs.php',
    'targets' => require __DIR__.'/targets.php',
    'prompts' => require __DIR__.'/prompts.php',
    'schemas' => require __DIR__.'/schemas.php',
];
