<?php

namespace App\Services\Ai\Providers\Transport;

use App\Exceptions\AiImportException;
use App\Services\Ai\Content\ArticleSourceFetcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: HTTP boundary chung cho text/image/discovery, redaction và egress.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: __construct(), normalizeBaseUrl(), send().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * INPUT: connection server-side + payload.
 * OUTPUT: JSON hoặc lỗi domain an toàn.
 * SIDE EFFECT: HTTPS outbound có timeout, không redirect hay log body/key.
 * RETRY: GET chỉ retry HTTP 429/5xx; mất kết nối/quá hạn cần người dùng thử lại.
 * EXCEPTION/TRANSACTION: AiImportException an toàn; không mở transaction.
 * =====================================================================
 */
final class AiProviderClient
{
    /**
     * =====================================================================
     * Input: Fetcher dùng chung để kiểm tra URL và chặn địa chỉ private.
     * Output: Client giữ boundary kiểm tra URL; không gửi request hoặc ghi dữ liệu.
     * =====================================================================
     */
    public function __construct(private readonly ArticleSourceFetcher $fetcher) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa HTTPS base và kiểm tra egress policy
     * =====================================================================
     * INPUT: API base/driver.
     * OUTPUT: API base canonical; lỗi được chuyển thành AiImportException an toàn.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function normalizeBaseUrl(string $url, string $driver): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new AiImportException('Endpoint phải là HTTPS API base, không có query hoặc fragment.', 'AI_ENDPOINT_INVALID');
        }
        $base = rtrim($this->fetcher->validateUrl(trim($url)), '/');
        if ($driver !== 'gemini' && empty(trim($parts['path'] ?? '', '/'))) {
            $base .= '/v1';
        }
        $allowed = config('ai-providers.allowed_hosts', []);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($allowed !== [] && ! in_array($host, $allowed, true)) {
            throw new AiImportException('Host endpoint chưa được phép bởi egress policy.', 'AI_ENDPOINT_NOT_ALLOWED');
        }

        return $base;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gửi request JSON qua connection snapshot đã được kiểm tra.
     * =====================================================================
     * INPUT: connection, method/path và JSON/query.
     * OUTPUT: decoded JSON.
     * SIDE EFFECT: một POST hoặc GET có retry HTTP giới hạn; không retry mất kết nối, không persist secret.
     * EXCEPTION/TRANSACTION: lỗi auth/quota/schema không chứa upstream body/header; không transaction.
     * =====================================================================
     */
    public function send(AiConnection $connection, string $method, string $path, array $data = []): array
    {
        $method = strtoupper($method);
        if (! in_array($method, ['GET', 'POST'], true)) {
            throw new AiImportException('Provider request method không được phép.', 'AI_PROVIDER_METHOD_INVALID');
        }
        $snapshot = $connection->snapshot;
        $base = $this->normalizeBaseUrl($snapshot['base_url'], $snapshot['driver']);
        $url = $base.'/'.ltrim($path, '/');
        $this->fetcher->validateUrl($url);
        $request = Http::acceptJson()->connectTimeout(5)
            ->timeout((int) ($snapshot['timeout'] ?? 30))
            ->withOptions(['allow_redirects' => false]);
        $request = $snapshot['driver'] === 'gemini'
            ? $request->withHeaders(['x-goog-api-key' => $connection->apiKey])
            : $request->withToken($connection->apiKey);

        /**
         * =====================================================================
         * GHI CHÚ: Pin DNS public cho request chứa credential.
         * =====================================================================
         * Mỗi host:port có một rule chứa toàn bộ IP để cURL chọn IPv4/IPv6;
         * rule riêng cho từng IP sẽ ghi đè DNS cache và chỉ còn IP cuối.
         * HTTP fake vẫn offline; host test không resolve sẽ không tạo socket.
         * =====================================================================
         */
        $host = trim((string) parse_url($base, PHP_URL_HOST), '[]');
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
        foreach ($addresses as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP)
                || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new AiImportException('Endpoint không được trỏ về private network.', 'AI_ENDPOINT_NOT_ALLOWED');
            }
        }
        if ($addresses !== [] && defined('CURLOPT_RESOLVE')) {
            $resolveAddresses = array_map(
                static fn (string $ip): string => str_contains($ip, ':')
                    ? '['.$ip.']'
                    : $ip,
                $addresses,
            );
            $resolve = [$host.':443:'.implode(',', $resolveAddresses)];
            $request = $request->withOptions(['curl' => [CURLOPT_RESOLVE => $resolve]]);
        }

        if ($method === 'GET') {
            $request = $request->retry([200, 600], 0, fn ($exception): bool => ($exception instanceof \Illuminate\Http\Client\RequestException
                    && ($exception->response->status() === 429 || $exception->response->serverError())), false);
        }
        try {
            $response = $method === 'GET' ? $request->get($url, $data) : $request->post($url, $data);
        } catch (ConnectionException) {
            throw new AiImportException('Không kết nối được, kết nối bị ngắt hoặc đã hết thời gian chờ provider. Hãy kiểm tra trạng thái request ở provider rồi thử lại thủ công.', 'AI_PROVIDER_TIMEOUT');
        }
        if (! $response->successful()) {
            $message = match ($response->status()) {
                401 => 'API key không hợp lệ.',
                403 => 'API key chưa có quyền thực hiện thao tác hoặc dùng model này.',
                404 => 'Model hoặc API endpoint không tồn tại.',
                429 => 'Provider đã hết quota hoặc đạt giới hạn request.',
                default => 'Provider trả HTTP '.$response->status().'.',
            };
            throw new AiImportException($message, 'AI_PROVIDER_HTTP_'.$response->status(),
                $response->status() === 429 || ($method === 'GET' && $response->serverError()));
        }
        if (strlen($response->body()) > (int) config('ai-providers.max_response_bytes')) {
            throw new AiImportException('Response provider vượt giới hạn.', 'AI_PROVIDER_RESPONSE_TOO_LARGE');
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new AiImportException('Provider trả JSON không hợp lệ.', 'AI_PROVIDER_INVALID_JSON');
        }

        return $json;
    }
}
