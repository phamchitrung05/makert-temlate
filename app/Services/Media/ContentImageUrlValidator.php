<?php

namespace App\Services\Media;

use DOMDocument;
use Illuminate\Validation\ValidationException;

/** Kiểm link ảnh trong HTML; chỉ đọc markup, không tìm asset hoặc tạo quan hệ Post. */
final class ContentImageUrlValidator
{
    /** Input: HTML bài viết. Output: hợp lệ hoặc lỗi content; giữ nguyên các ảnh lặp và định dạng. */
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
            if ($image->hasAttribute('srcset')) {
                $this->fail('Ảnh nội dung hiện dùng một link ảnh; chưa hỗ trợ srcset.');
            }
            if (! $this->validUrl(trim($image->getAttribute('src')))) {
                $this->fail('Link ảnh phải là HTTP/HTTPS hoặc đường dẫn từ gốc website. Chờ upload xong trước khi lưu ảnh tạm.');
            }
        }
    }

    /** Input: HTML đã lưu. Output: từng URL img theo vị trí; không khử trùng hoặc truy cập đường dẫn. */
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

    /** Input: URL img. Output: chỉ nhận URL web hoặc đường dẫn gốc, không nhận blob/data/file/script. */
    private function validUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url) || str_contains($url, '\\')) {
            return false;
        }
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }
        $absolute = str_starts_with($url, '//') ? 'https:'.$url : $url;

        return filter_var($absolute, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($absolute, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /** Input: HTML UTF-8. Output: DOM tách rời; không tải ảnh/script và khôi phục cấu hình libxml. */
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

    /** Input: thông báo. Output: lỗi validation content; không sửa HTML hoặc ghi database. */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['content' => $message]);
    }
}
