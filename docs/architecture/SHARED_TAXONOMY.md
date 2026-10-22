# Hệ thống Slug + Category + Tag dùng chung (Shared Taxonomy)

**Cập nhật:** 22/10/2026
**Áp dụng cho:** mọi tài nguyên có URL public hoặc cần phân loại chung —
Resource (catalog), Post (blog), Category, Tag, Technology, AiImageProfile
(Visual Style) và bất kỳ model nào thêm về sau cần slug/category/tag.

**Morph map:** `AiImageProfile` đăng ký alias `visual_style` trong
`AppServiceProvider::enforceMorphMap()` — bắt buộc khi model dùng morph
relation (HasSlug/categorizables/taggables/media_asset_usages), nếu thiếu sẽ ném
`ClassMorphViolationException`.

> **Quy tắc cho AI/developer:** khi một model mới cần slug, gắn category hoặc
> gắn tag, **dùng lại** hệ thống polymorphic dưới đây — không tạo cột
> `slug`/`category_id`/`tag_id` riêng trên bảng của model đó.

---

## 1. Ba bảng trung tâm + pivot

| Bảng | Model | Vai trò |
| --- | --- | --- |
| `slugable` | `App\Models\Slug` | slug tập trung cho mọi model có URL public |
| `categories` | `App\Models\Category` | danh mục dạng cây (`parent_id`) |
| `tags` | `App\Models\Tag` | tag phẳng |
| `categorizables` | pivot polymorphic | gắn Category ↔ Resource/Post/… |
| `taggables` | pivot polymorphic | gắn Tag ↔ Resource/Post/… |

**Lưu ý chính tả:** bảng slug tên `slugable` và cột `sluggable_type` /
`sluggable_id` (hai chữ **g** — "sluggable", không phải "slugable"). Mọi quan
hệ morph khai báo morph name `sluggable` để khớp cột.

---

## 2. Slug — trait `HasSlug` + `SlugService`

### 2.1. Cách model dùng

Thêm trait và khai báo 3 thành phần bắt buộc:

```php
use App\Models\Concerns\HasSlug;
use App\Models\Slug;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Post extends Model
{
    use HasSlug;

    public function slugs(): MorphMany
    {
        return $this->morphMany(Slug::class, 'sluggable');
    }

    public function slugSource(): string        // giá trị sinh slug
    {
        return (string) $this->title;
    }

    public function slugSourceColumn(): string  // cột nguồn để wasChanged() đo
    {
        return 'title';
    }
}
```

`HasSlug` đăng ký hook `created`/`updated` một lần (qua `bootHasSlug`, không
phải `initialize`) — slug được sinh ngay khi insert và sinh lại khi
`slugSourceColumn()` đổi, không để model tồn tại ở trạng thái "có nhưng chưa
có slug".

### 2.2. Bất biến do `SlugService` giữ

- Mỗi model **một slug `is_primary = true` cho mỗi locale**.
- Slug cũ **không bị xóa** — hạ `is_primary = false` để redirect 301.
- Trùng slug trong cùng `sluggable_type` + `locale` → hậu tố `-1`, `-2`…
- `syncForModel()` mở savepoint + `lockForUpdate`, retry unique tối đa 5 lần.

### 2.3. API hữu dụng

| Method | Dùng khi |
| --- | --- |
| `SlugService::syncForModel($model, $source)` | tạo/cập nhật slug primary |
| `SlugService::previewForModel($model, $source)` | lấy slug khả dụng, **không ghi DB** (admin preview) |
| `SlugService::resolveBySlug($slug)` | tìm model theo slug khi resolve public URL |
| `model->primarySlug()` | slug hiện hành của locale hiện tại |
| `model->regenerateSlug()` | ép sinh lại dù nguồn không đổi |
| `shouldGenerateSlug()` | override `false` để tắt cho model nội bộ |

---

## 3. Category + Tag — gắn qua quan hệ polymorphic

Model không giữ `category_id`/`tag_id` cột riêng — gắn qua pivot `morphToMany`
/`morphedByMany`.

