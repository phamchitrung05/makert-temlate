# Đối chiếu & Phân rã Task — Lịch Đăng Bài Tự Động Theo Chiến Dịch

**Cập nhật:** 01/2026 (đối chiếu trạng thái hiện tại trong repo)
**Nguồn plan:** [SOCIAL_POST_WEEKLY_SCHEDULE_PLAN.md](SOCIAL_POST_WEEKLY_SCHEDULE_PLAN.md) — APPROVED DESIGN
**Trang UI đích:** `/marketing/post-schedule` (file: [index.vue](../../resources/js/pages/marketing/post-schedule/index.vue), 7 tab trong `views/marketing/post-schedule/`)

---

## 1. Tóm tắt kết luận nhanh

| Khối | Trạng thái | % đáp ứng plan |
| --- | --- | --- |
| **Giao diện 7 tab** | Đã dựng skeleton đủ 7 tab | ~60% (đúng hình, sai vai trò/chưa nối data) |
| **Wizard 5 bước (CampaignCreateDialog)** | Gần như hoàn chỉnh UI + logic local | ~85% (đầy đủ trường plan yêu cầu) |
| **Schema `campaigns` / `daily_content_plans`** | Thiếu nhiều cột plan yêu cầu | ~35% |
| **Hệ thống Settings `marketing_schedule`** | Chưa tồn tại group | 0% |
| **Engine planner/pairing/sinh ảnh** | Một phần service có sẵn | ~25% |
| **Scheduler (Sunday batch + auto-publish)** | Chỉ có draft command | ~20% |
| **API nối các tab** | Chỉ có Campaign/Product/SocialPost CRUD | ~15% |

**Tổng hợp mức sẵn sàng toàn hệ thống: ~40%.** Phần "xương sống" (models, AI worker, FB publisher, Media, Visual Style engine, Writing Profile) **đã có**. Phần còn thiếu nằm ở **campaign config schema**, **planner/pairing services**, **weekly batch + auto-publish scheduler**, và **API controller cho trang này**.

---

## 2. Đối chiếu chi tiết từng tab (Plan §3)

| Tab | Vai trò theo plan | Trạng thái hiện tại | Lệch chuẩn |
| --- | --- | --- | --- |
| **1. Cài đặt chung** `GeneralTab.vue` | **Global config**: AI model text/image, ngôn ngữ, timezone, **Sunday batch toggle+time**, `auto_submit_review`, `auto_schedule_publish` | Vừa được chuẩn hóa font/bo góc; nhưng **vẫn chứa field sai vai trò**: số bài/ngày, khung giờ tạo bài, chiến dịch mặc định, danh mục, lịch tuần, platform quota, tone picker, image style — toàn bộ này plan gán cho tab khác | ⚠️ **Sai vai trò**: đang là "tổng hợp mọi thứ", cần viết lại thành Global Config thuần |
| **2. Chiến Dịch** `CampaignTab.vue` + `CampaignCreateDialog.vue` | Trung tâm điều khiển + wizard 5 bước | Table + search + status chip + progress + dialog đã có; wizard 5 bước đã đầy đủ field plan (Bước 2: keywords + Pool văn phong; Bước 3: subject media 4–6 + image count + Pool styles; Bước 4: platform + tần suất + story arc; Bước 5: pairing mode + cooldown + Sunday batch) | ✅ Gần đúng; thiếu: panel xem nhanh config Pool + nút "Phân bổ lịch"; data là mock, chưa nối API |
| **3. Lịch đăng** `ScheduleTab.vue` | Theo dõi + duyệt hàng tuần, week view, trạng thái bài | Có calendar grid tháng + filter campaign/platform; chưa có week/day view thật, không có nút "AI Lên bài tuần này", không có trạng thái bài / duyệt | ⚠️ Thiếu week view + approve flow + trạng thái |
| **4. Văn phong AI** `ToneTab.vue` | Master library ~50 `AiWritingProfile` | Có lưới 6 card + form chi tiết + preview + examples — đúng vai trò | ✅ Đúng hướng; chưa nối API `/ai/writing-profiles` |
| **5. Phong cách hình ảnh** `ImageStyleTab.vue` | Master library ~20 `AiImageProfile` | Có lưới 12 card + form chi tiết (name/desc/category/keywords/prompt) | ✅ Đúng hướng; chưa nối API `/ai/image-profiles` |
| **6. Nền tảng** `PlatformsTab.vue` | Global guardrails: quota/ngày, ảnh/bài, kết nối platform | Chỉ có list 4 platform với quota stepper; **bị lỗi render lặp 2 lần** (dòng icon+name hiện 2 lần); chưa có giới hạn ảnh/bài, chưa có kết nối account | ⚠️ Bug UI + thiếu field |
| **7. Nâng cao** `AdvancedTab.vue` | Quy tắc AI: trọng số 4 tầng, cooldown, conflict khi trùng campaign, retry | **Placeholder rỗng** | ❌ Chưa có gì |

---

## 3. Đối chiếu Backend (Plan §7 Phase 1 & 3)

### 3.1 Schema — Plan yêu cầu vs hiện có

