<?php

namespace App\Models\Concerns;

use App\Models\Slug;
use App\Services\SlugService;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tự sinh slug tập trung khi model được tạo
 * =====================================================================
 *
 * Trait đăng ký hook `created` để mọi model dùng nó đều có slug primary ngay
 * khi được insert, mà không cần controller hay action gọi tay. Nhờ vậy không
 * tồn tại trạng thái trung gian "resource đã có nhưng chưa có slug".
 *
 * Slug được sinh lại khi cột nguồn thay đổi. Nếu model không đổi tên thì slug
 * cũ giữ nguyên để không phá đường dẫn công khai và SEO. Slug cũ không bị
 * xoá, nó được hạ cấp `is_primary = false` phục vụ redirect 301.
 *
 * Model sử dụng trait phải khai báo hai thành phần:
 * - `slugs(): MorphMany` trả về quan hệ tới App\Models\Slug với morph name
 *   `sluggable` (khớp tên cột `sluggable_type` trong bảng slugable).
 * - `slugSource(): string` trả về giá trị dùng để dựng slug, ví dụ title.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - bootHasSlug(): đăng ký listener created và updated
 * - handleSlugWasCreated(): sinh slug khi model vừa được insert
 * - handleSlugSourceChanged(): sinh lại slug khi cột nguồn đổi
 * - regenerateSlug(): ép sinh lại slug dù cột nguồn có đổi hay không
 * - shouldGenerateSlug(): cho phép model tắt cơ chế sinh slug
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : model có khai báo slugs() và slugSource()
 * - OUTPUT: side effect ghi vào bảng `slugable`
 *
 * EXCEPTION/TRANSACTION:
 * - Không tự mở transaction. Hook chạy trong transaction của caller nếu
 *   caller có mở; nếu không, slug được tạo bằng một INSERT độc lập.
 * =====================================================================
 */
trait HasSlug
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Đăng ký hook sinh slug cho model
     * =====================================================================
     *
     * Sử dụng `boot{TraitName}` chứ không phải `initialize{TraitName}` vì
     * Laravel gọi initializer trên từng instance mới, khiến listener `created`
     * bị đăng ký nhiều lần và sinh slug trùng. `bootHasSlug` chỉ chạy một lần
     * mỗi lần boot model.
     *
     * SIDE EFFECT:
     * - Đăng ký listener cho event created và updated của model
     */
    public static function bootHasSlug(): void
    {
        static::created(function (self $model): void {
            $model->handleSlugWasCreated();
        });

        static::updated(function (self $model): void {
            $model->handleSlugSourceChanged();
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Sinh slug primary khi model vừa được tạo
     * =====================================================================
     *
     * INPUT:
     * - $this: model vừa insert, đã có khóa chính
     *
     * SIDE EFFECT:
     * - INSERT bản ghi slugable với is_primary = true
     */
    private function handleSlugWasCreated(): void
    {
        if (! $this->shouldGenerateSlug()) {
            return;
        }

        $this->regenerateSlug();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Sinh lại slug khi cột nguồn thay đổi
     * =====================================================================
     *
     * INPUT:
     * - $this: model vừa được cập nhật
     *
     * SIDE EFFECT:
     * - INSERT slug mới và hạ cấp slug cũ nếu cột nguồn thực sự đổi
     */
    private function handleSlugSourceChanged(): void
    {
        if (! $this->shouldGenerateSlug()) {
            return;
        }

        if (! $this->wasChanged($this->slugSourceColumn())) {
            return;
        }

        $this->regenerateSlug();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ép sinh slug primary cho locale hiện tại
     * =====================================================================
     *
     * INPUT:
     * - $this: model sở hữu slug
     *
     * OUTPUT:
     * - Slug: bản ghi slug vừa tạo hoặc cập nhật
     *
     * SIDE EFFECT:
     * - Hạ cấp slug primary cũ rồi tạo slug mới trong bảng `slugable`
     */
    public function regenerateSlug(): Slug
    {
        return app(SlugService::class)->syncForModel($this, $this->slugSource());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về giá trị dùng để dựng slug
     * =====================================================================
     *
     * INPUT:
     * - $this: model hiện tại
     *
     * OUTPUT:
     * - string: giá trị nguồn, ví dụ title hoặc name
     */
    abstract public function slugSource(): string;

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về tên cột chứa nguồn slug
     * =====================================================================
     *
     * Cần cho `wasChanged()` vì hàm này so sánh theo tên cột chứ không theo
     * giá trị. Mặc định lấy tên cột khớp với `slugSource()`.
     *
     * OUTPUT:
     * - string: tên cột nguồn slug
     */
    public function slugSourceColumn(): string
    {
        return 'title';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo model dùng trait này có phải sinh slug hay không
     * =====================================================================
     *
     * OUTPUT:
     * - bool: mặc định true; model nội bộ có thể override thành false
     */
    public function shouldGenerateSlug(): bool
    {
        return true;
    }
}
