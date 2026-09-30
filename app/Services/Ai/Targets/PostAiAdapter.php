<?php

namespace App\Services\Ai\Targets;

use App\Services\Ai\Contracts\AiTargetAdapterContract;
use App\Services\SeoMetadataService;
use App\Support\SeoRules;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuyển draft AI thành payload Post theo field được chọn.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - key(), toPreview(), toApplyPayload().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : draft đã sanitize và danh sách field người dùng chọn.
 * - OUTPUT: payload whitelist hợp lệ, không nhận status/slug/actor do AI quyết định.
 * - SIDE EFFECT: không ghi database; Apply transaction thuộc controller/action.
 * - EXCEPTION/TRANSACTION: ValidationException khi candidate không còn hợp lệ;
 *   adapter không tự mở transaction.
 * =====================================================================
 */
class PostAiAdapter implements AiTargetAdapterContract
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận service SEO dùng chung với Post CRUD
     * =====================================================================
     * INPUT: SeoMetadataService đã được container resolve.
     * OUTPUT: adapter có thể chuyển SEO output về payload Post.
     * SIDE EFFECT: không ghi database.
     * EXCEPTION/TRANSACTION: không mở transaction; lỗi resolve do container.
     * =====================================================================
     */
    public function __construct(private readonly SeoMetadataService $seo) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả key ổn định để TargetRegistry định tuyến Post
     * =====================================================================
     * INPUT: không có.
     * OUTPUT: chuỗi `post`.
     * SIDE EFFECT: không có.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function key(): string
    {
        return 'post';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa output cho preview mà không tạo Post/slug
     * =====================================================================
     * INPUT: canonical output đã được provider validate.
     * OUTPUT: dữ liệu preview; không có model database.
     * SIDE EFFECT: không ghi database.
     * EXCEPTION/TRANSACTION: không mở transaction; lỗi schema xử lý ở boundary.
     * =====================================================================
     */
    public function toPreview(array $outputs): array
    {
        return $outputs;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuyển field candidate đã chọn thành payload Post whitelist
     * =====================================================================
     * INPUT: outputs AI đã sanitize và danh sách field người dùng chọn.
     * OUTPUT: payload hợp lệ cho CreatePostAction/UpdatePostAction.
     * SIDE EFFECT: không ghi database; không tự đặt status/slug/actor.
     * EXCEPTION/TRANSACTION: ValidationException khi payload không hợp lệ;
     * không tự mở transaction.
     * =====================================================================
     */
    public function toApplyPayload(array $outputs, array $fields): array
    {
        $payload = Arr::only($outputs, array_intersect($fields, ['title', 'excerpt', 'content']));
        if (in_array('content', $fields, true) && array_key_exists('content_html', $outputs)) {
            $payload['content'] = $outputs['content_html'];
        }
        if (in_array('seo', $fields, true)) {
            $payload += $this->seo->fields($outputs);
        }
        if (in_array('taxonomy', $fields, true)) {
            $payload += Arr::only($outputs, ['category_ids', 'tag_ids']);
        }
        if (in_array('thumbnail', $fields, true) && data_get($outputs, 'thumbnail.media_asset_id')) {
            $payload['media'] = ['thumbnail_id' => data_get($outputs, 'thumbnail.media_asset_id')];
        }

        return Validator::make($payload, [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:5000'],
            'content' => ['nullable', 'string'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'distinct', 'exists:tags,id'],
            ...SeoRules::rules(),
            'media' => ['sometimes', 'array'],
            'media.thumbnail_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
    }
}
