<?php

namespace App\Services\Settings;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lưu và đọc logo/favicon thuộc cấu hình thương hiệu website.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - changes(): lưu upload đã validate, trả đường dẫn thay đổi.
 * - present(): trả URL public và trạng thái ảnh, không lộ đường dẫn lưu nội bộ.
 * - urlFor()/ownsPath(): chỉ đọc file do dịch vụ này quản lý.
 * - deleteFiles(): dọn file mới khi rollback hoặc file cũ sau commit.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload đã validate và cấu hình site hiện tại.
 * - OUTPUT: đường dẫn nội bộ hoặc DTO branding an toàn cho public/admin.
 * - SIDE EFFECT: ghi/xóa file trên disk public; transaction thuộc settings writer.
 * =====================================================================
 */
final class SiteBrandingService
{
    /** Input: payload, danh sách file mới theo tham chiếu. Output: path/null; ghi file với tên UUID. */
    public function changes(array $payload, array &$created): array
    {
        $changes = [];
        foreach (['logo', 'favicon'] as $kind) {
            $file = $payload[$kind.'_file'] ?? null;
            if ($file instanceof UploadedFile) {
                $path = $file->storeAs('branding/'.$kind, Str::uuid().'.'.$file->guessExtension(), 'public');
                if ($path === false) {
                    throw new \RuntimeException('Không thể lưu ảnh thương hiệu.');
                }
                $created[] = $path;
                $changes[$kind.'_path'] = $path;
            } elseif ($payload['remove_'.$kind] ?? false) {
                $changes[$kind.'_path'] = null;
            }
        }

        return $changes;
    }

    /** Input: site values. Output: URL hiệu lực và cờ custom; favicon trống dùng tài nguyên mặc định. */
    public function present(array $values): array
    {
        $logo = $this->urlFor($values['logo_path'] ?? null);
        $favicon = $this->urlFor($values['favicon_path'] ?? null);

        return [
            'logo_url' => $logo,
            'logo_configured' => $logo !== null,
            'favicon_url' => $favicon ?? asset('favicon.ico'),
            'favicon_configured' => $favicon !== null,
            'favicon_type' => $favicon !== null ? 'image/png' : 'image/x-icon',
        ];
    }

    /** Input: path nội bộ nullable. Output: URL chỉ khi file branding hợp lệ còn tồn tại. */
    private function urlFor(?string $path): ?string
    {
        return $this->ownsPath($path) && Storage::disk('public')->exists($path)
            ? Storage::disk('public')->url($path) : null;
    }

    /** Input: path. Output: true cho đúng thư mục/tên UUID/extension do changes() tạo. */
    private function ownsPath(?string $path): bool
    {
        return $path !== null && preg_match('/\Abranding\/(logo|favicon)\/[a-f0-9-]{36}\.(png|jpg|jpeg|webp)\z/i', $path) === 1;
    }

    /** Input: paths cũ/mới. Output: không có; dọn giới hạn trong branding, lỗi cleanup không đảo kết quả commit. */
    public function deleteFiles(array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            if (! $this->ownsPath($path)) {
                continue;
            }
            try {
                Storage::disk('public')->delete($path);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }
}
