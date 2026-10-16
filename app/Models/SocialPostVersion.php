<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lưu snapshot bất biến của Social Post theo version.
 * =====================================================================
 * Version giữ caption/context/asset/prompt/profile/provider snapshot để
 * regenerate và audit mà không phụ thuộc dữ liệu hiện tại của product.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - casts(): ép version/JSON về kiểu an toàn.
 * - socialPost(): Social Post sở hữu snapshot.
 * - createdBy(): actor hoặc system tạo version.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : snapshot canonical và metadata AI đã được allowlist.
 * - OUTPUT: SocialPostVersion có version_no/hash để truy vết.
 * - SIDE EFFECT: Eloquent lưu snapshot; không gọi provider.
 * =====================================================================
 */
class SocialPostVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'social_post_id', 'social_post_variant_id', 'version_no', 'caption', 'content_json', 'context_json', 'asset_ids_json',
        'prompt_snapshot_json', 'writing_profile_snapshot_json', 'provider_snapshot_json',
        'version_hash', 'reason', 'created_by',
    ];

    /**
     * Ép kiểu version và các snapshot JSON.
     *
     * Input: thuộc tính Eloquent SocialPostVersion.
     * Output: integer/array đúng contract; không ghi database.
     */
    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'content_json' => 'array',
            'context_json' => 'array',
            'asset_ids_json' => 'array',
            'prompt_snapshot_json' => 'array',
            'writing_profile_snapshot_json' => 'array',
            'provider_snapshot_json' => 'array',
        ];
    }

    /**
     * Lấy Social Post sở hữu snapshot.
     *
     * Input: version hiện tại.
     * Output: BelongsTo<SocialPost>; không tự ghi database.
     */
    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class);
    }

    public function socialPostVariant(): BelongsTo
    {
        return $this->belongsTo(SocialPostVariant::class);
    }

    /**
     * Lấy actor tạo snapshot.
     *
     * Input: version hiện tại.
     * Output: BelongsTo<User|null>; system version có thể không có actor.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
