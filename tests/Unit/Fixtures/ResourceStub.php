<?php

namespace Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Model giả lập cho unit test BaseResponse
 * =====================================================================
 *
 * Stub này chỉ tồn tại trong test, không liên kết bảng database. Nó cho
 * phép kiểm thử paginated() với dữ liệu thuần mà không cần migration.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - Không có; chỉ khai báo model tối giản
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : mảng attributes từ test
 * - OUTPUT: ResourceStub dùng để bọc JsonResource
 * =====================================================================
 */
class ResourceStub extends Model
{
    /**
     * @var string
     */
    protected $table = 'resources';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
