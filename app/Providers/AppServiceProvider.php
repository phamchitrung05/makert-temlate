<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\Resource;
use App\Models\ResourceVersion;
use App\Models\Tag;
use App\Models\Technology;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Đăng ký các binding dùng chung của ứng dụng
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Ghi binding vào container; không truy cập database
     * =====================================================================
     */
    public function register(): void
    {
        //
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi động các cấu hình runtime của ứng dụng
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Đăng ký morph map cho các cột polymorphic dùng chung
     * =====================================================================
     */
    public function boot(): void
    {
        $this->enforceMorphMap();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khóa cột polymorphic chỉ lưu alias thay vì FQCN
     * =====================================================================
     *
     * Các bảng `slugable`, `media`, `categorizables`, `taggables` và
     * `activity_log` đều lưu morph key dưới dạng chuỗi. enforceMorphMap bảo
     * đảm mọi giá trị ghi xuống đều là alias đã khai báo ở đây, nên dữ liệu
     * không phụ thuộc namespace và một model không khai báo sẽ bị lỗi ngay.
     *
     * SIDE EFFECT:
     * - Đăng ký alias cho 9 model; các alias cũ đã ghi vào database phải
     *   được migrate riêng nếu có
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; Relation::enforceMorphMap chỉ ghi vào registry
     * =====================================================================
     */
    private function enforceMorphMap(): void
    {
        Relation::enforceMorphMap([
            'resource' => Resource::class,
            'resource_version' => ResourceVersion::class,
            'media_asset' => MediaAsset::class,
            'media_asset_usage' => MediaAssetUsage::class,
            'category' => Category::class,
            'tag' => Tag::class,
            'technology' => Technology::class,
            'user' => User::class,
            'customer' => Customer::class,
        ]);
    }
}
