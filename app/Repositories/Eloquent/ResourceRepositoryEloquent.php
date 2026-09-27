<?php

namespace App\Repositories\Eloquent;

use App\Models\Resource;
use App\Repositories\Contracts\ResourceRepositoryInterface;
use App\Repositories\Criteria\PubliclyVisibleResourceCriteria;
use App\Repositories\Criteria\PublishedResourceCriteria;
use App\Repositories\Criteria\ResourceCategoryCriteria;
use App\Validators\ResourceValidator;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Eloquent\BaseRepository;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Triển khai truy cập dữ liệu resource trên Eloquent
 * =====================================================================
 *
 * Lớp này là implementation duy nhất của ResourceRepositoryInterface. Nó
 * cung cấp sẵn RequestCriteria cho tìm kiếm/sắp xếp, validator cho create và
 * update, cùng các helper lọc theo trạng thái, hiển thị và danh mục.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - model(): trả về Eloquent model mà repository quản lý
 * - boot(): đăng ký RequestCriteria mặc định cho mọi truy vấn
 * - validator(): trả về ResourceValidator cho create/update
 * - published(): áp Criteria chỉ giữ resource đã xuất bản
 * - publiclyVisible(): áp Criteria an toàn cho khách chưa đăng nhập
 * - forCategory(): áp Criteria lọc theo danh mục
 * - fieldSearchable: khai báo các cột cho phép tìm kiếm
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : attributes cho create/update, tham số truy vấn cho read
 * - OUTPUT: Resource, Collection hoặc Paginator; create/update ném
 *   ValidatorException khi rule fail
 * =====================================================================
 */
class ResourceRepositoryEloquent extends BaseRepository implements ResourceRepositoryInterface
{
    /**
     * Chỉ các cột này được phép xuất hiện trong tham số `search` của
     * RequestCriteria. Cột ngoài danh sách sẽ bị bỏ qua, tránh việc client
     * dò tên cột tùy ý.
     *
     * @var array<string, string>
     */
    protected $fieldSearchable = [
        'title' => 'like',
        'short_description' => 'like',
        'code',
        'type',
        'status',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo Eloquent model mà repository quản lý
     * =====================================================================
     *
     * OUTPUT:
     * - string: Resource::class
     *
     * SIDE EFFECT:
     * - Không có; BaseRepository gọi method này một lần trong constructor
     */
    public function model()
    {
        return Resource::class;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đăng ký RequestCriteria cho mọi truy vấn của repository
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Push RequestCriteria vào hàng đợi criteria; đọc tham số `search`,
     *   `searchFields`, `orderBy`, `sortedBy`, `with` từ request hiện tại
     */
    public function boot()
    {
        $this->pushCriteria(app(RequestCriteria::class));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về validator cho create và update
     * =====================================================================
     *
     * OUTPUT:
     * - string: ResourceValidator::class
     */
    public function validator()
    {
        return ResourceValidator::class;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chỉ giữ resource đã xuất bản
     * =====================================================================
     *
     * OUTPUT:
     * - static: chính repository, đã gắn PublishedResourceCriteria
     */
    public function published(): static
    {
        $this->pushCriteria(app(PublishedResourceCriteria::class));

        return $this;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chỉ giữ resource mà khách chưa đăng nhập được phép xem
     * =====================================================================
     *
     * OUTPUT:
     * - static: chính repository, đã gắn PubliclyVisibleResourceCriteria
     */
    public function publiclyVisible(): static
    {
        $this->pushCriteria(app(PubliclyVisibleResourceCriteria::class));

        return $this;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc resource theo danh mục
     * =====================================================================
     *
     * INPUT:
     * - $categoryId: id danh mục, null nghĩa là không lọc
     *
     * OUTPUT:
     * - static: chính repository, đã gắn ResourceCategoryCriteria
     */
    public function forCategory(?int $categoryId): static
    {
        $this->pushCriteria(app(ResourceCategoryCriteria::class, ['categoryId' => $categoryId]));

        return $this;
    }
}
