# Kế hoạch AI Content Planner & Generator — tự động 3–4 bài Social/ngày

**Cập nhật:** 21/10/2026
**Trạng thái:** DRAFT — chờ chủ dự án duyệt trước khi triển khai.
**Nguồn:** đối chiếu từ `ai-content-planner-generator-master-prompt.md` với codebase
hiện tại. Ý tưởng: mỗi ngày hệ thống xáo trộn các Writing Profile + Visual Style đã
chọn, viết 3–4 bài social (mỗi bài 1 nội dung + 3–4 ảnh), đưa vào draft chờ duyệt.
**Phạm vi:** một thương hiệu duy nhất (không multi-brand); Trend Watcher bị loại bỏ —
chủ dự án tự thiết kế tiêu chí nội dung sau qua slot `content_criteria` đã chừa sẵn.

## 1. Nguyên tắc kiến trúc (giữ nguyên từ master prompt)

Phân tách các lớp, không gộp thành một prompt lớn:

| Thành phần | Vai trò | Entity project |
| --- | --- | --- |
| Brand Personality | Bản sắc/tính cách thương hiệu cố định | `brand_personality` (singleton, bảng mới) |
| Writing Style DNA | Cấu trúc + đặc trưng cách viết | `ai_writing_profiles` (đã có) |
| Writing Tone Profile | Cảm xúc/sắc thái của từng bài | `writing_tone_profiles` (bảng mới) |
| Content Pillar | Trụ cột nội dung + tỷ trọng | `content_pillars` (bảng mới) |
| Content Objective | Mục tiêu bài viết | `content_objectives` (bảng mới) |
| Content Format | Hình thức thể hiện | `content_formats` (bảng mới) |
| Visual Style DNA | Phong cách ảnh | `ai_image_profiles` → nâng cấp thành Visual Style Engine |
| Shot Recipe | Góc chụp cụ thể | `visual_style_shots` (bảng mới của VSE) |
| Content Brief | Bản giao việc cho từng bài | `content_plan_items.brief` (JSON) |
| Generated Content | Bài viết AI tạo | `social_posts` + `social_post_versions` (tái dùng) |

## 2. Quyết định entity — KHÔNG tạo bảng trùng

Master prompt đề xuất ~9 bảng; đối chiếu codebase chỉ cần **5 bảng mới nhỏ**
(taxonomy/strategy), còn lại tái dùng:

| Master prompt | Quyết định | Lý do |
| --- | --- | --- |
| `content_plans` | **Tái dùng `daily_content_plans`** (đã có từ MP-01) | Thêm `status`, `planner_metadata_json`, `generated_at` nếu thiếu |
| `content_plan_items` | **Mỗi item = 1 `social_posts` draft**, link `daily_content_plan_id` | SocialPost đã có lifecycle draft/review/approve |
| `generated_contents` | **`social_post_versions`** | Đã lưu nội dung + context_json + writing_profile_snapshot |
| `content_reviews` | **`ai_article_evaluations`** mở rộng target social_post | Evaluator/hard-gate đã có |
| `content_generation_assets` | **`social_post_variants` + `media_assets`** | Variant đã giữ gallery ảnh có thứ tự |
| `brand_personality_profiles` | **`brand_personality` singleton** (1 brand) | 1 row; nếu multi-brand sau này thêm `brand_id` nullable |
| `writing_tone_profiles` | **Bảng mới** | Thực sự là lớp mới tách khỏi Writing DNA |
| `content_pillars` / `content_objectives` / `content_formats` | **Bảng mới** | Taxonomy chiến lược mới |
| `visual_style_shots` | Bảng mới — thuộc VSE | Shot Recipe riêng |
| `image_generation_jobs/outputs` | **`ai_imports` + `ai_task_runs`** | Queue tracker dùng chung đã có |

## 3. Lớp prompt — tách bạch + precedence

Pipeline ghép prompt theo **layer có thể đăng ký** (không hardcode), kế thừa
pattern `TargetRegistry`/`ProviderRegistry` hiện có:

```php
// config/ai/content_planner_layers.php — đăng ký layer, thêm tính năng = file mới + 1 dòng
'layers' => [
    BrandPersonalityLayer::class,      // bản sắc thương hiệu (bất biến)
    WritingDnaLayer::class,            // rules/style_instructions từ profile
    ToneProfileLayer::class,           // cảm xúc bài này — override tone của DNA
    ContentBriefLayer::class,          // topic/angle/pillar/format/objective
    ProductContextLayer::class,        // facts_allowed + mô tả sản phẩm
    LengthLayer::class,                // đọc config('ai.content.length_options')
    UserInstructionsLayer::class,      // ghi chú admin + content_criteria (slot chờ)
    ConstraintLayer::class,            // không bịa fact/giá/địa chỉ, giữ logo
    NegativeLayer::class,              // avoid_* từ DNA + Tone constraints
];
```

