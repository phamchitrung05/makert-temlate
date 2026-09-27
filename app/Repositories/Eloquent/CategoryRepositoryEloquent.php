<?php

namespace App\Repositories\Eloquent;

use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Validators\CategoryValidator;
use Illuminate\Support\Collection;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Eloquent\BaseRepository;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Triển khai truy cập dữ liệu danh mục trên Eloquent
 * =====================================================================
 *
 * Implementation của CategoryRepositoryInterface. Ngoài CRUD chuẩn của gói,
 * repository cung cấp `roots()` để lấy danh mục cấp cao nhất dùng cho cây
 * điều hướng trong admin form.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - model(): trả về Eloquent model mà repository quản lý
 * - boot(): đăng ký RequestCriteria mặc định cho mọi truy vấn
 * - validator(): trả về CategoryValidator cho create/update
 * - roots(): lấy danh mục cấp cao nhất theo sort_order
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : attributes cho create/update, tham số truy vấn cho read
 * - OUTPUT: Category, Collection hoặc Paginator
 * =====================================================================
 */
class CategoryRepositoryEloquent extends BaseRepository implements CategoryRepositoryInterface
{
    /**
     * @var array<string, string>
     */
    protected $fieldSearchable = [
        'name' => 'like',
        'status',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo Eloquent model mà repository quản lý
     * =====================================================================
     *
     * OUTPUT:
     * - string: Category::class
     */
    public function model()
    {
        return Category::class;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đăng ký RequestCriteria cho mọi truy vấn của repository
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Push RequestCriteria vào hàng đợi criteria của repository
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
     * - string: CategoryValidator::class
     */
    public function validator()
    {
        return CategoryValidator::class;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy danh mục cấp cao nhất theo thứ tự sắp xếp
     * =====================================================================
     *
     * OUTPUT:
     * - Collection: các Category có parent_id null
     */
    public function roots(): Collection
    {
        return $this->model->roots()->get();
    }
}
