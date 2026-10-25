# Kế hoạch Visual Style Engine — Style DNA + Shot Recipes + PromptBuilder

**Cập nhật:** 21/10/2026
**Trạng thái:** DRAFT — chờ chủ dự án duyệt trước khi triển khai.
**Phạm vi đợt này:** chỉ phần **Visual Style DNA + Shot Recipes + PromptBuilder
nhiều tầng + generation pipeline**, độc lập với Content Planner (plan riêng).
**Nguồn:** đối chiếu `visual-style-engine-master-prompt.md` với codebase thật;
bỏ phần Style Library UI rộng và commerce, giữ lõi kỹ thuật.

## 1. Mục tiêu

Thay `AiImageProfile` hiện tại (1 ảnh → 1 chuỗi `prompt` phẳng) bằng mô hình
**Style DNA có cấu trúc + nhiều Shot Recipes**, để:

- 1 phong cách dùng lại cho nhiều sản phẩm/bài đăng, chỉ đổi góc chụp.
- Prompt cuối được **build động** từ DNA + recipe + sản phẩm + constraints —
  không lưu prompt tĩnh.
- Nhiều ảnh trong 1 batch giữ **DNA đồng nhất** nhưng **khác góc** nhờ recipes.
- Linh hoạt mở rộng: thêm thuộc tính (brand kit, mood board...) = thêm 1
  prompt layer, không viết lại pipeline.

## 2. Khái niệm cốt lõi

```
Style DNA (bất biến của phong cách)          ── lưu JSON có schema_version
        +
Shot Recipe (góc/bố cục cụ thể, override)   ── bảng riêng, 1 style nhiều recipe
        +
Product Context (sản phẩm lần này, ad-hoc)   ── DTO, không cần entity Product
        +
User settings (số ảnh, ratio, ghi chú)
        ▼
PromptBuilder (layers) → prompt cuối → Image Provider
```

Không gửi JSON DNA thô cho image model — `PromptBuilder` render ra ngôn ngữ
tự nhiên trước khi gọi provider.

## 3. Style DNA — schema + lưu trữ

**Phân loại theo taxonomy dùng chung.** Visual Style gắn Category/Tag qua
`categorizables`/`taggables` (xem `SHARED_TAXONOMY.md`), không dùng cột
`category`/`tag` riêng. Khi phân tích, service gửi `existing_categories` +
`existing_tags` (tên + id bounded, từ bảng `categories`/`tags`) làm input cho
Vision model; AI trả `taxonomy_suggestion {action, category, tags,
new_category, new_tags, confidence, reason}` để:

- `match_existing` → gắn category/tag **hiện có** vào pivot (auto-attach), và
  `dna_json.taxonomy_suggestion` giữ `category_id`/`tag_ids` đã resolve để
  form edit prefill.
- `propose_new` → **không tự tạo** taxonomy; giữ tên thô trong
  `dna_json.taxonomy_suggestion`, dialog edit hiển thị gợi ý để admin bấm
  "Dùng gợi ý" mới `firstOrCreate` Category/Tag rồi `sync()`.

`dna_json` vẫn giữ `taxonomy_suggestion` (đã normalize trong
`VisualStyleDnaDefinition`) để prefill một lần; cột `ai_image_profiles.category`
scalar chỉ là nhãn hiển thị legacy, không phải nguồn phân loại.

Schema v1 (lưu `config/ai/visual_style_dna.php`, enum chuẩn hóa):

```json
{
  "schema_version": "1.0",
  "identity":   {"name":"", "slug":"", "category":"", "description":"", "keywords":[]},
  "composition":{"framing":"", "subject_position":"", "subject_scale":0.0,
                 "orientation":"", "visual_balance":"", "negative_space":"", "depth_layers":[]},
  "camera":     {"shot_type":"", "angle":"", "lens_look":"", "depth_of_field":"",
                 "focus":"", "perspective":""},
  "lighting":   {"type":"", "direction":"", "quality":"", "intensity":"",
                 "temperature":"", "shadows":"", "highlights":""},
  "environment":{"type":"", "background_style":"", "background_detail":"",
                 "background_blur":"", "props":"", "environment_adaptation":true},
  "color_mood": {"palette":[], "saturation":"", "contrast":"", "mood":[]},
  "subject_rules":{"preserve_shape":true, "preserve_color":true, "preserve_logo":true,
                   "preserve_text":true, "preserve_material":true,
                   "allow_perspective_change":true, "allow_occlusion":false},
  "style_constraints":{"photorealistic":true, "commercial_quality":true,
                        "avoid_3d_render":true, "avoid_illustration":true,
                        "avoid_unrealistic_hands":true},
  "product_adaptation":{"supported_categories":[], "adaptation_rules":{}},
  "confidence": {},
  "shot_variations": []
}
```

**Quy tắc:** enum chuẩn hóa (không để AI viết "eye-level"/"eye level" lung tung);
`confidence` cho field AI không chắc; `shot_variations` tách sang bảng riêng
sau khi user duyệt. `prompt`/`negative_prompt` của `ai_image_profiles` giữ lại làm
**rendered cache** — không phải nguồn.

