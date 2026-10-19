<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Bootstrap Laravel chỉ khi DB/storage E2E nằm trong thư mục tạm riêng.
 * CÁC HÀM/METHOD TRONG FILE: Không có; kiểm invariant và trả application.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): env testing + đường dẫn tạm -> Application cô lập hoặc lỗi.
 * =====================================================================
 */
$root = realpath((string) getenv('MAKERT_E2E_ROOT'));
$temporary = realpath(sys_get_temp_dir());
$database = realpath((string) getenv('DB_DATABASE'));
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || ! $root || ! $temporary || ! str_starts_with(basename($root), 'makert-e2e-')
    || dirname($root) !== $temporary || ! $database || dirname($database) !== $root) {
    throw new RuntimeException('E2E requires its own temporary SQLite database and storage.');
}
foreach (['framework/cache/data', 'framework/views', 'framework/sessions', 'logs'] as $directory) {
    $path = $root.'/storage/'.$directory;
    if (! is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useStoragePath($root.'/storage');

return $app;
