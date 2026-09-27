<?php

namespace App\Services\Media;

use App\Enums\MediaAssetKind;
use App\Exceptions\MediaSecurityException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate file upload theo allowlist MediaAsset
 * =====================================================================
 *
 * Validator kiểm tra file upload bằng MIME server-detected, extension, size,
 * filename và nội dung ảnh. Không tin Content-Type do browser gửi lên và
 * không cho phép filename có path hoặc extension nguy hiểm trung gian.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - validate(): trả metadata an toàn hoặc ném MediaSecurityException
 * - assertFilenameSafe(): chặn path traversal, null byte và extension nguy hiểm
 * - detectedExtensions(): lấy toàn bộ extension trong filename
 * - assertImageContent(): xác nhận bytes là ảnh thật
 * - kindConfig(): lấy policy theo kind
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : UploadedFile và MediaAssetKind
 * - OUTPUT: metadata original_name, extension, MIME, size; exception nếu file sai
 * =====================================================================
 */
class MediaUploadValidator
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Validate file upload theo policy kind
     * =====================================================================
     *
     * INPUT:
     * - $file: file upload từ HTTP
     * - $kind: nhóm asset cần tạo
     *
     * OUTPUT:
     * - array<string, mixed>: metadata dùng để lưu custom properties
     *
     * EXCEPTION/TRANSACTION:
     * - MediaSecurityException nếu file không hợp lệ; không ghi database
     */
    public function validate(UploadedFile $file, MediaAssetKind $kind): array
    {
        if (! $file->isValid()) {
            throw new MediaSecurityException('File upload không hợp lệ hoặc đã bị lỗi khi truyền.');
        }

        $originalName = $file->getClientOriginalName();
        $this->assertFilenameSafe($originalName);

        $extensions = $this->detectedExtensions($originalName);
        $extension = (string) Str::of(pathinfo($originalName, PATHINFO_EXTENSION))->lower();
        $policy = $this->kindConfig($kind);
        $mimeType = strtolower((string) $file->getMimeType());
        $size = (int) ($file->getSize() ?: 0);

        if ($extension === '' || ! in_array($extension, $policy['extensions'], true)) {
            throw new MediaSecurityException("Extension không được phép cho kind {$kind->value}.");
        }

        if (array_intersect($extensions, config('media-assets.blocked_extensions', [])) !== []) {
            throw new MediaSecurityException('Filename chứa extension nguy hiểm.');
        }

        if (! in_array($mimeType, $policy['mime_types'], true)) {
            throw new MediaSecurityException('MIME type không khớp allowlist của MediaAsset.');
        }

        if ($size <= 0 || $size > ((int) $policy['max_size_kb'] * 1024)) {
            throw new MediaSecurityException('Kích thước file vượt giới hạn cho phép.');
        }

        if ($kind === MediaAssetKind::Image) {
            $this->assertImageContent($file);
        }

        return [
            'original_name' => $originalName,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size' => $size,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn filename không an toàn
     * =====================================================================
     *
     * INPUT: $filename do client cung cấp.
     * OUTPUT: Không trả giá trị nếu filename hợp lệ.
     * EXCEPTION/TRANSACTION: MediaSecurityException nếu có path/null byte/dangerous extension.
     */
    private function assertFilenameSafe(string $filename): void
    {
        if ($filename === ''
            || str_contains($filename, "\0")
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || $filename === '.'
            || $filename === '..'
            || str_contains($filename, '..')) {
            throw new MediaSecurityException('Filename chứa path hoặc ký tự không an toàn.');
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy toàn bộ extension xuất hiện trong filename
     * =====================================================================
     *
     * INPUT: $filename đã qua kiểm tra path.
     * OUTPUT: list<string> gồm extension trung gian và extension cuối.
     */
    private function detectedExtensions(string $filename): array
    {
        $parts = explode('.', strtolower($filename));

        return count($parts) > 1 ? array_values(array_slice($parts, 1)) : [];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra bytes upload là ảnh thật
     * =====================================================================
     *
     * INPUT: $file upload kind image.
     * OUTPUT: Không trả giá trị nếu getimagesize đọc được ảnh.
     * EXCEPTION/TRANSACTION: MediaSecurityException nếu nội dung giả MIME.
     */
    private function assertImageContent(UploadedFile $file): void
    {
        if (@getimagesize($file->getRealPath() ?: '') === false) {
            throw new MediaSecurityException('Nội dung file không phải hình ảnh hợp lệ.');
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy policy allowlist của kind
     * =====================================================================
     *
     * INPUT: $kind enum MediaAssetKind.
     * OUTPUT: array policy extension, MIME và size.
     * EXCEPTION/TRANSACTION: MediaSecurityException nếu config thiếu kind.
     */
    private function kindConfig(MediaAssetKind $kind): array
    {
        $config = config('media-assets.kinds.'.$kind->value);

        if (! is_array($config)) {
            throw new MediaSecurityException('MediaAsset kind chưa có security policy.');
        }

        return $config;
    }
}
