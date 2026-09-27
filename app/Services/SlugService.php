<?php

namespace App\Services;

use App\Models\Slug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Sinh và duy trì slug tập trung cho model có URL public
 * =====================================================================
 *
 * Service này giữ đúng ba bất biến của bảng `slugable`:
 * 1. Mỗi model chỉ có một slug `is_primary = true` cho mỗi locale.
 * 2. Slug cũ không bị xóa, giữ `is_primary = false` để redirect 301.
 * 3. Trùng slug trong cùng morph type và locale được giải quyết bằng hậu tố
 *    `-2`, `-3`...
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - syncForModel(): tạo hoặc cập nhật slug primary cho một model
 * - resolveBySlug(): tìm model theo slug kèm locale
 * - isPrimarySlug(): kiểm tra slug có còn là bản primary hiện tại
 * - makeUniqueSlug(): sinh slug không trùng bằng hậu tố tăng dần
 * - demoteExistingSlugs(): hạ cấp các slug primary cũ
 * - slugRelation(): lấy quan hệ morphMany slugs từ model
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Model có quan hệ slugs(), chuỗi nguồn slug và locale tuỳ chọn
 * - OUTPUT: Slug vừa tạo/cập nhật, hoặc Model|null khi resolve
 *
 * EXCEPTION/TRANSACTION:
 * - Không tự mở transaction; caller (Action) phải bọc trong transaction
 *   để việc đổi slug và việc đổi tên model là nguyên tử
 * =====================================================================
 */
class SlugService
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Đồng bộ slug primary cho model theo locale hiện tại
     * =====================================================================
     *
     * INPUT:
     * - $model: model đã tồn tại và có quan hệ slugs()
     * - $source: chuỗi nguồn để sinh slug, thường là title hoặc name
     * - $locale: locale đích, mặc định lấy từ config app
     *
     * OUTPUT:
     * - Slug: bản ghi slug vừa tạo hoặc cập nhật
     *
     * SIDE EFFECT:
     * - Hạ cấp tất cả slug primary cũ của cùng locale
     * - INSERT hoặc UPDATE bản ghi slug trong bảng `slugable`
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; caller chịu trách nhiệm
     */
    public function syncForModel(Model $model, string $source, ?string $locale = null): Slug
    {
        $locale ??= (string) config('app.locale');
        $relation = $this->slugRelation($model);
        $base = $this->makeUniqueSlug($model, $source, $locale);

        $this->demoteExistingSlugs($relation, $locale);

        $existing = $relation
            ->where('slug', $base)
            ->where('locale', $locale)
            ->first();

        if ($existing) {
            $existing->forceFill(['is_primary' => true])->save();

            return $existing->refresh();
        }

        $slug = $relation->create([
            'slug' => $base,
            'locale' => $locale,
            'is_primary' => true,
        ]);

        return $slug->refresh();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tìm model theo slug và locale
     * =====================================================================
     *
     * INPUT:
     * - $slug: chuỗi slug lấy từ URL
     * - $locale: locale cần tra, mặc định lấy từ config app
     *
     * OUTPUT:
     * - Model|null: model sở hữu slug, null nếu không tồn tại
     *
     * SIDE EFFECT:
     * - Truy vấn bảng `slugable` rồi nạp model tương ứng
     */
    public function resolveBySlug(string $slug, ?string $locale = null): ?Model
    {
        $locale ??= (string) config('app.locale');

        $record = Slug::query()
            ->where('slug', $slug)
            ->where('locale', $locale)
            ->first();

        return $record?->sluggable;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra một slug có còn là bản primary hiện tại
     * =====================================================================
     *
     * INPUT:
     * - $slug: bản ghi Slug cần kiểm tra
     * - $locale: locale so sánh, mặc định lấy từ config app
     *
     * OUTPUT:
     * - bool: true nếu slug đang là primary của locale
     */
    public function isPrimarySlug(Slug $slug, ?string $locale = null): bool
    {
        $locale ??= (string) config('app.locale');

        return $slug->is_primary && $slug->locale === $locale;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Sinh slug duy nhất cho model trong locale hiện tại
     * =====================================================================
     *
     * INPUT:
     * - $model: model sở hữu slug, dùng để loại trừ chính bản ghi đó khi kiểm tra
     * - $source: chuỗi nguồn để chuyển thành slug
     * - $locale: locale cần kiểm tra trùng
     *
     * OUTPUT:
     * - string: slug không trùng, có hậu tố `-2`, `-3` nếu cần
     */
    private function makeUniqueSlug(Model $model, string $source, string $locale): string
    {
        $base = Str::slug($source);

        if ($base === '') {
            $base = 'item';
        }

        $slug = $base;
        $suffix = 2;

        while ($this->slugExists($model, $slug, $locale)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Hạ cấp mọi slug primary cũ của locale
     * =====================================================================
     *
     * INPUT:
     * - $relation: MorphMany slugs của model
     * - $locale: locale cần hạ cấp
     *
     * SIDE EFFECT:
     * - UPDATE is_primary = false cho các slug primary cùng locale
     */
    private function demoteExistingSlugs(MorphMany $relation, string $locale): void
    {
        $relation
            ->where('locale', $locale)
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra slug đã tồn tại với model khác chưa
     * =====================================================================
     *
     * INPUT:
     * - $model: model hiện tại
     * - $slug: slug cần kiểm tra
     * - $locale: locale của slug
     *
     * OUTPUT:
     * - bool: true nếu còn model khác giữ slug này
     */
    private function slugExists(Model $model, string $slug, string $locale): bool
    {
        return Slug::query()
            ->where('slug', $slug)
            ->where('locale', $locale)
            ->where('sluggable_type', $model->getMorphClass())
            ->where('sluggable_id', '!=', $model->getKey())
            ->exists();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy quan hệ morphMany slugs từ model
     * =====================================================================
     *
     * INPUT:
     * - $model: model cần lấy quan hệ
     *
     * OUTPUT:
     * - MorphMany: quan hệ slugs
     *
     * EXCEPTION:
     * - Ném InvalidArgumentException nếu model chưa khai báo quan hệ slugs()
     */
    private function slugRelation(Model $model): MorphMany
    {
        if (! method_exists($model, 'slugs')) {
            throw new \InvalidArgumentException(
                sprintf('Model [%s] must define a slugs() relation to use SlugService.', $model::class),
            );
        }

        return $model->slugs();
    }
}
