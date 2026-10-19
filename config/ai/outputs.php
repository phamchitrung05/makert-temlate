<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Registry AI outputs theo module cấu hình.
 * =====================================================================
 *
 * File chỉ khai báo capability immutable; orchestration/HTTP không nằm ở đây.
 * INPUT/OUTPUT: config array -> registry metadata; không gọi provider hoặc DB.
 * =====================================================================
 */
return [
        'title' => [
            'label' => 'Tiêu đề', 'fields' => ['title'], 'required' => ['title'],
            'rules' => ['title' => ['type' => 'string', 'max' => 255]],
        ],
        'excerpt' => [
            'label' => 'Mô tả ngắn', 'fields' => ['excerpt'], 'required' => ['excerpt'],
            'rules' => ['excerpt' => ['type' => 'string', 'max' => 5000]],
        ],
        'content' => [
            'label' => 'Nội dung', 'fields' => ['content_html', 'content'], 'required' => ['content_html'],
            'rules' => ['content_html' => ['type' => 'string', 'max' => 200000, 'html' => true]],
        ],
        'seo' => [
            'label' => 'SEO',
            'fields' => ['focus_keyword', 'seo_title', 'seo_description', 'canonical_url', 'robots_index', 'robots_follow', 'og_title', 'og_description'],
            'minimum' => 1,
            'rules' => [
                'focus_keyword' => ['type' => 'string', 'max' => 255],
                'seo_title' => ['type' => 'string', 'max' => 255],
                'seo_description' => ['type' => 'string', 'max' => 5000],
                'canonical_url' => ['type' => 'string', 'max' => 2048, 'url' => true],
                'robots_index' => ['type' => 'boolean'], 'robots_follow' => ['type' => 'boolean'],
                'og_title' => ['type' => 'string', 'max' => 255],
                'og_description' => ['type' => 'string', 'max' => 5000],
            ],
        ],
        'thumbnail' => [
            'label' => 'Ảnh đại diện (nguồn / AI)',
            'fields' => ['thumbnail', 'thumbnail_prompt', 'thumbnail_alt_text'],
            'source_types' => [],
            'source_owned_fields' => ['thumbnail'],
            'rules' => [
                'thumbnail_prompt' => ['type' => 'string', 'max' => 10000],
                'thumbnail_alt_text' => ['type' => 'string', 'max' => 255],
            ],
        ],
        'social_content' => [
            'label' => 'Social content',
            'target_types' => ['social_post'],
            'fields' => ['content_html', 'cta', 'hashtags', 'link_url'],
            'required' => ['content_html'],
            'rules' => [
                'content_html' => ['type' => 'string', 'max' => 5000, 'html' => true],
                'cta' => ['type' => 'string', 'max' => 500],
                'hashtags' => ['type' => 'string_list', 'max_items' => 12, 'item_max' => 80],
                'link_url' => ['type' => 'string', 'max' => 1000, 'url' => true],
            ],
        ],
        'social_gallery' => [
            'label' => 'Gallery image',
            'target_types' => ['social_post'],
            'fields' => ['gallery_prompt', 'negative_prompt', 'aspect_ratio', 'slot_count'],
            'required' => ['gallery_prompt'],
            'minimum' => 1,
            'rules' => [
                'gallery_prompt' => ['type' => 'string', 'max' => 4000],
                'negative_prompt' => ['type' => 'string', 'max' => 1500],
                'aspect_ratio' => ['type' => 'string', 'max' => 10],
                'slot_count' => ['type' => 'integer', 'min' => 1, 'max' => 10],
            ],
        ],
        'social_excerpt' => [
            'label' => 'Social excerpt',
            'target_types' => ['social_post'],
            'fields' => ['excerpt'],
            'required' => ['excerpt'],
            'rules' => ['excerpt' => ['type' => 'string', 'max' => 1000]],
        ],
        'social_video' => [
            'label' => 'Social video',
            'target_types' => ['social_post'],
            'fields' => ['video_prompt', 'duration_seconds', 'aspect_ratio', 'scenes'],
            'required' => ['video_prompt'],
            'minimum' => 1,
            'rules' => [
                'video_prompt' => ['type' => 'string', 'max' => 5000],
                'duration_seconds' => ['type' => 'integer', 'min' => 1, 'max' => 600],
                'aspect_ratio' => ['type' => 'string', 'max' => 10],
                'scenes' => ['type' => 'string_list', 'max_items' => 20, 'item_max' => 500],
            ],
        ],
    ];
