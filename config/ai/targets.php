<?php

use App\Services\Ai\Targets\PostAiAdapter;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Registry target AI được bật theo capability.
 * =====================================================================
 *
 * Post và Social dùng chung source/pipeline skeleton nhưng có output,
 * schema, editor và adapter riêng. TikTok được chuẩn bị ở profile disabled.
 * Resource/Sound không còn thuộc catalog AI hiện tại.
 *
 * INPUT/OUTPUT: target key -> capability immutable; không gọi provider/DB.
 * =====================================================================
 */
return [
    'post' => [
        'enabled' => true,
        'label' => 'Post',
        'icon' => 'tabler-article',
        'color' => 'primary',
        'permission' => 'posts.manage',
        'content_instructions' => 'Write useful, natural prose appropriate to the source and article brief. Choose structure and length to fit the material; headings, introduction and conclusion are optional. Preserve facts, conditions, code, tables, links and quotes. Never generate taxonomy, slug, actor or publishing status.',
        'adapter' => PostAiAdapter::class,
        'operations' => ['create'],
        'inputs' => ['url', 'file', 'html', 'text'],
        'outputs' => ['title', 'excerpt', 'content', 'seo', 'thumbnail'],
        'pipeline' => [
            'content' => ['output' => 'content', 'depends_on' => []],
            'seo' => ['output' => 'seo', 'depends_on' => ['content']],
            'thumbnail' => ['output' => 'thumbnail', 'depends_on' => ['content']],
        ],
        'platforms' => [],
        'manual_taxonomy' => true,
    ],

    'social_post' => [
        'enabled' => true,
        'label' => 'Social',
        'icon' => 'tabler-brand-facebook',
        'color' => 'primary',
        'permission' => 'social_posts.create',
        'adapter' => null,
        'operations' => ['create'],
        'inputs' => ['text'],
        'outputs' => ['social_content', 'social_gallery'],
        'platforms' => ['facebook'],
        'default_platform' => 'facebook',
        'manual_taxonomy' => false,
        'platform_profiles' => [
            'facebook' => [
                'enabled' => true,
                'label' => 'Facebook',
                'content_instructions' => 'Write a Facebook social post in the requested language. Use only the supplied source, writing profile and brief; keep the copy concise, useful and ready for a Facebook draft. Do not assume or invent a product, price, date, guarantee, address or contact detail. Return content_html, CTA and hashtags only. Do not generate title, excerpt, SEO, taxonomy, slug, actor or publishing status.',
                'content_schema' => 'social_post.facebook_content.v2',
                'outputs' => ['social_content', 'social_gallery'],
                'editor_fields' => ['content', 'cta', 'hashtags', 'link_url', 'gallery'],
                'pipeline' => [
                    'content' => ['output' => 'social_content', 'depends_on' => []],
                    'gallery' => ['output' => 'social_gallery', 'depends_on' => []],
                ],
            ],
            'tiktok' => [
                'enabled' => false,
                'label' => 'TikTok',
                'content_instructions' => 'Prepare a TikTok excerpt and short-form video brief from the supplied source. Do not invent claims, product facts or publishing metadata.',
                'content_schema' => 'social_post.tiktok_content.v1',
                'outputs' => ['social_excerpt', 'social_video'],
                'editor_fields' => ['excerpt', 'video'],
                'pipeline' => [
                    'excerpt' => ['output' => 'social_excerpt', 'depends_on' => []],
                    'video' => ['output' => 'social_video', 'depends_on' => []],
                ],
            ],
        ],
    ],
];