**`campaigns`** — hiện có: `name, objective, message, starts_at, ends_at, timezone, status, created_by, updated_by`.
Thiếu theo Task 1.1: `budget, keywords_json, writing_profile_ids, image_profile_ids, subject_media_ids, subject_description, platforms_json, schedule_config_json, advanced_config_json` → **9/9 cột chưa có**.

**`daily_content_plans`** — hiện có: `planned_date, slot, timezone, campaign_id, product_id, social_post_id, target_draft_count, status, error_code/message, started_at, completed_at`.
Thiếu theo Task 1.2: `pillar_type, topic_title, keywords_json, content_angle, subject_media_ids, subject_description, writing_profile_id, image_profile_id, image_count, scheduled_at` → **10/10 cột chưa có** (campaign_id đã có sẵn ✓).

**HasTaxonomy** — `AiImageProfile` đã có `HasTaxonomy` ✓; `Campaign` và `AiWritingProfile` **chưa có** (Task 1.3).

### 3.2 Services / Jobs / Commands — có sẵn vs thiếu

| Thành phần plan | Tên theo plan | Hiện trạng |
| --- | --- | --- |
| Planner tuần | `WeeklyContentKeyPlannerService` | ❌ Chưa có |
| Pairing 4 tầng | `SocialSchedulePairingService` | ❌ Chưa có |
| Worker viết caption | `SocialPostAiWorkerService` | ✅ Có [app/Services/Marketing/SocialPostAiWorkerService.php](../../app/Services/Marketing/SocialPostAiWorkerService.php) |
| Context builder | `SocialPostContextBuilder` | ✅ Có |
| Image job | `ProcessSocialPostImageJob` + `ProcessSocialPostImagePromptJob` | ✅ Có |
| Visual prompt | `VisualPromptBuilder` + `VisualStyleShot` + shots | ✅ Có [app/Services/Ai/VisualStyle/VisualPromptBuilder.php](../../app/Services/Ai/VisualStyle/VisualPromptBuilder.php) |
| FB publisher | `FacebookPublisher` | ✅ Có nhưng giới hạn **4 ảnh** (`array_slice($images, 0, 4)` tại dòng 68) — plan yêu cầu 5 |
| Batch Sunday | `GenerateDailySocialPostDraftsCommand --weekly-batch` | ⚠️ Command có, signature `marketing:social-post-drafts --date --count` nhưng **chưa có `--weekly-batch`**, chưa schedule `weeklyOn(0,'07:00')` |
| Auto-publish | `PublishScheduledSocialPostsCommand` | ❌ Chưa có |
| Settings group | `marketing_schedule` trong `ProjectSettingsService::CLASSES` | ❌ Chưa có group này |
| Controller trang | `PostScheduleController` | ❌ Chưa có |
| Campaign API | `CampaignController` + `CampaignRequest` | ✅ Có GET/POST/PUT/DELETE `/marketing/campaigns` |
| Writing/Image profile API | `AiWritingProfileController`, `AiImageProfileController`, `AiImageProfileController::options`, `AiWritingProfileController::options` | ✅ Có |

---

## 4. Kết luận đáp ứng

- **UI skeleton**: ~60% — đủ tab, đúng bố cục, chưa đúng vai trò + chưa nối data.
- **Wizard 5 bước**: ~85% — UI+state+computed hoàn chỉnh, thiếu API submit thật.
- **Backend**: ~35% — engine AI có sẵn, nhưng schema config campaign, planner/pairing, scheduler batch/publish, settings group và controller đều chưa có.
- **Tổng**: **~40%** — plan mới ở giai đoạn "UI mock đúng hình" chứ chưa đấu nối pipeline thật.

---

## 5. Task chi tiết chuẩn bị triển khai

> Theo cấu trúc plan (Phase 1 → 4). Mỗi task kèm file đích & acceptance. Các task được đánh số tiếp theo plan gốc; **không đánh số lại** để dễ đối chiếu.

### Phase 1 — Schema & Settings (Backend Foundation)

| ID | Task | File chính | Ghi chú |
| --- | --- | --- | --- |
| 1.1 | Migration thêm 9 cột vào `campaigns` | `database/migrations/YYYY_MM_DD_xxxxxx_extend_campaigns_for_weekly_schedule.php` | `budget`, `keywords_json`, `writing_profile_ids`, `image_profile_ids`, `subject_media_ids`, `subject_description`, `platforms_json`, `schedule_config_json`, `advanced_config_json`. Có `up`/`down`, có index phù hợp. Cập nhật `Campaign::$fillable`/`$casts` và `getActivitylogOptions`. |
| 1.2 | Migration thêm 10 cột vào `daily_content_plans` | `database/migrations/..._extend_daily_content_plans_for_campaign_slots.php` | `pillar_type`, `topic_title`, `keywords_json`, `content_angle`, `subject_media_ids`, `subject_description`, `writing_profile_id` (FK), `image_profile_id` (FK), `image_count`, `scheduled_at`. Cập nhật `DailyContentPlan` fillable/casts/relations `writingProfile()`/`imageProfile()`. |
| 1.3 | Gắn `HasTaxonomy` vào `Campaign` + `AiWritingProfile` | `app/Models/Campaign.php`, `app/Models/AiWritingProfile.php` | Cho phép category/tag như `AiImageProfile` để Tầng 1 ghép cặp dùng taxonomy. |
| 1.4 | Đăng ký `Settings` group `marketing_schedule` | `app/Services/Settings/ProjectSettingsService.php` + `app/Settings/MarketingScheduleSettings.php` (mới) | Keys: `default_text_provider/model`, `default_image_provider/model`, `default_language`, `default_timezone`, `sunday_batch_enabled`, `sunday_batch_time`, `auto_submit_review`, `auto_schedule_publish`, `platform_quotas_json`, `global_pairing_rules_json`. |

