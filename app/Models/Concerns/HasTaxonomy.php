<?php

namespace App\Models\Concerns;

use App\Enums\TaxonomyStatus;
use App\Models\Category;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Gắn và đồng bộ Category/Tag dùng chung cho model tài nguyên.
 * =====================================================================
 * Trait gom hai quan hệ polymorphic (categorizables + taggables) thay cho việc
 * viết tay `categories()`/`tags()` lặp lại ở từng model — theo convention
 * HasSlug/HasMediaAssets. Model dùng trait cần đăng ký morph map (xem
 * docs/architecture/SHARED_TAXONOMY.md) để cột *_type lưu alias thay vì FQCN.
 *
 * syncTaxonomyWithNew() xử lý hai loại phân loại trong một lần gọi: id đã tồn
 * tại (AI matched / admin chọn) chỉ cần gắn pivot; tên mới (AI proposed /
 * admin duyệt) được firstOrCreate rồi gắn luôn — so khớp case-insensitive để
 * không tạo trùng khi chỉ khác hoa-thường.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - categories(): MorphToMany Category qua pivot categorizables (+sort_order).
 * - tags(): MorphToMany Tag qua pivot taggables.
 * - syncTaxonomyWithNew(): firstOrCreate tên mới + sync pivot một lần.
 * - firstOrCreateTaxonomy(): resolve id theo tên, tạo khi chưa có (active).
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : model tài nguyên cần phân loại; payload ids + tên mới.
 * - OUTPUT: quan hệ pivot; ids đã đồng bộ khi gọi syncTaxonomyWithNew().
 * - SIDE EFFECT: INSERT categories/tags khi tên mới chưa có + INSERT/DELETE
 *   pivot qua sync(); slug mới tự sinh qua HasSlug của Category/Tag.
 *
 * EXCEPTION/TRANSACTION:
 * - syncTaxonomyWithNew() ghi nhiều bảng — PHẢI gọi trong transaction của
 *   caller; trait không tự mở transaction (theo convention HasSlug).
 * =====================================================================
 */
trait HasTaxonomy
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Liên kết Category dùng chung (categorizables) cho model.
     * =====================================================================
     * Input: model hiện tại. Output: MorphToMany Category, pivot có sort_order.
     * Side effect: chỉ query khi nạp quan hệ.
     * =====================================================================
     */
    public function categories(): MorphToMany
    {
        return $this->morphToMany(Category::class, 'categorizable')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Liên kết Tag dùng chung (taggables) cho model.
     * =====================================================================
     * Input: model hiện tại. Output: MorphToMany Tag qua pivot taggables.
     * Side effect: chỉ query khi nạp quan hệ.
     * =====================================================================
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đồng bộ category/tag — id có sẵn gắn trực tiếp, tên mới tạo rồi gắn.
     * =====================================================================
     * Input: payload ['categories' => [ids], 'tags' => [ids],
     *   'new_categories' => [names], 'new_tags' => [names]] — key nào vắng mặt
     *   thì nhóm đó không đổi (caller chỉ gửi khi muốn thay đổi).
     * Output: ['category_ids' => [ids], 'tag_ids' => [ids]] đã sync (hữu ích
     *   khi caller cần biết id vừa tạo).
     * Side effect: firstOrCreate bản ghi mới status=active (slug tự sinh qua
     *   HasSlug), sync() pivot cho nhóm có mặt trong payload.
     * Exception/Transaction: ghi nhiều bảng — caller PHẢI bọc DB::transaction;
     *   lỗi giữa chừng rollback toàn bộ khi caller đã mở transaction.
     * =====================================================================
     *
     * @return array{category_ids: list<int>, tag_ids: list<int>}
     */
    public function syncTaxonomyWithNew(array $payload): array
    {
        $result = ['category_ids' => [], 'tag_ids' => []];

        if (array_key_exists('categories', $payload) || array_key_exists('new_categories', $payload)) {
            $ids = array_merge(
                array_map('intval', (array) ($payload['categories'] ?? [])),
                $this->firstOrCreateTaxonomy(Category::class, (array) ($payload['new_categories'] ?? [])),
            );
            $this->categories()->sync($ids);
            $result['category_ids'] = $ids;
        }

        if (array_key_exists('tags', $payload) || array_key_exists('new_tags', $payload)) {
            $ids = array_merge(
                array_map('intval', (array) ($payload['tags'] ?? [])),
                $this->firstOrCreateTaxonomy(Tag::class, (array) ($payload['new_tags'] ?? [])),
            );
            $this->tags()->sync($ids);
            $result['tag_ids'] = $ids;
        }

        return $result;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve id theo tên — lấy bản ghi có sẵn hoặc tạo mới.
     * =====================================================================
     * Input: model class (Category|Tag) và danh sách tên.
     * Output: list<int> id theo đúng thứ tự tên; tên rỗng/trùng lặp trong
     *   payload bị lược bỏ.
     * Side effect: INSERT bản ghi status=active khi tên (đã normalize, không
     *   phân biệt hoa-thường) chưa tồn tại; không tạo trùng khi chỉ khác case.
     * =====================================================================
     *
     * @param  class-string<Category|Tag>  $model
     * @return list<int>
     */
    protected function firstOrCreateTaxonomy(string $model, array $names): array
    {
        $ids = [];
        $seen = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '' || mb_strlen($name) > 80) {
                continue;
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $existing = $model::query()->whereRaw('LOWER(name) = ?', [$key])->first();
            $ids[] = $existing?->id ?? $model::query()->create([
                'name' => $name,
                'status' => TaxonomyStatus::Active,
            ])->id;
        }

        return $ids;
    }
}
