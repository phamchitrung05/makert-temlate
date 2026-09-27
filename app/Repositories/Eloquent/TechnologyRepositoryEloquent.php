<?php

namespace App\Repositories\Eloquent;

use App\Models\Technology;
use App\Repositories\Contracts\TechnologyRepositoryInterface;
use App\Validators\TechnologyValidator;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Eloquent\BaseRepository;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Triển khai truy cập dữ liệu công nghệ trên Eloquent
 * =====================================================================
 *
 * Implementation của TechnologyRepositoryInterface, cung cấp CRUD chuẩn của
 * gói kèm RequestCriteria cho tìm kiếm và sắp xếp.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - model(): trả về Eloquent model mà repository quản lý
 * - boot(): đăng ký RequestCriteria mặc định cho mọi truy vấn
 * - validator(): trả về TechnologyValidator cho create/update
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : attributes cho create/update, tham số truy vấn cho read
 * - OUTPUT: Technology, Collection hoặc Paginator
 * =====================================================================
 */
class TechnologyRepositoryEloquent extends BaseRepository implements TechnologyRepositoryInterface
{
    /**
     * @var array<string, string>
     */
    protected $fieldSearchable = [
        'name' => 'like',
        'type',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo Eloquent model mà repository quản lý
     * =====================================================================
     *
     * OUTPUT:
     * - string: Technology::class
     */
    public function model()
    {
        return Technology::class;
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
     * - string: TechnologyValidator::class
     */
    public function validator()
    {
        return TechnologyValidator::class;
    }
}