### Phase 2 — Frontend 7 tab + Wizard

| ID | Task | File chính | Ghi chú |
| --- | --- | --- | --- |
| 2.1 | Sửa `p` thừa dòng 1 + nối wizard 5 bước với API tạo campaign thật | `CampaignCreateDialog.vue` | Hiện đã đầy đủ field; cần map `form` → payload `keywords_json`/`writing_profile_ids`/... và gọi `POST /marketing/campaigns`. |
| 2.2 | **Viết lại `GeneralTab`** thành Global Config đúng plan | `GeneralTab.vue` | Bỏ toàn bộ field lệch vai trò (đã có trong wizard). Giữ: AI text model, image model, language, timezone, **Sunday batch toggle + time**, 2 công tắc auto. Bind `Settings group marketing_schedule`. |
| 2.3 | Nâng cấp `ScheduleTab` + `CampaignTab` | `ScheduleTab.vue`, `CampaignTab.vue` | ScheduleTab: week/day view thật, trạng thái bài, nút "AI Lên bài tuần này", duyệt cả tuần. CampaignTab: panel xem nhanh Pool + nút "Phân bổ lịch bài". |
| 2.4 | Hoàn thiện `AdvancedTab` + sửa `PlatformsTab` | `AdvancedTab.vue`, `PlatformsTab.vue` | PlatformsTab: xóa lặp icon+name, thêm max ảnh/bài + social account link. AdvancedTab: trọng số 4 tầng, cooldown, conflict rule, retry. |

### Phase 3 — Engine Planner, Pairing & Sinh ảnh

| ID | Task | File chính | Ghi chú |
| --- | --- | --- | --- |
| 3.1 | `WeeklyContentKeyPlannerService` | `app/Services/Marketing/WeeklyContentKeyPlannerService.php` | Quét campaign active giao tuần; sinh `(pillar_type, topic_title, keywords_json, content_angle)` theo story arc 25/50/25. |
| 3.2 | `SocialSchedulePairingService` | `app/Services/Marketing/SocialSchedulePairingService.php` | Lọc Pool campaign; chấm điểm 4 tầng (taxonomy 40 + affinity 30 + rotation 30); không trùng trong tuần. |
| 3.3 | Truyền context vào worker + nâng FB limit 4→5 | `SocialPostContextBuilder.php`, `SocialPostAiWorkerService.php`, `ProcessSocialPostImageJob.php`, `FacebookPublisher.php` | Truyền `topic_title/keywords/content_angle/subject_description` vào prompt; nâng `array_slice($images, 0, 4)` → `0, 5`. |

### Phase 4 — Scheduler + API

| ID | Task | File chính | Ghi chú |
| --- | --- | --- | --- |
| 4.1 | Nâng `GenerateDailySocialPostDraftsCommand` hỗ trợ `--weekly-batch` + schedule | `GenerateDailySocialPostDraftsCommand.php`, `routes/console.php` hoặc `bootstrap/app.php` | `weeklyOn(0, '07:00')`; chỉ tạo slot khi có campaign active giao tuần; tạo DailyContentPlan + SocialPost → pending_review. |
| 4.2 | `PublishScheduledSocialPostsCommand` + `everyFiveMinutes()` | `app/Console/Commands/PublishScheduledSocialPostsCommand.php` | Quét `social_posts` approved có `scheduled_at <= now()` → `PublishSocialPostJob`. |
| 4.3 | `PostScheduleController` + nối API 7 tab | `app/Http/Controllers/Admin/Marketing/PostScheduleController.php`, `routes/api.php`, `resources/js/services/postSchedule.js` | Endpoint GET/PUT cấu hình + trigger batch; service frontend unwrap `BaseResponse`. |

---

## 6. Gợi ý thứ tự thực hiện

1. **Phase 1 trước** (schema + settings) — mọi thứ phía sau phụ thuộc vào.
2. **Phase 3.3 + Phase 4.2** là rủi ro tích hợp nhỏ nhất để làm sớm (nâng FB limit, publish command).
3. **Phase 2.2** (viết lại GeneralTab) là task frontend dễ tách riêng vì chỉ đọc settings, không cần planner.
4. **Phase 3.1 + 3.2** là lõi phức tạp nhất — nên làm sau khi schema chốt.
5. **Phase 4.1 + 4.3** là tích hợp cuối.
