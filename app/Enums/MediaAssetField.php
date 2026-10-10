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
    case PostGallery = 'post.gallery';
    // Chỉ giữ để đọc dữ liệu/rollback cũ; không còn là field được phép attach.
    case PostContentImages = 'post.content_images';
    case PostOgImage = 'post.og_image';
    case CategoryThumbnail = 'category.thumbnail';
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
        return array_values(array_filter(array_column(self::cases(), 'value'),
            fn (string $field): bool => $field !== self::PostContentImages->value));
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
            'post.gallery' => 'Post image gallery',
            'post.og_image' => 'Post Open Graph image',
            'category.thumbnail' => 'Category thumbnail',
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
            self::PostGallery,
            self::PostContentImages,
            self::PostOgImage,
            self::CategoryThumbnail,
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
            self::PostGallery,
            self::PostContentImages,
            self::ResourcePreview,
            self::ResourceVersionDocumentation => true,
            self::PostThumbnail,
            self::PostOgImage,
            self::CategoryThumbnail,
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
