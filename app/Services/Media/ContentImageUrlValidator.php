<?php

namespace App\Services\Media;

use DOMDocument;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm contract URL ảnh trong HTML Post.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: validate(), imageUrls(), validUrl(), document(), fail().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTML sau boundary request, trước khi sanitize và lưu Post.
 * - OUTPUT: pass hoặc ValidationException; không sửa HTML và không query asset.
 * =====================================================================
 */
final class ContentImageUrlValidator
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm cấu trúc ảnh và chỉ cho phép URL HTTP/HTTPS/root-relative.
     * =====================================================================
     * INPUT: HTML bài viết chưa sanitize.
     * OUTPUT: không trả dữ liệu khi hợp lệ; lỗi validation khi URL/kiểu ảnh sai.
     * SIDE EFFECT: chỉ parse DOM trong memory, không tải URL.
     * EXCEPTION/TRANSACTION: ValidationException; không mở transaction.
     * =====================================================================
     */
    public function validate(string $html): void
    {
        if (trim($html) === '') {
            return;
        }

        $document = $this->document($html);
        if ($document->getElementsByTagName('picture')->length > 0) {
            $this->fail('Ảnh nội dung hiện dùng img với một link ảnh; chưa hỗ trợ picture/source.');
        }
        foreach ($document->getElementsByTagName('source') as $source) {
            if ($source->hasAttribute('srcset')) {
                $this->fail('Ảnh nội dung hiện dùng img với một link ảnh; chưa hỗ trợ source/srcset.');
            }
        }
        foreach ($document->getElementsByTagName('img') as $image) {
            foreach ($image->attributes as $attribute) {
                if (str_starts_with(strtolower($attribute->name), 'on')) {
                    $this->fail('Ảnh nội dung không được chứa thuộc tính thực thi sự kiện.');
                }
            }
            // Style và attribute lạ do ContentHtmlSanitizer loại bỏ; validator giữ contract event ảnh.
            if ($image->hasAttribute('srcset')) {
                $this->fail('Ảnh nội dung hiện dùng một link ảnh; chưa hỗ trợ srcset.');
            }
            if (! $this->validUrl(trim($image->getAttribute('src')))) {
                $this->fail('Link ảnh phải là HTTP/HTTPS hoặc đường dẫn từ gốc website. Chờ upload xong trước khi lưu ảnh tạm.');
            }
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trích URL img theo thứ tự để consumer cần đọc HTML.
     * =====================================================================
     * INPUT: HTML đã lưu.
     * OUTPUT: danh sách URL ảnh; không sanitize hoặc truy cập đường dẫn.
     * SIDE EFFECT: chỉ parse DOM trong memory.
     * EXCEPTION/TRANSACTION: không có; không mở transaction.
     * =====================================================================
     */
    public function imageUrls(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $urls = [];
        foreach ($this->document($html)->getElementsByTagName('img') as $image) {
            $urls[] = trim($image->getAttribute('src'));
        }

        return $urls;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm một URL ảnh có thuộc protocol được phép hay không.
     * =====================================================================
     * INPUT: URL src của img.
     * OUTPUT: bool; chỉ nhận HTTP/HTTPS hoặc root-relative.
     * SIDE EFFECT: không có.
     * EXCEPTION/TRANSACTION: không có; không mở transaction.
     * =====================================================================
     */
    private function validUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url) || str_contains($url, '\\')) {
            return false;
        }
        if (str_starts_with($url, '//')) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return true;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Parse HTML UTF-8 trong DOM tách rời.
     * =====================================================================
     * INPUT: HTML UTF-8.
     * OUTPUT: DOMDocument; không tải ảnh/script và khôi phục cấu hình libxml.
     * SIDE EFFECT: chỉ thay đổi parser state trong thời gian parse.
     * EXCEPTION/TRANSACTION: libxml warning được nuốt; không mở transaction.
     * =====================================================================
     */
    private function document(string $html): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ném lỗi validation chuẩn cho field content.
     * =====================================================================
     * INPUT: thông báo tiếng Việt.
     * OUTPUT: never.
     * SIDE EFFECT: tạo ValidationException; không sửa HTML hoặc ghi database.
     * EXCEPTION/TRANSACTION: ValidationException; không mở transaction.
     * =====================================================================
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['content' => $message]);
    }
}
