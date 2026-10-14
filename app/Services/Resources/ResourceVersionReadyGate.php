<?php

namespace App\Services\Resources;

use App\Enums\ResourceType;
use App\Enums\ResourceVersionTemplateProcessingStatus;
use App\Models\MediaAsset;
use App\Models\ResourceVersion;
use App\Models\ResourceVersionTemplate;
use App\Services\ResourceVersionPackageGuard;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm tra mọi điều kiện trước khi version được ready/promote
 * =====================================================================
 *
 * Gate dùng chung cho MarkResourceVersionReadyAction và
 * PromoteResourceVersionAction. Package luôn được đọc lại qua guard; template
 * phải có processing ready, screen/entry hợp lệ, checksum khớp và artifact
 * private còn tồn tại. Gate không thay đổi trạng thái version.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận package guard và artifact service.
 * - assert(): xác minh package và template release gate.
 * - templateGate(): kiểm snapshot/template artifact của resource type template.
 * - checksum(): lấy checksum hiện tại từ media hoặc đọc file private.
 * - reject(): tạo ValidationException bounded cho API.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ResourceVersion đã lock và package usage hiện tại.
 * - OUTPUT: MediaAsset archive private/clean nếu mọi gate đạt.
 * - SIDE EFFECT: chỉ đọc/lock template metadata; không đổi lifecycle.
 * - EXCEPTION/TRANSACTION: ValidationException khi version chưa đủ điều kiện;
 *   caller chịu transaction/rollback.
 * =====================================================================
 */
final class ResourceVersionReadyGate
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận các guard dùng chung của version/template
     * =====================================================================
     * INPUT: package guard và processing service từ container.
     * OUTPUT: gate sẵn sàng kiểm tra; không query/ghi DB.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function __construct(
        private readonly ResourceVersionPackageGuard $packageGuard,
        private readonly TemplateVersionProcessingService $templateProcessing,
    ) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác minh version đủ điều kiện ready hoặc promote
     * =====================================================================
     * INPUT: ResourceVersion đang được caller lock.
     * OUTPUT: MediaAsset package archive private/clean hiện tại.
     * SIDE EFFECT: Đọc usage, media, template và artifact; không đổi DB.
     * EXCEPTION/TRANSACTION: ValidationException nếu gate bất kỳ thất bại.
     * =====================================================================
     */
    public function assert(ResourceVersion $version): MediaAsset
    {
        $version->loadMissing('resource');
        $package = $this->packageGuard->cleanPackageFor($version);

        if ($version->resource?->type !== ResourceType::Template) {
            return $package;
        }

        $this->templateGate($version, $package);

        return $package;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm processing snapshot và artifact của template
     * =====================================================================
     * INPUT: ResourceVersion template và package đã qua scan guard.
     * OUTPUT: Không trả giá trị khi snapshot đã sẵn sàng phát hành.
     * SIDE EFFECT: Lock row template để gate không đọc giữa mutation.
     * EXCEPTION/TRANSACTION: ValidationException cho processing/screen/artifact
     *   thiếu hoặc source checksum không còn khớp.
     * =====================================================================
     */
    private function templateGate(ResourceVersion $version, MediaAsset $package): void
    {
        $template = ResourceVersionTemplate::query()
            ->where('resource_version_id', $version->getKey())
            ->lockForUpdate()
            ->first();

        if (! $template) {
            $this->reject('template', 'Template version chưa có metadata xử lý.');
        }

        if ($template->processing_status !== ResourceVersionTemplateProcessingStatus::Ready) {
            $this->reject('template', 'Template phải xử lý xong trước khi version được ready.');
        }

        $screens = $template->screens()->get();
        if ($screens->isEmpty()) {
            $this->reject('template', 'Template phải có ít nhất một screen trước khi ready.');
        }

        if (! filled($template->entry_screen_slug)
            || ! $screens->contains(fn ($screen): bool => $screen->slug === $template->entry_screen_slug)) {
            $this->reject('template', 'Template phải có entry screen hợp lệ trước khi ready.');
        }

        $manifest = (array) $template->manifest_json;
        if (($manifest['requires_entry_selection'] ?? false) === true) {
            $this->reject('template', 'Template vẫn cần chọn entry screen trước khi ready.');
        }

        if (! filled($template->preview_path)
            || ! $template->processed_at
            || ! $this->templateProcessing->hasPublishedArtifact($template)) {
            $this->reject('template', 'Artifact template không còn sẵn sàng; hãy xử lý lại package.');
        }

        foreach ($screens as $screen) {
            if (! in_array($screen->source_path, (array) ($manifest['files'] ?? []), true)) {
                $this->reject('template', 'Screen không khớp danh sách file đã xử lý.');
            }
            try {
                app(TemplatePreviewService::class)->file($template, $screen->source_path);
            } catch (\Throwable) {
                $this->reject('template', 'Artifact thiếu file screen; hãy rebuild package.');
            }
        }

        $media = $package->getFirstMedia('library');
        $checksum = $media ? $this->checksum($media) : null;
        if ((int) $template->source_media_id !== (int) $package->getKey()
            || ! is_string($checksum)
            || $checksum === ''
            || ! hash_equals((string) $template->package_sha256, $checksum)) {
            $this->reject('template', 'Template snapshot không còn khớp package hiện tại; hãy xử lý lại.');
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy checksum hiện tại của media package
     * =====================================================================
     * INPUT: Spatie media item thuộc archive private.
     * OUTPUT: SHA-256 string hoặc null nếu không đọc được file.
     * SIDE EFFECT: Có thể đọc file private khi custom property thiếu.
     * EXCEPTION/TRANSACTION: Không ném lỗi; caller biến null thành validation.
     * =====================================================================
     */
    private function checksum(Media $media): ?string
    {
        $custom = (string) $media->getCustomProperty('checksum_sha256');
        if ($custom !== '') {
            return $custom;
        }

        $path = $media->getPath();
        if (! is_file($path)) {
            return null;
        }

        $hash = hash_file('sha256', $path);

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ném lỗi gate theo field public-safe
     * =====================================================================
     * INPUT: field và message bounded do action chọn.
     * OUTPUT: Không trả giá trị.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: ValidationException; caller rollback transaction.
     * =====================================================================
     */
    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
