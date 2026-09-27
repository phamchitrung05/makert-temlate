<?php

namespace App\Providers;

use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Repositories\Contracts\ResourceRepositoryInterface;
use App\Repositories\Contracts\TagRepositoryInterface;
use App\Repositories\Contracts\TechnologyRepositoryInterface;
use App\Repositories\Eloquent\CategoryRepositoryEloquent;
use App\Repositories\Eloquent\ResourceRepositoryEloquent;
use App\Repositories\Eloquent\TagRepositoryEloquent;
use App\Repositories\Eloquent\TechnologyRepositoryEloquent;
use Illuminate\Support\ServiceProvider;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Bind hợp đồng repository sang implementation Eloquent
 * =====================================================================
 *
 * Controller chỉ type-hint vào interface trong thư mục Contracts. Provider
 * này nối interface với lớp Eloquent cụ thể, nhờ đó có thể thay implementation
 * trong test mà không phải sửa controller.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - register(): khai báo binding interface sang lớp Eloquent
 * - boot(): không dùng, để trống theo chuẩn service provider
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : các interface trong App\Repositories\Contracts
 * - OUTPUT: binding trong container để Laravel inject được repository
 * =====================================================================
 */
class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Bind interface repository sang implementation Eloquent
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Ghi binding vào container; binding được resolve lazily khi lần đầu
     *   được inject nên chưa truy vấn database tại thời điểm đăng ký
     */
    public function register(): void
    {
        $this->app->bind(ResourceRepositoryInterface::class, ResourceRepositoryEloquent::class);
        $this->app->bind(CategoryRepositoryInterface::class, CategoryRepositoryEloquent::class);
        $this->app->bind(TagRepositoryInterface::class, TagRepositoryEloquent::class);
        $this->app->bind(TechnologyRepositoryInterface::class, TechnologyRepositoryEloquent::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi động cấu hình runtime của repository
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Không có; giữ lại để tuân thủ chữ ký của ServiceProvider
     */
    public function boot(): void
    {
        //
    }
}