## 4. Database — mở rộng `ai_image_profiles`, thêm 1 bảng

**Migration mở rộng `ai_image_profiles`** (giữ tên bảng để không phá code,
tên logic = VisualStyle):

```text
ai_image_profiles   + dna_json (JSON nullable)        -- Style DNA đã validate
                    + schema_version (string 8)       -- version DNA
                    + version (unsigned, default 1)   -- số lần chỉnh sửa
                    + status enum mở rộng: queued|analyzing|analyzed|ready|failed|draft|active|archived
                    + category (string 80 nullable)   -- nhóm phong cách
                    + prompt / negative_prompt         -- đổi vai trò: rendered cache
```

**Bảng mới `visual_style_shots`:**

```text
id, visual_style_id FK→ai_image_profiles cascade,
code (string 60), name, description nullable,
composition_override_json nullable, camera_override_json nullable,
prompt_suffix nullable, suggested_aspect_ratio nullable,
sort_order, is_active, timestamps
```

Không tạo `visual_styles`, `visual_style_references`, `image_generation_jobs`,
`image_generation_outputs` riêng — tái dùng `ai_image_profiles`, `media_assets`,
`ai_imports`, `ai_task_runs`. Ảnh tham chiếu = `media_assets` gắn qua
`HasMediaAssets` hoặc cột `reference_path` hiện có (chốt khi triển khai: giữ
`reference_path` đơn giản cho 1 ảnh, thêm quan hệ media nếu cần nhiều ảnh mẫu).

## 5. PromptBuilder — pipeline layer có thể đăng ký

Thiết kế **linh hoạt mở rộng** theo đúng pattern `TargetRegistry`/`ProviderRegistry`:

```php
// config/ai/visual_style_prompt_layers.php
return ['layers' => [
    ProductIdentityLayer::class,    // mô tả sản phẩm lần này
    StyleDnaLayer::class,           // render DNA → ngôn ngữ tự nhiên
    ProductAdaptationLayer::class,  // rule theo category sản phẩm
    ShotRecipeLayer::class,         // override góc/bố cục recipe
    UserInstructionsLayer::class,   // ghi chú admin
    PreservationConstraintLayer::class, // giữ logo/chữ/màu/shape
    NegativeLayer::class,           // avoid_* + negative_prompt
]];
```

Mỗi layer implement `PromptLayerContract`:

```php
interface PromptLayerContract {
    public function key(): string;                        // 'shot_recipe'
    public function render(VisualPromptContext $ctx): ?string; // null = bỏ qua
}
```

`VisualPromptBuilder` loop qua config layers, ghép kết quả, lưu `prompt_used`
vào output. **Thêm tính năng sau** (brand kit, mood board...) = file layer mới
+ 1 dòng config + field trên `VisualPromptContext` (có `extra: []` dự phòng).

`VisualPromptContext` DTO: `style, recipe, product, mediaIds, instructions,
length/aspect, extras[]` — `ProductContext` nhận `Product` model hoặc ad-hoc
(`mediaIds + description + category`), không bắt buộc entity Product.

## 6. Services / Jobs theo convention

```
App\Services\Ai\VisualStyle\
  VisualStyleDnaDefinition.php      -- schema + enum + validate + version (như WritingProfileDefinition)
  VisualStyleAnalyzerService.php    -- queue() + process() analyze (như WritingProfileAnalysisService)
  VisualStyleService.php            -- CRUD style + activate/archive
  VisualStyleShotService.php        -- CRUD shot recipes
  ProductContextBuilder.php         -- dựng ProductContext từ upload/Product
  VisualPromptBuilder.php           -- loop layers → prompt cuối
  PromptLayers\
    PromptLayerContract.php         VisualPromptContext.php
    ProductIdentityLayer.php        StyleDnaLayer.php
    ProductAdaptationLayer.php      ShotRecipeLayer.php
    UserInstructionsLayer.php       PreservationConstraintLayer.php
    NegativeLayer.php

App\Jobs\
  AnalyzeVisualStyleJob.php         -- queue phân tích ảnh → DNA
  GenerateVisualStyleImagesJob.php  -- per recipe → image provider → media_assets
```

Analysis/generation đi qua `AiImport` + `ai_task_runs` (popup queue dùng chung),
provider qua `ProviderRegistry`/`ModelResolver` với `AiCapability::Vision` (analyze)
và image provider (generate). Quota `ai.import.quota_per_hour` tái dùng.

## 7. API (`/api/admin/`, `BaseResponse`, FormRequest + authorize)

