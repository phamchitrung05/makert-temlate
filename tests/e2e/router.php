<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Phục vụ Laravel E2E bằng build thật và storage tạm.
 * CÁC HÀM/METHOD TRONG FILE: Không có; PHP development-server router.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): localhost HTTP -> static asset hoặc route Laravel thật.
 * =====================================================================
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

$public = realpath(dirname(__DIR__, 2).'/public');
$file = realpath($public.'/'.rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/'));
if ($file && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
    return false;
}

$app = require __DIR__.'/bootstrap.php';
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
$app->make(Vite::class)->useHotFile(getenv('MAKERT_E2E_ROOT').'/unused.hot');
$app->handleRequest(Request::capture());
