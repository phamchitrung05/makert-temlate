<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Allowlist model và quyền cho API preview slug dùng chung.
 * CÁC HÀM/METHOD TRONG FILE: không có; map alias -> class/quyền tạo/sửa.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): model_type -> model được server tin cậy.
 * Thêm model mới tại đây sau khi model hỗ trợ HasSlug; không nhận FQCN từ client.
 * =====================================================================
 */
return [
    'post' => [
        'model' => App\Models\Post::class,
        'create' => ['posts.manage', 'posts.create'],
        'update' => ['posts.manage', 'posts.update'],
    ],
    'resource' => ['model' => App\Models\Resource::class, 'create' => 'resources.create', 'update' => 'resources.update'],
    'category' => ['model' => App\Models\Category::class, 'create' => 'taxonomy.manage', 'update' => 'taxonomy.manage'],
    'tag' => ['model' => App\Models\Tag::class, 'create' => 'taxonomy.manage', 'update' => 'taxonomy.manage'],
    'technology' => ['model' => App\Models\Technology::class, 'create' => 'taxonomy.manage', 'update' => 'taxonomy.manage'],
];
