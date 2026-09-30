<?php

namespace App\Services;

use App\Models\Slug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\UniqueConstraintViolationException;
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
 *    `-1`, `-2`...
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - syncForModel(): tạo hoặc cập nhật slug primary cho một model
 * - previewForModel(): trả slug khả dụng, không ghi database
 * - slugExists(): kiểm tra slug trong cùng type/locale
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
 * - sync mở transaction/savepoint để rollback demote khi trùng; caller vẫn
 *   bọc toàn bộ thay đổi model trong transaction và retry khi deadlock.
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
     * - Mở savepoint, retry unique collision tối đa 5 lần; caller retry deadlock.
     */
    public function syncForModel(Model $model, string $source, ?string $locale = null): Slug
    {
        $locale ??= (string) config('app.locale');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                // Savepoint rollback cả demote khi INSERT bị trùng; locking read
                // nhìn thấy slug vừa được commit bởi request cạnh tranh.
                return $model->getConnection()->transaction(function () use ($model, $source, $locale): Slug {
                    $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
                    $base = $this->makeUniqueSlug($model, $source, $locale, true);
                    $relation = $this->slugRelation($model);
                    $this->demoteExistingSlugs($relation, $locale);
                    $existing = $this->slugRelation($model)->where('slug', $base)->where('locale', $locale)->first();
                    if ($existing) {
                        $existing->forceFill(['is_primary' => true])->save();

                        return $existing->refresh();
                    }

                    return $this->slugRelation($model)->create([
                        'slug' => $base, 'locale' => $locale, 'is_primary' => true,
                    ]);
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 4) {
                    throw $exception;
                }
            }
        }
        throw new \LogicException('Slug retry exhausted.');
    }

    /**
     * Input: model mới hoặc đã lưu, title và locale tùy chọn.
     * Output: slug khả dụng tại thời điểm đọc; không giữ chỗ hoặc ghi dữ liệu.
     */
    public function previewForModel(Model $model, string $source, ?string $locale = null): string
    {
        return $this->makeUniqueSlug($model, $source, $locale ?? (string) config('app.locale'));
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
     * - string: slug không trùng, có hậu tố `-1`, `-2` nếu cần
     */
    private function makeUniqueSlug(Model $model, string $source, string $locale, bool $lock = false): string
    {
        $base = Str::slug($source);

        if ($base === '') {
            $base = 'item';
        }

        $base = rtrim(substr($base, 0, 250), '-');
        $slug = $base;
        $suffix = 1;

        while ($this->slugExists($model, $slug, $locale, $lock)) {
            $ending = '-'.$suffix;
            $slug = rtrim(substr($base, 0, 250 - strlen($ending)), '-').$ending;
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
    private function slugExists(Model $model, string $slug, string $locale, bool $lock = false): bool
    {
        return Slug::query()
            ->where('slug', $slug)
            ->where('locale', $locale)
            ->where('sluggable_type', $model->getMorphClass())
            ->when($model->exists, fn ($query) => $query->where('sluggable_id', '!=', $model->getKey()))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first(['id']) !== null;
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