**Precedence (rủi ro đã xử lý):** Tone Profile là *runtime override* cho bài đó —
khi DNA và Tone cùng định nghĩa một thuộc tính (vd formality), **Tone thắng**,
còn lại lấy DNA. Mỗi layer implement `PromptLayerContract::render(PromptContext)`
trả `?string`; trả `null` thì bỏ qua.

## 4. Database — chỉ các bảng MỚI

```text
brand_personality          singleton: id, profile_json, version, status, timestamps
content_pillars            id, name, slug, description, target_ratio, configuration_json,
                           is_active, sort_order, timestamps
content_objectives         id, name, slug, description, configuration_json, is_active, timestamps
content_formats            id, name, slug, description, structure_json, default_length_json,
                           is_active, timestamps
writing_tone_profiles      id, name, slug, description, tone_json, version, status, timestamps
visual_style_shots         (thuộc VSE) id, visual_style_id, code, name, description,
                           composition_override_json, camera_override_json, prompt_suffix,
                           suggested_aspect_ratio, sort_order, is_active, timestamps
```

**Mở rộng bảng có sẵn (migration nhỏ):**

```text
daily_content_plans   + status (planned/generating/done/failed), planner_metadata_json,
                        generated_at, posts_target (mặc định 3-4)
social_posts          + daily_content_plan_id nullable FK, plan_slot, plan_brief_json
ai_image_profiles     + dna_json, schema_version, version, status  → tên logic VisualStyle
```

Không tạo `content_plans`, `generated_contents`, `content_reviews`,
`content_generation_assets`, `image_generation_jobs/outputs` riêng — đã có tương đương.

## 5. Luồng pipeline hàng ngày

```text
Laravel Scheduler (config('app.timezone'))
   │  DailyContentPlanJob::dispatch() — idempotent theo plan_date
   ▼
ContentPlannerContextBuilder           → gom config + lịch sử 7–30 ngày (KHÔNG gửi raw history)
   │  nguồn: pillars, objectives, formats, writing profiles, tone profiles,
   │  visual styles, products/facts, campaign, content_criteria (slot), recent posts meta
   ▼
Planner AI (text provider)             → trả JSON plan theo schema cố định
   │  PlanJsonValidator: schema + ID existence + quan hệ + đúng số slot
   │  ID ảo → retry ≤2 → vẫn sai → fallback chọn theo Selection Score rule-based
   ▼
Lưu daily_content_plans + tạo N social_posts draft (status=planned, plan_brief_json)
   │  (tuỳ chọn: chờ admin duyệt plan trước khi generate — config `auto_plan`)
   ▼
Per item — queue độc lập, có thể retry/regenerate riêng:
   ├─ GeneratePostContentJob  → Writer layer → social_post_versions + status=review
   ├─ GeneratePostImagesJob   → VSE PromptBuilder → image provider → media_assets
   │                            → social_post_variants (gallery có thứ tự)
   ▼
ContentQualityReview (tái dùng evaluator):
   - pass → chờ admin approve   - revise ≤2   - vượt → needs_manual_review
   - HARD GATE: factual accuracy + brand constraints lỗi → reject dù điểm cao
   ▼
Admin duyệt qua flow SocialPost hiện có → social_post_targets → publisher (MP-02)
```

**Dedupe:** trước khi ghi, hash `(plan_date + slot + topic_hash + style_id + profile_id)`;
`ContentDiversityService` tính Selection Score rule-based bằng PHP/SQL — AI chỉ đề xuất,
backend quyết chọn cuối. Không gửi toàn bộ lịch sử vào prompt.

## 6. Services / Jobs mới (theo convention Service/Job/Registry)

```
App\Services\ContentPlanner\
  BrandPersonalityService.php        ContentPillarService.php
  ToneProfileService.php             DailyContentPlannerService.php
  ContentDiversityService.php        ContentBriefBuilder.php
  PlanJsonValidator.php              ContentGenerationOrchestrator.php
  ContentScheduleService.php

App\Services\ContentPlanner\PromptLayers\  (mỗi file = 1 layer, contract chung)
  PromptLayerContract.php            PromptContext.php
  BrandPersonalityLayer.php          WritingDnaLayer.php
  ToneProfileLayer.php               ContentBriefLayer.php
  ProductContextLayer.php            LengthLayer.php
  UserInstructionsLayer.php          ConstraintLayer.php
  NegativeLayer.php                  ContentCriteriaLayer.php  ← slot chờ chủ dự án

App\Jobs\
  GenerateDailyContentPlanJob.php    GeneratePostContentJob.php
  GeneratePostImagesJob.php          ReviewGeneratedContentJob.php
  RegenerateContentItemJob.php
```

Tái dùng `AiTaskRunService`/`ai_task_runs` để tracker hiện trong popup queue chung;
mỗi lần gọi planner/writer/reviewer = 1 `AiImport` (operation riêng), giữ quota
`ai.import.quota_per_hour`.

## 7. API (theo convention `/api/admin/`, qua `BaseResponse`)

