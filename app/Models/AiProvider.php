<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Connection AI có secret mã hóa và model catalog riêng.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: casts(), models().
 * INPUT: metadata server-side.
 * OUTPUT: Eloquent model; API key không được serialize hoặc log.
 * SIDE EFFECT: Ghi DB khi caller save.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
class AiProvider extends Model
{
    protected $attributes = ['request_timeout' => 120];

    protected $fillable = [
        'key', 'name', 'kind', 'driver', 'base_url', 'api_key', 'discovery_mode', 'request_timeout',
        'is_active', 'test_status', 'test_message', 'last_tested_at', 'last_synced_at',
    ];

    protected $hidden = ['api_key'];

    /**
     * =====================================================================
     * CHỨC NĂNG: casts bảo vệ secret và chuẩn hóa status/date.
     * =====================================================================
     * INPUT: không có.
     * OUTPUT: casts bảo vệ secret và chuẩn hóa status/date.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted', 'is_active' => 'boolean', 'request_timeout' => 'integer',
            'last_tested_at' => 'datetime', 'last_synced_at' => 'datetime',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: quan hệ catalog; query chỉ chạy khi đọc.
     * =====================================================================
     * INPUT: provider đã hydrate.
     * OUTPUT: quan hệ catalog; query chỉ chạy khi đọc.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }
}
