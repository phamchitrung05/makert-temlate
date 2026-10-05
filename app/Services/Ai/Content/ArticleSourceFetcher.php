<?php

namespace App\Services\Ai\Content;

use App\Exceptions\AiImportException;
use Illuminate\Support\Facades\Http;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: HTTP fetcher an toàn cho nguồn bài viết và thumbnail.
 * =====================================================================
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - downloadImage(), fetch(), validateUrl(), isPrivateAddress(), resolveRedirect().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : URL người dùng hoặc Location redirect.
 * - OUTPUT: HTML/binary đã giới hạn kích thước và kiểm MIME/signature.
 * - SIDE EFFECT: chỉ gọi HTTP outbound; không ghi database.
 * - EXCEPTION/TRANSACTION: AiImportException cho SSRF, redirect, MIME,
 *   timeout/HTTP lỗi; không mở transaction.
 */
class ArticleSourceFetcher
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tải ảnh metadata qua egress boundary an toàn.
     * =====================================================================
     * INPUT: URL ảnh từ metadata nguồn.
     * OUTPUT: bytes ảnh đã kiểm MIME/signature/size.
     * SIDE EFFECT: gọi HTTP GET outbound.
     * EXCEPTION/TRANSACTION: AiImportException khi URL/response không hợp lệ; không transaction.
     * =====================================================================
     */
    public function downloadImage(string $url): string
    {
        $url = $this->validateUrl($url);
        $response = Http::connectTimeout((int) config('ai-import.connect_timeout', 5))
            ->timeout((int) config('ai-import.timeout', 12))
            ->withOptions(['allow_redirects' => false])
            ->get($url);
        if (! $response->successful() || ! str_starts_with(strtolower((string) $response->header('Content-Type')), 'image/')) {
            throw new AiImportException('Ảnh thumbnail nguồn không hợp lệ.', 'THUMBNAIL_INVALID');
        }
        $binary = (string) $response->body();
        if (strlen($binary) > (int) config('ai-import.max_image_bytes', 10 * 1024 * 1024) || @getimagesizefromstring($binary) === false) {
            throw new AiImportException('Ảnh thumbnail nguồn vượt giới hạn.', 'THUMBNAIL_TOO_LARGE');
        }

        return $binary;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tải HTML cuối cùng sau tối đa N redirect.
     * =====================================================================
     * INPUT: URL HTTP(S).
     * OUTPUT: HTML cuối cùng sau tối đa N redirect.
     * SIDE EFFECT: gọi HTTP GET và kiểm response size/content type.
     * EXCEPTION/TRANSACTION: AiImportException cho redirect/HTTP/size; không transaction.
     *
     * @return array{url:string,html:string,content_type:string}
     *                                                           =====================================================================
     */
    public function fetch(string $url): array
    {
        $current = $this->validateUrl($url);
        $maxRedirects = (int) config('ai-import.max_redirects', 3);

        for ($redirect = 0; $redirect <= $maxRedirects; $redirect++) {
            $response = Http::connectTimeout((int) config('ai-import.connect_timeout', 5))
                ->timeout((int) config('ai-import.timeout', 12))
                ->withHeaders([
                    'User-Agent' => (string) config('ai-import.user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36'),
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->withOptions(['allow_redirects' => false])
                ->get($current);

            if ($response->redirect()) {
                if ($redirect === $maxRedirects) {
                    throw new AiImportException('URL nguồn chuyển hướng quá nhiều lần.', 'REDIRECT_LIMIT');
                }
                $location = trim((string) $response->header('Location'));
                if ($location === '') {
                    throw new AiImportException('URL nguồn trả về chuyển hướng không hợp lệ.', 'INVALID_REDIRECT');
                }
                $current = $this->validateUrl($this->resolveRedirect($current, $location));

                continue;
            }

            if (! $response->successful()) {
                $retryable = $response->status() === 429 || $response->serverError();
                throw new AiImportException(
                    'Không thể đọc URL nguồn (HTTP '.$response->status().').',
                    'SOURCE_HTTP_'.$response->status(),
                    $retryable,
                );
            }

            $contentType = strtolower((string) $response->header('Content-Type'));
            if ($contentType !== '' && ! str_contains($contentType, 'text/html') && ! str_contains($contentType, 'application/xhtml')) {
                throw new AiImportException('URL nguồn không phải HTML.', 'SOURCE_NOT_HTML');
            }
            $maxBytes = (int) config('ai-import.max_html_bytes', 5 * 1024 * 1024);
            if ((int) $response->header('Content-Length', 0) > $maxBytes) {
                throw new AiImportException('HTML nguồn vượt giới hạn kích thước.', 'SOURCE_TOO_LARGE');
            }
            $html = (string) $response->body();
            if (strlen($html) > $maxBytes) {
                throw new AiImportException('HTML nguồn vượt giới hạn kích thước.', 'SOURCE_TOO_LARGE');
            }

            return ['url' => $current, 'html' => $html, 'content_type' => $contentType ?: 'text/html'];
        }

        throw new AiImportException('Không thể đọc URL nguồn.', 'SOURCE_FETCH_FAILED');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate URL trước mọi outbound request.
     * =====================================================================
     * INPUT: URL người dùng hoặc Location redirect.
     * OUTPUT: URL normalized chỉ http/https, không private address/credential.
     * SIDE EFFECT: DNS lookup để kiểm địa chỉ private.
     * EXCEPTION/TRANSACTION: AiImportException nếu URL không được phép; không transaction.
     * =====================================================================
     */
    public function validateUrl(string $url): string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new AiImportException('URL không hợp lệ.', 'INVALID_URL');
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = $parts['port'] ?? null;
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ($port !== null && ! in_array((int) $port, [80, 443], true))) {
            throw new AiImportException('URL nguồn không được phép.', 'URL_NOT_ALLOWED');
        }
        if (isset($parts['user']) || isset($parts['pass']) || $this->isPrivateAddress($host)) {
            throw new AiImportException('URL nguồn không được phép.', 'URL_NOT_ALLOWED');
        }

        $normalizedHost = trim($host, '[]');
        $displayHost = str_contains($normalizedHost, ':') ? '['.$normalizedHost.']' : $normalizedHost;

        return $scheme.'://'.$displayHost.(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận diện host local/private/link-local/cloud metadata.
     * =====================================================================
     * INPUT: hostname hoặc IP đã parse.
     * OUTPUT: true nếu địa chỉ không được phép.
     * SIDE EFFECT: có thể DNS resolve hostname.
     * EXCEPTION/TRANSACTION: lỗi DNS được coi như không có address; không transaction.
     * =====================================================================
     */
    private function isPrivateAddress(string $host): bool
    {
        /**
         * =====================================================================
         * GHI CHÚ: PHP giữ host IPv6 trong ngoặc vuông; cần chuẩn hóa trước khi
         * kiểm tra dải IP, bao gồm cả metadata/private address dạng IPv4-mapped IPv6.
         * =====================================================================
         */
        $host = rtrim(trim($host, '[]'), '.');
        if (str_starts_with($host, '::ffff:')) {
            $packed = @inet_pton($host);
            $host = $packed !== false ? (string) inet_ntop(substr($packed, -4)) : $host;
        }
        if (in_array($host, ['localhost', 'localhost.localdomain', 'metadata.google.internal'], true)) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }
        $addresses = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            if (filled($record['ip'] ?? null)) {
                $addresses[] = (string) $record['ip'];
            }
            if (filled($record['ipv6'] ?? null)) {
                $addresses[] = (string) $record['ipv6'];
            }
        }
        $addresses = array_values(array_unique($addresses));
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve Location redirect thành URL tuyệt đối.
     * =====================================================================
     * INPUT: URL hiện tại và Location header tương đối/tuyệt đối.
     * OUTPUT: URL tuyệt đối để validate lại ở vòng redirect tiếp theo.
     * SIDE EFFECT: không gọi network.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    private function resolveRedirect(string $base, string $location): string
    {
        if (filter_var($location, FILTER_VALIDATE_URL)) {
            return $location;
        }
        $baseParts = parse_url($base);
        $origin = ($baseParts['scheme'] ?? 'https').'://'.($baseParts['host'] ?? '');
        if (isset($baseParts['port'])) {
            $origin .= ':'.$baseParts['port'];
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }
        $directory = rtrim(str_replace('\\', '/', dirname((string) ($baseParts['path'] ?? '/'))), '/');

        return $origin.($directory === '' ? '/' : $directory.'/').$location;
    }
}