```
GET/POST   /api/admin/visual-styles                 list/create
GET/PUT/DEL /api/admin/visual-styles/{id}           show/update/archive
POST       /api/admin/visual-styles/{id}/analyze    queue phân tích → DNA draft
PUT        /api/admin/visual-styles/{id}/dna        lưu DNA đã chỉnh (validate)
POST       /api/admin/visual-styles/{id}/activate   status → active (ready+enabled)
CRUD       /api/admin/visual-styles/{id}/shots      shot recipes + reorder
POST       /api/admin/visual-styles/generate        chọn style+recipes+product+count+ratio
GET        /api/admin/visual-styles/runs/{taskRun}  trạng thái generation
```

Permission subject `ai_settings` (nhất quán profile hiện tại) hoặc subject mới —
chốt khi implement. Mọi endpoint qua FormRequest, lifecycle ở server.

## 8. Vue Admin

```
resources/js/pages/visual-styles/
  index.vue            -- grid style + trạng thái + số recipes
  edit/index.vue       -- upload ảnh mẫu, Analyze, form edit DNA (tabs nhóm),
                         prompt preview, danh sách recipes thêm/sửa/xóa/sort
  generate/index.vue   -- upload product, chọn style + recipes + count + ratio,
                         theo dõi outputs, retry/select
```

Dùng `VCard`, `AppDialogLayout`, PascalCase, header block tiếng Việt; DNA form
trực quan (slider/select), JSON chỉ là chế độ nâng cao. Service
`resources/js/services/visualStyles.js` unwrap envelope.

## 9. Luồng vận hành

```
TẠO STYLE:
  Upload ảnh mẫu → AiImport + AnalyzeVisualStyleJob → Vision provider
  → trả JSON DNA (+ suggested_shots) → VisualStyleDnaDefinition validate
  → lưu dna_json + status=analyzed → admin xem/sửa DNA + duyệt recipes
  → activate → version 1

TẠO ẢNH:
  Upload product + chọn style + 3-4 recipes + count + ratio
  → per recipe: VisualPromptBuilder → prompt → GenerateVisualStyleImagesJob
  → image provider → media_assets → output gắn post/variant
  → retry từng ảnh, prompt_used lưu để regenerate đúng
```

## 10. Điểm rủi ro đã xử lý / cần lưu ý

- **Không cam kết 100% giữ chữ/logo** — UI cảnh báo khi sản phẩm nhiều chữ nhỏ;
  slot compositing (tạo nền + ghép ảnh gốc) để phase sau, không ép dùng.
- **Enum DNA** — validate chặt trong `VisualStyleDnaDefinition`; giá trị ngoài
  enum → reject, không lưu DNA sai.
- **Recipe chỉ override field cần** — không sao chép nguyên DNA; merge rule:
  recipe field có giá trị → thắng, trống → giữ DNA.
- **Nhiều ảnh tham chiếu** — phase đầu giữ `reference_path` 1 ảnh; nâng cấp
  nhiều ảnh = quan hệ `media_assets` sau.
- **Provider capability** — kiểm tra negative_prompt/aspect_ratio/image-editing
  trước khi gửi; adapter chuẩn hóa response.
- **Cost/usage** — ghi vào `AiImport.diagnostics` + `generation_meta` của output.

## 11. Phase triển khai

| Phase | Nội dung | Xong khi |
| --- | --- | --- |
| VSE-1 | `VisualStyleDnaDefinition` + schema/enum config + migration ai_image_profiles + visual_style_shots | validate pass, migrate+rollback ok |
| VSE-2 | `VisualStyleAnalyzerService` + `AnalyzeVisualStyleJob` + analyze API → DNA draft | queue → JSON DNA validate được |
| VSE-3 | `VisualStyleService`/`VisualStyleShotService` + CRUD API (style, dna, shots, activate) | API CRUD + version++ |
| VSE-4 | `VisualPromptContext` + layers + `VisualPromptBuilder` + unit test từng layer | prompt ghép đúng thứ tự, recipe override đúng |
| VSE-5 | `GenerateVisualStyleImagesJob` + product context + generate API + outputs | tạo ảnh qua queue, prompt_used lưu |
| VSE-6 | Vue list/edit/generate + AiTaskRun popup | UI chạy, DNA form, theo dõi outputs |
| VSE-7 | QA unit/feature + lint/build + docs api/qa | coverage logic chính |

## 12. Definition of Done

- [ ] `dna_json` lưu + validate theo `schema_version`, enum chuẩn hóa
- [ ] Upload ảnh → AI trả DNA JSON hợp lệ (không bịa thông số, có confidence)
- [ ] Edit DNA bằng form, version tăng khi sửa
- [ ] CRUD shot recipes, recipe chỉ override field cần
- [ ] PromptBuilder ghép đúng thứ tự, mỗi recipe ra prompt khác nhau
- [ ] Generate ảnh qua queue, prompt_used + provider/model + cost lưu lại
- [ ] Retry từng ảnh, không block request HTTP
- [ ] Tái dùng `ai_imports`/`ai_task_runs`/`media_assets`/`AiCapability::Vision`
- [ ] Vietnamese comment đầy đủ PHP + header block `.vue`
- [ ] Tests cho validate + prompt builder + idempotency
