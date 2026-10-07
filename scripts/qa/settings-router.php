<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/** Loopback-only disposable QA server with real Laravel requests and explicit failure injection. */
$root = realpath(__DIR__.'/../..');
$qa = $root.'/.zcode/fix1-settings-qa';
if (! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || ! is_file($qa.'/database.sqlite')) {
    http_response_code(403);
    exit('QA chỉ chạy trên loopback với database fixture đã tạo.');
}
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (str_starts_with($path, '/qa-files/')) {
    $file = realpath($qa.'/public/'.substr($path, strlen('/qa-files/')));
    $base = realpath($qa.'/public');
    if (! $file || ! $base || ! str_starts_with(strtolower($file), strtolower($base).DIRECTORY_SEPARATOR) || ! is_file($file)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: '.(new finfo(FILEINFO_MIME_TYPE))->file($file));
    readfile($file);
    exit;
}
$staticFile = realpath($root.'/public'.$path);
if ($path !== '/' && $staticFile && str_starts_with(strtolower($staticFile), strtolower(realpath($root.'/public')).DIRECTORY_SEPARATOR)
    && is_file($staticFile) && ! str_ends_with(strtolower($staticFile), '.php')) {
    return false;
}
$mode = json_decode(file_get_contents($qa.'/scenario.json'), true)['mode'] ?? 'normal';
if ($mode === 'timeout-ai' && $path === '/api/admin/settings/ai') {
    sleep(17);
}
if (str_starts_with($path, '/api/admin/settings') && ! in_array($path, ['/api/admin/settings/preferences', '/api/admin/settings/ai'], true)) {
    if ($mode === 'timeout' && $path === '/api/admin/settings') {
        sleep(17);
    }
    if (in_array($mode, ['failed', 'forbidden', 'conflict', 'validation'], true)) {
        $status = ['failed' => 500, 'forbidden' => 403, 'conflict' => 409, 'validation' => 422][$mode];
        // Conflict/validation apply only to writes, keeping read/retry requests real.
        if (in_array($mode, ['failed', 'forbidden'], true) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code($status);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'QA '.$mode.': kiểm tra giữ draft và thử lại.', 'errors' => $mode === 'validation' ? ['site_name' => ['QA lỗi validation']] : []], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$qa.'/database.sqlite');
putenv('APP_URL=http://127.0.0.1:8017');
putenv('CACHE_STORE=array');
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'database.default' => 'sqlite', 'database.connections.sqlite.database' => $qa.'/database.sqlite',
    'cache.default' => 'array', 'permission.cache.store' => 'array', 'queue.default' => 'database',
    'filesystems.disks.public.root' => $qa.'/public', 'filesystems.disks.public.url' => 'http://127.0.0.1:8017/qa-files',
    'mail.default' => 'array',
]);
$app->handleRequest(Request::capture());
