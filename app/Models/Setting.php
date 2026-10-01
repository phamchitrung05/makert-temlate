<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Setting key-value có group/type, tái sử dụng cho cấu hình tương lai.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: casts().
 * INPUT: giá trị đã validate.
 * OUTPUT: typed JSON value; secret provider được lưu riêng trong AiProvider.
 * SIDE EFFECT: Ghi DB khi caller save.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
class Setting extends Model
{
    protected $fillable = ['group', 'key', 'type', 'value', 'updated_by'];

    /**
     * =====================================================================
     * CHỨC NĂNG: giá trị scalar/array đã decode.
     * =====================================================================
     * INPUT: JSON trong DB.
     * OUTPUT: giá trị scalar/array đã decode.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
