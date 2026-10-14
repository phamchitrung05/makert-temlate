<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Share preview/download theo version cố định và token hash
 * CÁC HÀM/METHOD TRONG FILE: casts(): expiry/revocation.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): capability/expiry -> share link có thể revoke.
 * =====================================================================
 */
class TemplateShareLink extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    /** Input: no args. Output: owning ResourceVersion relation. */
    public function resourceVersion(): BelongsTo
    {
        return $this->belongsTo(ResourceVersion::class);
    }

    /** Input: DB dates. Output: Carbon dates dùng khi kiểm mỗi request. */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
