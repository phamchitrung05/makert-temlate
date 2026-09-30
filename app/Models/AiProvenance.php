<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Audit bất biến nguồn AI của field đã áp dụng vào tài nguyên.
 * CÁC HÀM/METHOD: dùng persistence Eloquent. INPUT: metadata backend đã xác minh.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - Model persistence mặc định; không thêm mutation ngoài fillable audit fields.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : metadata provider/model/prompt/hash do backend đã xác minh.
 * - OUTPUT: lịch sử provenance; không chứa secret hay nội dung đầy đủ.
 * SIDE EFFECT: insert trong transaction Apply, giữ audit khi candidate hết hạn.
 * =====================================================================
 */
class AiProvenance extends Model
{
    protected $fillable = [
        'target_type', 'target_id', 'field', 'run_id', 'provider', 'model',
        'prompt_key', 'prompt_version', 'value_hash', 'applied_by',
    ];
}
