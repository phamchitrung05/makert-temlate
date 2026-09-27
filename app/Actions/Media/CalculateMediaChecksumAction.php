<?php

namespace App\Actions\Media;

use App\Exceptions\MediaSecurityException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tính checksum SHA-256 cho file media tạm thời
 * =====================================================================
 *
 * Checksum được lưu trong custom properties của Spatie Media để đối chiếu
 * file, hỗ trợ deduplication và retry idempotent.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(): đọc file và trả checksum SHA-256
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : absolute path tới file local đã validate
 * - OUTPUT: chuỗi SHA-256 64 ký tự hoặc exception nếu không đọc được
 * =====================================================================
 */
class CalculateMediaChecksumAction
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tính checksum SHA-256 của file
     * =====================================================================
     *
     * INPUT: $path là absolute path tới file local.
     * OUTPUT: string SHA-256 lowercase.
     * EXCEPTION/TRANSACTION: MediaSecurityException nếu file không tồn tại/đọc được.
     */
    public function handle(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new MediaSecurityException('Không thể đọc file để tính checksum.');
        }

        $checksum = hash_file('sha256', $path);
        if (! is_string($checksum)) {
            throw new MediaSecurityException('Không thể tính checksum SHA-256.');
        }

        return $checksum;
    }
}