### 3.1. Phía model tài nguyên (ví dụ `Post`, `Resource`)

```php
public function categories(): MorphToMany
{
    return $this->morphToMany(Category::class, 'categorizable')
        ->withPivot('sort_order')   // pivot có sort_order
        ->withTimestamps();
}

public function tags(): MorphToMany
{
    return $this->morphToMany(Tag::class, 'taggable')->withTimestamps();
}
```

### 3.2. Phía taxonomy (Category, Tag) — quan hệ ngược `morphedByMany`

Mỗi loại tài nguyên gắn vào Category/Tag cần **một quan hệ ngược tường minh**:

```php
// Category.php
public function resources(): MorphToMany
{
    return $this->morphedByMany(Resource::class, 'categorizable')
        ->withPivot('sort_order')->withTimestamps();
}
public function posts(): MorphToMany
{
    return $this->morphedByMany(Post::class, 'categorizable')
        ->withPivot('sort_order')->withTimestamps();
}
```

Khi gắn thêm một model mới vào Category/Tag, thêm một quan hệ `morphedByMany`
tương tự trên `Category`/`Tag` để đếm/filter ngược.

### 3.3. Đặc điểm hai taxonomy

- **Category**: cây `parent_id`, `sort_order`, `show_on_menu`, status
  `TaxonomyStatus`, SoftDeletes, `HasSlug` + `HasMediaAssets`.
- **Tag**: phẳng (không cây), status `TaxonomyStatus`, SoftDeletes, `HasSlug`.

Controller taxonomy dùng `TaxonomyController` base (category/tag/technology) —
xem `DEVELOPMENT_GUIDELINES.md` § Laravel.

---

## 4. Checklist khi thêm model có slug/category/tag

1. `use HasSlug` + định nghĩa `slugs()` (morph name `sluggable`),
   `slugSource()`, `slugSourceColumn()`.
2. Gắn Category/Tag qua `morphToMany` tới `categorizable`/`taggable`, không
   tạo cột FK riêng.
3. Thêm `morphedByMany` ngược trên `Category`/`Tag` nếu cần đếm/filter.
4. Slug public resolve bằng `SlugService::resolveBySlug()` — đọc từ
   `slugable`, không query cột `slug` trên model.
5. Preview slug ở admin qua `SlugService::previewForModel()` /
   `SlugPreviewController`, không tự `Str::slug` rồi ghi.

## 4.1. Media — ảnh/file cũng đi qua pipeline chung

Khi một tài nguyên cần **ảnh hoặc file** (thumbnail, cover, reference, preview):

- **Không** lưu `path`/`file` raw trên model — đưa qua
  `UploadMediaAssetAction` → `MediaAsset` + Spatie `media` collection, rồi
  `MediaAssetUsageService::attach()` vào `media_asset_usages` qua
  `HasMediaAssets` trait.
- Thêm case `domain.field` vào `App\Enums\MediaAssetField` (đặt morph alias
  prefix khớp morph map của model), khai `kind()`, `allowsMultiple()`.
- Nguồn chuẩn file là `reference_media_id`/quan hệ `mediaAssets()` — model chỉ
  giữ FK hoặc path cache; file vật lý nằm trong collection `library` của
  MediaAsset (được scan/convert/checksum qua pipeline).
- `VisualStyle` (`AiImageProfile`) là ví dụ đã chuẩn: field
  `visual_style.reference`, `reference_media_id`, `HasMediaAssets` trait.

## 5. Liên kết

- Trait: [HasSlug.php](../../app/Models/Concerns/HasSlug.php) ·
  [HasMediaAssets.php](../../app/Models/Concerns/HasMediaAssets.php)
- Service: [SlugService.php](../../app/Services/SlugService.php)
- Models: [Slug](../../app/Models/Slug.php) · [Category](../../app/Models/Category.php) · [Tag](../../app/Models/Tag.php) · [Resource](../../app/Models/Resource.php) · [Post](../../app/Models/Post.php)
