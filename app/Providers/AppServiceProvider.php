<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\Post;
use App\Models\Resource;
use App\Models\ResourceVersion;
use App\Models\Tag;
use App\Models\Technology;
use App\Models\User;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Registries\PromptRegistry;
use App\Services\Ai\Registries\ProviderRegistry;
use App\Services\Ai\Registries\SchemaRegistry;
use App\Services\Ai\Registries\TargetRegistry;
use App\Services\Ai\StructuredAiProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đăng ký binding AI registry/provider và morph map của ứng dụng.
 * =====================================================================
 *
 * Provider là điểm composition root: các service AI được bind một lần vào
 * container, còn morph alias được khóa trước khi model ghi quan hệ polymorphic.
 * Không đặt business rule hoặc truy vấn database trong provider.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - register(): bind provider contract và các registry singleton.
 * - boot(): kích hoạt morph map.
 * - enforceMorphMap(): khai báo alias/model cho quan hệ polymorphic.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : service container và danh sách model domain.
 * - OUTPUT: binding/morph registry sẵn sàng cho request, job và model.
 * - SIDE EFFECT: thay đổi container và registry toàn cục của Laravel.
 * =====================================================================
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Đăng ký các binding dùng chung của ứng dụng
     * =====================================================================
     *
     * INPUT:
     * - Không nhận tham số; dùng container của Laravel.
     * OUTPUT:
     * - Không trả giá trị; các contract/registry được resolve từ container.
     * SIDE EFFECT:
     * - Ghi binding vào container; không truy cập database.
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; lỗi binding phát hiện khi resolve dependency.
     * =====================================================================
     */
    public function register(): void
    {
        $this->app->singleton(AiProviderContract::class, StructuredAiProvider::class);
        $this->app->singleton(PromptRegistry::class);
        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(TargetRegistry::class);
        $this->app->singleton(SchemaRegistry::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi động các cấu hình runtime của ứng dụng
     * =====================================================================
     *
     * INPUT:
     * - Không nhận tham số; sử dụng danh sách alias khai báo trong provider.
     * OUTPUT:
     * - Không trả giá trị; runtime có morph map ổn định.
     * SIDE EFFECT:
     * - Đăng ký morph map cho các cột polymorphic dùng chung.
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; alias không hợp lệ sẽ ném exception từ Laravel.
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
            'post' => Post::class,
            'category' => Category::class,
            'tag' => Tag::class,
            'technology' => Technology::class,
            'user' => User::class,
            'customer' => Customer::class,
        ]);
    }
}
