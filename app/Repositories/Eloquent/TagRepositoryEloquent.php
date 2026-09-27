<?php

namespace App\Repositories\Eloquent;

use App\Models\Tag;
use App\Repositories\Contracts\TagRepositoryInterface;
use App\Validators\TagValidator;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Eloquent\BaseRepository;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Triển khai truy cập dữ liệu tag trên Eloquent
 * =====================================================================
 *
 * Implementation của TagRepositoryInterface, cung cấp CRUD chuẩn của gói
 * kèm RequestCriteria cho tìm kiếm và sắp xếp.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - model(): trả về Eloquent model mà repository quản lý
 * - boot(): đăng ký RequestCriteria mặc định cho mọi truy vấn
 * - validator(): trả về TagValidator cho create/update
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : attributes cho create/update, tham số truy vấn cho read
 * - OUTPUT: Tag, Collection hoặc Paginator
 * =====================================================================
 */
class TagRepositoryEloquent extends BaseRepository implements TagRepositoryInterface
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
     * - string: Tag::class
     */
    public function model()
    {
        return Tag::class;
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
     * - string: TagValidator::class
     */
    public function validator()
    {
        return TagValidator::class;
    }
}
