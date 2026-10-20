<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Registry AI schemas theo module cấu hình.
 * =====================================================================
 *
 * File chỉ khai báo capability immutable; orchestration/HTTP không nằm ở đây.
 * INPUT/OUTPUT: config array -> registry metadata; không gọi provider hoặc DB.
 * =====================================================================
 */
return [
        'article.analysis-plan.v1' => ['version' => '1.0', 'fields' => ['knowledge', 'writing_plan']],
        'article.writer.v1' => ['version' => '1.0', 'fields' => ['draft', 'used_fact_ids', 'used_asset_ids']],
        'article.editor.v1' => ['version' => '1.0', 'fields' => ['final', 'used_fact_ids', 'issues']],
        'article.seo.v1' => ['version' => '1.0', 'fields' => ['focus_keyword', 'seo_title', 'seo_description', 'canonical_url', 'robots_index', 'robots_follow', 'og_title', 'og_description']],
        'writing-profile.analysis.v1' => ['version' => '1.0', 'fields' => ['summary', 'rules', 'evidence', 'style_instructions']],
        'post.content.v1' => [
            'version' => '1.0',
            'fields' => [
                'title', 'content_html', 'excerpt', 'focus_keyword', 'seo_title',
                'seo_description', 'canonical_url', 'robots_index', 'robots_follow',
                'og_title', 'og_description', 'thumbnail_prompt', 'thumbnail_alt_text',
            ],
        ],
        'social_post.facebook_content.v2' => [
            'version' => '2.0',
            'json_schema' => [
                'type' => 'object',
                'properties' => [
                    'content_html' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5000],
                    'cta' => ['type' => 'string', 'maxLength' => 500],
                    'hashtags' => [
                        'type' => 'array', 'maxItems' => 12,
                        'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                    ],
                    'link_url' => ['type' => ['string', 'null'], 'maxLength' => 1000],
                    'gallery_prompt' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000],
                    'negative_prompt' => ['type' => 'string', 'maxLength' => 1500],
                    'aspect_ratio' => ['type' => 'string', 'enum' => ['1:1', '4:5', '9:16']],
                    'slot_count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
                ],
                'required' => ['content_html', 'cta', 'hashtags', 'link_url', 'gallery_prompt', 'negative_prompt', 'aspect_ratio', 'slot_count'],
                'additionalProperties' => false,
            ],
            'fields' => ['content_html', 'cta', 'hashtags', 'link_url', 'gallery_prompt', 'negative_prompt', 'aspect_ratio', 'slot_count'],
        ],
        'social_post.tiktok_content.v1' => [
            'version' => '1.0',
            'json_schema' => [
                'type' => 'object',
                'properties' => [
                    'excerpt' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000],
                    'video_prompt' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5000],
                    'duration_seconds' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 600],
                    'aspect_ratio' => ['type' => 'string', 'enum' => ['9:16', '1:1']],
                    'scenes' => [
                        'type' => 'array', 'maxItems' => 20,
                        'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                    ],
                ],
                'required' => ['excerpt', 'video_prompt', 'duration_seconds', 'aspect_ratio', 'scenes'],
                'additionalProperties' => false,
            ],
            'fields' => ['excerpt', 'video_prompt', 'duration_seconds', 'aspect_ratio', 'scenes'],
        ],
        'social_post.caption.v1' => [
            'version' => '1.0',
            'json_schema' => [
                'type' => 'object',
                'properties' => [
                    'caption' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5000],
                    'cta' => ['type' => 'string', 'maxLength' => 500],
                    'hashtags' => [
                        'type' => 'array', 'maxItems' => 12,
                        'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                    ],
                ],
                'required' => ['caption'],
                'additionalProperties' => false,
            ],
            'fields' => ['caption', 'cta', 'hashtags'],
        ],
        'social_post.facebook_content.v1' => [
            'version' => '1.0',
            'json_schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => ['string', 'null'], 'maxLength' => 255],
                    'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5000],
                    'bullet_points' => [
                        'type' => 'array', 'maxItems' => 8,
                        'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
                    ],
                    'cta' => ['type' => ['string', 'null'], 'maxLength' => 500],
                    'contact' => [
                        'type' => 'object',
                        'properties' => [
                            'address' => ['type' => ['string', 'null'], 'maxLength' => 500],
                            'phone' => ['type' => ['string', 'null'], 'maxLength' => 120],
                            'website' => ['type' => ['string', 'null'], 'maxLength' => 500],
                        ],
                        'required' => ['address', 'phone', 'website'],
                        'additionalProperties' => false,
                    ],
                    'hashtags' => [
                        'type' => 'array', 'maxItems' => 12,
                        'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                    ],
                    'link_url' => ['type' => ['string', 'null'], 'maxLength' => 1000],
                ],
                'required' => ['title', 'body', 'bullet_points', 'cta', 'contact', 'hashtags', 'link_url'],
                'additionalProperties' => false,
            ],
            'fields' => ['title', 'body', 'bullet_points', 'cta', 'contact', 'hashtags', 'link_url'],
        ],
        'social_post.image.v1' => [
            'version' => '1.0',
            'json_schema' => [
                'type' => 'object',
                'properties' => [
                    'image_prompt' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000],
                    'negative_prompt' => ['type' => 'string', 'maxLength' => 1500],
                    'aspect_ratio' => ['type' => 'string', 'enum' => ['1:1', '4:5', '9:16']],
                ],
                'required' => ['image_prompt'],
                'additionalProperties' => false,
            ],
            'fields' => ['image_prompt', 'negative_prompt', 'aspect_ratio'],
        ],
        'social_post.facebook_image.v1' => [
            'version' => '1.0',
            'json_schema' => [
                'type' => 'object',
                'properties' => [
                    'image_prompt' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000],
                    'negative_prompt' => ['type' => 'string', 'maxLength' => 1500],
                    'aspect_ratio' => ['type' => 'string', 'enum' => ['1:1', '4:5', '9:16']],
                    'slot_count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
                ],
                'required' => ['image_prompt', 'negative_prompt', 'aspect_ratio', 'slot_count'],
                'additionalProperties' => false,
            ],
            'fields' => ['image_prompt', 'negative_prompt', 'aspect_ratio', 'slot_count'],
        ],
    ];
