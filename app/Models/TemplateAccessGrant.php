<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Capability token hash cho preview/download ghim build/version
 * CÁC HÀM/METHOD TRONG FILE: casts(): dates lifecycle.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): identity/capability -> grant; không raw token.
 * =====================================================================
 */
class TemplateAccessGrant extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    /** Input: no args. Output: owning ResourceVersion relation. */
    public function resourceVersion(): BelongsTo
    {
        return $this->belongsTo(ResourceVersion::class);
    }

    /** Input: DB values. Output: dates cho expire/revoke/count once. */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
