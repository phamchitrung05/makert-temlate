<?php

namespace App\Services\Media;

use App\Exceptions\MediaSecurityException;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Scan archive để chặn traversal, symlink và ZIP bomb
 * =====================================================================
 *
 * Scanner chỉ đọc central directory của ZIP, không giải nén ra filesystem.
 * Nó kiểm tra tên entry, executable extension, encrypted entry, symlink, số
 * lượng file, tổng dung lượng giải nén và compression ratio.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - scan(): trả thống kê archive nếu an toàn
 * - assertEntrySafe(): kiểm tra từng entry
 * - isSymlink(): phát hiện Unix symlink từ external attributes
 * - entryExtensions(): lấy extension trong tên entry
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : absolute path tới ZIP tạm thời
 * - OUTPUT: array entries/uncompressed_bytes/compression_ratio hoặc exception
 * =====================================================================
 */
class ArchiveSecurityScanner
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Scan archive mà không giải nén file
     * =====================================================================
     *
     * INPUT: $path là file ZIP local đã qua upload validation.
     * OUTPUT: array thống kê archive an toàn.
     * EXCEPTION/TRANSACTION: MediaSecurityException nếu archive vi phạm policy.
     */
    public function scan(string $path): array
    {
        $zip = new ZipArchive;
        $opened = $zip->open($path);

        if ($opened !== true) {
            throw new MediaSecurityException('Không thể mở archive để scan.');
        }

        try {
            $maxEntries = (int) config('media-assets.archive.max_entries');
            $maxBytes = (int) config('media-assets.archive.max_uncompressed_bytes');
            $maxRatio = (float) config('media-assets.archive.max_compression_ratio');
            $entryCount = $zip->numFiles;
            $uncompressedBytes = 0;

            if ($entryCount > $maxEntries) {
                throw new MediaSecurityException('Archive chứa quá nhiều entry.');
            }

            for ($index = 0; $index < $entryCount; $index++) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat)) {
                    throw new MediaSecurityException('Archive có entry không đọc được.');
                }

                $this->assertEntrySafe($zip, $index, $stat);
                $uncompressedBytes += (int) ($stat['size'] ?? 0);

                if ($uncompressedBytes > $maxBytes) {
                    throw new MediaSecurityException('Archive vượt giới hạn dung lượng giải nén.');
                }

                $compressedBytes = (int) ($stat['comp_size'] ?? 0);
                if ($compressedBytes > 0 && ($stat['size'] / $compressedBytes) > $maxRatio) {
                    throw new MediaSecurityException('Archive có compression ratio bất thường.');
                }
            }

            $fileSize = max(1, (int) filesize($path));

            return [
                'entries' => $entryCount,
                'uncompressed_bytes' => $uncompressedBytes,
                'compression_ratio' => round($uncompressedBytes / $fileSize, 2),
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra một entry trong archive
     * =====================================================================
     *
     * INPUT: ZipArchive, index và stat entry.
     * OUTPUT: Không trả giá trị nếu entry an toàn.
     * EXCEPTION/TRANSACTION: MediaSecurityException nếu traversal/symlink/encrypted/dangerous.
     */
    private function assertEntrySafe(ZipArchive $zip, int $index, array $stat): void
    {
        $name = (string) ($stat['name'] ?? '');
        $normalized = str_replace('\\', '/', $name);

        if ($name === ''
            || str_contains($name, "\0")
            || Str::startsWith($normalized, '/')
            || preg_match('/^[a-z]:\//i', $normalized)
            || in_array('..', explode('/', $normalized), true)) {
            throw new MediaSecurityException('Archive chứa path traversal hoặc absolute path.');
        }

        if (! (bool) config('media-assets.archive.allow_symlinks', false) && $this->isSymlink($zip, $index)) {
            throw new MediaSecurityException('Archive chứa symlink không được phép.');
        }

        $flags = (int) ($stat['flags'] ?? 0);
        if (! (bool) config('media-assets.archive.allow_encrypted', false) && ($flags & 1) === 1) {
            throw new MediaSecurityException('Archive mã hóa không được phép.');
        }

        if (array_intersect(
            $this->entryExtensions($name),
            config('media-assets.archive.blocked_entry_extensions', []),
        ) !== []) {
            throw new MediaSecurityException('Archive chứa file executable hoặc extension nguy hiểm.');
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Phát hiện Unix symlink trong ZIP entry
     * =====================================================================
     *
     * INPUT: ZipArchive và index entry.
     * OUTPUT: bool true khi external attributes chỉ ra symlink.
     */
    private function isSymlink(ZipArchive $zip, int $index): bool
    {
        if (! method_exists($zip, 'getExternalAttributesIndex')) {
            return false;
        }

        $opsys = 0;
        $attributes = 0;
        if (! $zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            return false;
        }

        return (($attributes >> 16) & 0xF000) === 0xA000;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy extension trong tên entry archive
     * =====================================================================
     *
     * INPUT: $name tên entry đã đọc từ central directory.
     * OUTPUT: list<string> extension trung gian và extension cuối.
     */
    private function entryExtensions(string $name): array
    {
        $parts = explode('.', strtolower(basename($name)));

        return count($parts) > 1 ? array_values(array_slice($parts, 1)) : [];
    }
}