```
GET   /api/admin/content-planner/dashboard
GET   /api/admin/content-planner/plans            POST /plans/generate
GET   /api/admin/content-planner/plans/{id}        POST /plans/{id}/regenerate
GET   /api/admin/content-planner/items/{id}
POST  /items/{id}/generate-content | /generate-images | /review
POST  /items/{id}/approve | /reject | /regenerate | /schedule
CRUD  /api/admin/content-planner/{pillars|objectives|formats|tone-profiles|personality}
```

Permission subject mới `content_planner` (hoặc gộp `ai_settings` — chốt khi triển khai),
mọi route qua FormRequest + authorize server-side.

## 8. Vue Admin (PascalCase, AppDialogLayout, header tiếng Việt)

```
resources/js/pages/content-planner/
  dashboard/index.vue        — chọn ngày, số bài, trạng thái, tiến độ
  settings/index.vue         — tabs: Personality / Pillars / Objectives /
                               Formats / Tone Profiles / Diversity rules
  plans/{id}/index.vue       — review plan, regenerate từng item/toàn bộ
  items/{id}/index.vue       — xem bài + ảnh, edit, score, approve/reject/schedule
```

Nav thêm mục trong `apps-and-pages.js` + `horizontal/apps.js`. Tone Profile dùng
form slider 1–5 + select — không bắt sửa JSON thô (JSON = chế độ nâng cao).
Service `resources/js/services/contentPlanner.js` unwrap `BaseResponse`.

## 9. Điểm đã chỉnh so với master prompt (rủi ro)

1. **Trùng bảng** — map sang `daily_content_plans`/`social_posts`/`social_post_versions`/
   `ai_article_evaluations`; không tạo song song.
2. **Đơn vị độ dài** — dùng `config('ai.content.length_options')` (đơn vị **từ**),
   plan item lưu `length_key` (`status`=10–20 từ, `short`=150–300 từ, …); có thể thêm
   mức mới vào config mà không đổi code. Master prompt nói "câu" → đã sửa thành key-based.
3. **Tone vs DNA precedence** — Tone Profile thắng ở thuộc tính nó override; DNA là base.
4. **Planner bịa ID** — validate + retry ≤2 + fallback Selection Score rule-based.
5. **Reviewer 10 tiêu chí** — gom 4–5 nhóm: content_quality / brand_fit / tone_fit /
   factual / cta; hard gate factual + brand (plan đã có, giữ).
6. **`brand_id` → 1 brand** — `brand_personality` singleton; `BrandContext::current()`
   resolve mặc định, sau này nâng cấp multi-brand bằng FK nullable.
7. **Không tự publish** — mọi output vào `social_posts` draft, đi qua approve +
   `social_post_targets` + publisher như hiện tại; config `auto_publish=false` mặc định.
8. **Ảnh nhiều chữ** — cảnh báo UI + slot compositing sau (VSE lo phần prompt).

## 10. Phase triển khai

| Phase | Nội dung | Phụ thuộc |
| --- | --- | --- |
| CP-0 | Audit + chốt entity mapping này, subject permission | — |
| CP-1 | Visual Style Engine (bảng shots, DNA schema, PromptBuilder, generation) | — (SA-1) |
| CP-2 | Migrations + models + CRUD: personality, pillars, objectives, formats, tone profiles | CP-0 |
| CP-3 | Planner: context builder, diversity score, plan JSON validate, lưu plans + items | CP-2 |
| CP-4 | Writer: brief builder + prompt layers + draft social_post_versions | CP-3 |
| CP-5 | Image integration: chọn style+recipes → generation → variants | CP-1, CP-4 |
| CP-6 | Quality: evaluator mở rộng, hard gates, revise limit, needs_manual_review | CP-4 |
| CP-7 | Scheduler hàng ngày + idempotency + cancel/retry/regenerate | CP-3..6 |
| CP-8 | Vue dashboard + settings + plan/item review UI | CP-3..7 |
| CP-9 | QA: unit (tone/dna/score/plan-validate/prompt), feature (CRUD/plan/generate/approve), lint/build | mọi phase |
| SA-5 | *(chừa trống)* Content Criteria — chủ dự án thiết kế sau, cắm `ContentCriteriaLayer` | — |

## 11. Định nghĩa xong (Definition of Done)

- [ ] `brand_personality` quản lý được (singleton form)
- [ ] CRUD pillars / objectives / formats / tone profiles
- [ ] Planner tạo 3–4 item theo config, JSON validate, không bịa ID
- [ ] Tái dùng `ai_writing_profiles` và Visual Style Engine
- [ ] Writer sinh draft theo brief + tone, không bịa fact
- [ ] Images qua queue, gắn đúng post, prompt_used lưu lại
- [ ] Reviewer chấm + hard gate + giới hạn revise
- [ ] Scheduler idempotent, không trùng plan cùng ngày
- [ ] Admin duyệt trước publish; tests + docs + lint/build pass
