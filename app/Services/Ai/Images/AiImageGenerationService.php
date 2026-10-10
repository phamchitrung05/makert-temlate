<?php

namespace App\Services\Ai\Images;

use App\Actions\Media\UploadMediaAssetAction;
use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Exceptions\AiImportException;
use App\Models\MediaAsset;
use App\Services\Ai\Contracts\AiImageProviderContract;
use App\Services\Ai\Providers\Transport\AiConnection;
use Illuminate\Http\UploadedFile;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đưa kết quả image provider qua Media Library hiện tại.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: __construct(), generate(), upload().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * INPUT: connection/prompt/actor; OUTPUT: asset chưa attach vào Post.
 * SIDE EFFECT: gọi adapter, tạo file tạm và upload; không tự publish hoặc đổi Post.
 * EXCEPTION/TRANSACTION: lỗi provider/storage an toàn, không retry sau upload
 * thất bại để tránh lặp request tính phí; uploader tự quản lý transaction.
 * =====================================================================
 */
final class AiImageGenerationService
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận adapter ảnh và Media Library uploader qua dependency injection
     * =====================================================================
     * INPUT: AiImageProviderContract và UploadMediaAssetAction.
     * OUTPUT: Service sẵn sàng phục vụ image run.
     * SIDE EFFECT: Chỉ giữ dependencies; không gọi model hoặc ghi file.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function __construct(
        private readonly AiImageProviderContract $provider,
        private readonly UploadMediaAssetAction $uploader,
    ) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Gọi model ảnh rồi đưa kết quả vào Media Library
     * =====================================================================
     * INPUT: Connection snapshot, prompt, actor và metadata ảnh.
     * OUTPUT: MediaAsset mới, chưa attach vào Post.
     * SIDE EFFECT: Gọi provider qua contract rồi upload media.
     * EXCEPTION/TRANSACTION: Lỗi provider/storage thành AiImportException; không giữ transaction qua HTTP.
     * =====================================================================
     */
    public function generate(AiConnection $connection, string $prompt, int $actorId, string $title, ?string $altText = null): MediaAsset
    {
        $binary = $this->provider->generate($connection, $prompt);

        return $this->upload($binary, $actorId, $title, $altText);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra bytes ảnh và tái sử dụng pipeline upload hiện tại
     * =====================================================================
     * INPUT: Bytes chưa được tin cậy, actor/title/alt text.
     * OUTPUT: MediaAsset đã qua validator/uploader của Media Library.
     * SIDE EFFECT: Tạo file tạm, upload và luôn dọn file trong finally.
     * EXCEPTION/TRANSACTION: Từ chối MIME/size sai; uploader tự quản lý transaction, không gọi lại model khi storage lỗi.
     * =====================================================================
     */
    private function upload(string $binary, int $actorId, string $title, ?string $altText): MediaAsset
    {
        $imageInfo = @getimagesizefromstring($binary);
        if ($imageInfo === false || strlen($binary) > (int) config('ai.import.max_image_bytes', 10 * 1024 * 1024)) {
            throw new AiImportException('Provider trả ảnh không hợp lệ hoặc vượt giới hạn.', 'AI_IMAGE_INVALID');
        }
        $mime = strtolower((string) ($imageInfo['mime'] ?? ''));
        $extension = match ($mime) {
            'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/png' => 'png',
            default => throw new AiImportException('Provider trả định dạng ảnh không được hỗ trợ.', 'AI_IMAGE_INVALID'),
        };
        $path = tempnam(sys_get_temp_dir(), 'ai-image-');
        if ($path === false) {
            throw new AiImportException('Không thể lưu ảnh tạm.', 'AI_IMAGE_STORAGE');
        }
        try {
            if (file_put_contents($path, $binary) === false) {
                throw new AiImportException('Không thể lưu ảnh tạm.', 'AI_IMAGE_STORAGE');
            }
            $file = new UploadedFile($path, 'ai-generated.'.$extension, $mime, null, true);

            return $this->uploader->handle($file, MediaAssetKind::Image, $title ?: 'AI generated image', $actorId, MediaAssetVisibility::Public, $altText);
        } catch (AiImportException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new AiImportException('Ảnh đã được provider xử lý nhưng không thể đưa vào thư viện. Kiểm tra lưu trữ trước khi tạo lại.', 'AI_IMAGE_STORAGE');
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
