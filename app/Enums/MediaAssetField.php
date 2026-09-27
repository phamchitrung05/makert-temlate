<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Định nghĩa field nghiệp vụ được phép dùng MediaAsset
 * =====================================================================
 *
 * Enum khóa contract giữa picker, API attach và bảng media_asset_usages. Prefix
 * trước dấu chấm là morph alias của model sử dụng; phần còn lại là field trong
 * domain đó. Model chưa tồn tại (ví dụ Post) vẫn được khai báo bằng alias để
 * contract không thay đổi khi domain được triển khai ở task sau.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - values(): danh sách field hợp lệ
 * - options(): nhãn hiển thị cho picker/admin
 * - kind(): kind asset mà field chấp nhận
 * - allowsMultiple(): field có cho phép nhiều asset hay không
 * - linkableMorphAlias(): alias dùng cho linkable_type
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : field string từ request hoặc picker
 * - OUTPUT: MediaAssetField tương ứng hoặc null khi tryFrom thất bại
 * =====================================================================
 */
enum MediaAssetField: string
{
    case PostThumbnail = 'post.thumbnail';
    case PostContentImages = 'post.content_images';
    case ResourceCover = 'resource.cover';
    case ResourcePreview = 'resource.preview';
    case ResourceVersionPackage = 'resource_version.package';
    case ResourceVersionDocumentation = 'resource_version.documentation';

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về toàn bộ field được phép attach
     * =====================================================================
     *
     * OUTPUT:
     * - list<string>: danh sách dùng cho validation rule `in:...`
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về nhãn hiển thị cho field
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, string>: field => nhãn picker/admin
     */
    public static function options(): array
    {
        return [
            'post.thumbnail' => 'Post thumbnail',
            'post.content_images' => 'Post content images',
            'resource.cover' => 'Resource cover',
            'resource.preview' => 'Resource preview',
            'resource_version.package' => 'Resource package',
            'resource_version.documentation' => 'Resource documentation',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác định kind asset mà field chấp nhận
     * =====================================================================
     *
     * OUTPUT:
     * - MediaAssetKind: kind được backend dùng để validate attach
     */
    public function kind(): MediaAssetKind
    {
        return match ($this) {
            self::PostThumbnail,
            self::PostContentImages,
            self::ResourceCover,
            self::ResourcePreview => MediaAssetKind::Image,
            self::ResourceVersionPackage => MediaAssetKind::Archive,
            self::ResourceVersionDocumentation => MediaAssetKind::Document,
        };
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác định field có cho phép nhiều asset hay không
     * =====================================================================
     *
     * OUTPUT:
     * - bool: true cho gallery/content images hoặc documentation nhiều file
     */
    public function allowsMultiple(): bool
    {
        return match ($this) {
            self::PostContentImages,
            self::ResourcePreview,
            self::ResourceVersionDocumentation => true,
            self::PostThumbnail,
            self::ResourceCover,
            self::ResourceVersionPackage => false,
        };
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy morph alias của model liên kết
     * =====================================================================
     *
     * OUTPUT:
     * - string: alias dùng trong cột `media_asset_usages.linkable_type`
     */
    public function linkableMorphAlias(): string
    {
        return (string) str($this->value)->before('.');
    }
}
