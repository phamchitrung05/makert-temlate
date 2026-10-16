<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cấu hình OAuth và Graph API cho Social publisher.
 * =====================================================================
 * Secret chỉ đọc server-side; không trả config này ra frontend hoặc activity log.
 *
 * CÁC HÀM/METHOD TRONG FILE: không có; file trả mảng cấu hình.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): env Meta -> connection Graph/OAuth.
 * =====================================================================
 */
return [
    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
        'redirect_uri' => env('FACEBOOK_OAUTH_REDIRECT_URI'),
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v20.0'),
        'scopes' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'FACEBOOK_SCOPES',
            'pages_show_list,pages_read_engagement,pages_manage_posts',
        ))))),
    ],
];
