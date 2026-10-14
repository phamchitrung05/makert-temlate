<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Headers/error boundary preview stateless không log token
 * CÁC HÀM/METHOD TRONG FILE: handle(): catch lỗi và thêm no-store/noindex.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): request -> response bounded, không session.
 * =====================================================================
 */
final class TemplatePreviewHeaders
{
    /** Input: request và next. Output: response an toàn kể cả lỗi, log status không URL/token. */
    public function handle(Request $request, Closure $next)
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode()
                : ($exception instanceof ValidationException ? 409 : ($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 503));
            Log::warning('Template preview unavailable.', ['status' => $status, 'reason' => $exception::class]);
            $response = response('Template preview không khả dụng.', $status);
        }
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
