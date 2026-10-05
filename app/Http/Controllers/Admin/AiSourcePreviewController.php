<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AiImportException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiImportRequest;
use App\Http\Responses\BaseResponse;
use App\Services\Ai\Content\ArticleSourceExtractor;
use App\Services\Ai\Content\ArticleSourceFetcher;
use Illuminate\Http\JsonResponse;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Preview nguồn đã extract trước khi người dùng tạo bài AI.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - __invoke(): đọc nguồn, extract và trả block/hash để giao diện kiểm tra.
 * =====================================================================
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : đúng một URL/text/HTML/file HTML của admin có quyền target.
 * - OUTPUT: source snapshot đã sanitize; không tạo run hoặc Post.
 * - SIDE EFFECT: có thể fetch URL qua SSRF boundary; không gọi model AI.
 * =====================================================================
 */
final class AiSourcePreviewController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả preview nguồn và source anchors bằng extractor dùng chung
     * =====================================================================
     * INPUT: request đã validate, fetcher và extractor từ container.
     * OUTPUT: envelope source snapshot hoặc lỗi nguồn/budget rõ ràng.
     * SIDE EFFECT: chỉ đọc nguồn; HTTP đọc nguồn có giới hạn, không ghi database.
     * EXCEPTION/TRANSACTION: exception nguồn/validation chuẩn; không transaction.
     * =====================================================================
     */
    public function __invoke(AiImportRequest $request, ArticleSourceFetcher $fetcher, ArticleSourceExtractor $extractor): JsonResponse
    {
        $data = $request->validated();
        $sourceUrl = '';
        if (filled($data['url'] ?? null)) {
            $source = $fetcher->fetch($fetcher->validateUrl($data['url']));
            $html = $source['html'];
            $sourceUrl = $source['url'];
        } elseif ($request->hasFile('html_file') || filled($data['html'] ?? null)) {
            $html = $request->hasFile('html_file') ? (string) $request->file('html_file')->getContent() : $data['html'];
            if (($data['source_encoding'] ?? 'UTF-8') !== 'UTF-8') {
                $html = mb_convert_encoding($html, 'UTF-8', $data['source_encoding']);
            }
        } else {
            $html = '<article><p>'.nl2br(e($data['text'])).'</p></article>';
        }
        $content = $extractor->extract($html, $sourceUrl);
        if (trim(strip_tags($content)) === '') {
            throw new AiImportException('Không tìm thấy nội dung bài trong nguồn.', 'SOURCE_EMPTY');
        }
        preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $title);
        $snapshot = $extractor->snapshot($content, [
            'title' => $data['title'] ?? trim(html_entity_decode(strip_tags($title[1] ?? 'Bài viết mới'), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'source_url' => $sourceUrl, 'canonical_url' => $sourceUrl,
        ]);

        return BaseResponse::success($snapshot, 'Nguồn đã extract và làm sạch; chưa gọi AI.');
    }
}
