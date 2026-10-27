# Plan: Chuẩn hóa state domain sang Pinia + `createCrudStore` factory

**Cập nhật:** 27/10/2026  
**Trạng thái:** DONE — Đã triển khai `createCrudStore.js` và chuẩn hóa toàn bộ stores (`category`, `aiCatalog`, `visualStyle`, `socialPost`, `user`, `resource`, `resourceVersion`, `post`).

## Mục tiêu

Đưa toàn bộ domain còn lệch pattern (gọi service trực tiếp từ page hoặc giữ shared state trong composable) về kiến trúc chuẩn của project: **page thin → Pinia store → service → Laravel API**, theo DEVELOPMENT_GUIDELINES ("route page thin: connect the Pinia store").

## Hiện trạng

- 7 stores hiện có nhưng chỉ `mediaAsset/post/resource/resourceVersion` là CRUD chuẩn; `adminAuth/aiAgent/slug` là special-purpose.
- Nhiều domain giữ shared state trong composable (fetch lặp mỗi màn hình):
  - `useAiProviderSettings` được dùng ở `pages/settings/ai-providers.vue`, `StyleAnalyzeDialog`, `VisualStyleCreateDialog` → mỗi nơi fetch catalog riêng.
  - `useSocialPosts` giữ items/stats/options cho social-post list.
  - `useUserList` cho user list.
  - `categoryService` được `pages/apps/taxonomy/category/index.vue` và `VisualStyleEditDialog` gọi trực tiếp, không qua store.
- `visualStylesService` đã có đủ CRUD + lifecycle + shots nhưng chưa có store.

## Thiết kế

### 1. Factory `createCrudStore` — `resources/js/stores/createCrudStore.js`

```js
createCrudStore('category', categoryService, {
  // optional
  actions: {                          // map thêm endpoint lifecycle
    publish: 'publish',               // → store.publish(id) gọi service.publish(id)
    archive: 'archive',
  },
  listDefaults: { sort: 'created_at', direction: 'desc' },
  normalizeList: r => r,              // nếu service trả shape khác
})
```

State expose (giống `resource.js`):
- `items`, `current`, `totalItems`, `pagination`, `filters` (readonly)
- `isLoading`, `isMutating`, `error`
- `hasItems`, `query` (computed)

Actions chuẩn:
- `fetchItems(params)` — gọi `service.list`, revision counter chống stale
- `fetchItem(id, fallback?)` — `service.show`, detailRevision chống stale
- `createItem(payload)` → `service.create` qua `runMutation`
- `updateItem(id, payload)` → `service.update`
- `removeItem(id)` → `service.remove`, gỡ item khỏi `items`
- Custom actions từ `options.actions` → `runMutation(service[key])`
- `setFilters`, `setPagination`, `clearCurrent`, `clearError`, `reset`

Contract giữ nguyên `response.items` / `response.itemsLength` / `response.pagination` của BaseResponse DataTable.

### 2. Stores mới (qua factory)

| Store | Service | Actions tùy chỉnh |
|---|---|---|
| `useVisualStyleStore` | `visualStylesService` | `activate`, `archive`, `duplicate`, `analyze`, `updateDna`, `listShots`, `createShot`, `updateShot`, `deleteShot`, `reorderShots`, `generate`, `getGeneration` |
| `useSocialPostStore` | `socialPostService` | `generate`, `regenerateCaption`, `regenerateImage`, `submitReview`, `approve`, `reject`, `requestRevision`, `publish`, `products`, `campaigns`, `accounts`, `connectFacebook` + `stats` |
| `useCategoryStore` | `categoryService` | CRUD chuẩn |
| `useAiCatalogStore` | `aiProviderSettingsService` (qua `useAiProviderSettings` internals) | `load` (providers/presets/settings), cache 1 lần/session — **không** CRUD, viết tay theo factory nhưng read-mostly |
| `useUserStore` | `accessManagementService` | list/show/create/update/remove + roles |

VisualStyle có `shots` là sub-resource → factory hỗ trợ `subResources` hoặc store tự định nghĩa thêm actions gọi `visualStylesService` shot methods (chọn cách 2, đơn giản hơn: store viết tay nhưng tái dùng `runMutation`/state core từ factory qua options hoặc spread).

### 3. Refactor về factory (giữ API cũ)

- `useResourceStore`, `useResourceVersionStore`, `usePostStore` → viết lại bằng `createCrudStore` + `actions`, **giữ tên method cũ** (`fetchResources`, `createResource`, `publishPost`...) bằng alias export để page hiện tại không đổi.
- `mediaAsset`, `aiAgent`, `adminAuth`, `slug` — **giữ nguyên** (logic đặc thù: upload progress, usage, session polling, auth).

### 4. Cập nhật consumers

- `pages/apps/taxonomy/category/index.vue` → `useCategoryStore`
- `views/visual-styles/*` + `pages/visual-styles/**` → `useVisualStyleStore`
- `pages/marketing/social-post/list/index.vue` + `SocialPostDetailDialog` → `useSocialPostStore`
- `pages/apps/user/list/index.vue`, `user/view/[id].vue`, `roles/index.vue` → `useUserStore`
- `useAiProviderSettings` → đổi thành wrapper mỏng gọi `useAiCatalogStore` (giữ signature để 3 consumers không đổi)
- `useSocialPosts`, `useUserList` → giữ phần local state/filters, rút fetch sang store

### 5. Không động vào

- PHP backend — contract API không đổi.
- Các page list/add/edit đang dùng `usePostStore`/`useResourceStore` — chỉ hưởng lợi (alias giữ nguyên), không sửa template nếu không cần.

## Việc cần làm (theo thứ tự)

1. Tạo `stores/createCrudStore.js` + test nhẹ.
2. Tạo `stores/category.js` → cập nhật taxonomy page.
3. Tạo `stores/aiCatalog.js` → refactor `useAiProviderSettings` thành wrapper.
4. Tạo `stores/visualStyle.js` → cập nhật visual-styles pages/dialogs.
5. Tạo `stores/socialPost.js` → cập nhật social-post pages/dialog.
6. Tạo `stores/user.js` → cập nhật user/roles pages.
7. Refactor `resource`, `resourceVersion`, `post` sang factory (giữ alias).
8. Lint + `npm run build` kiểm tra.

## Rủi ro / lưu ý

- Mỗi file `.js` mới trong `stores/` cần **header comment tiếng Việt** theo DEVELOPMENT_GUIDELINES (CHỨC NĂNG FILE / CÁC HÀM / INPUT-OUTPUT).
- `useAiProviderSettings` là factory-composable (state per-call); chuyển sang store sẽ đổi semantics → phải kiểm tra 3 consumers không phụ thuộc vào việc mỗi nơi một instance riêng (thực tế chúng muốn share catalog nên store là đúng).
- `mediaAsset` có usage relations đặc thù — không ép vào factory.
- Tag chưa có service riêng; nếu sau này có Tag admin page thì thêm `useTagStore` theo factory.

## Kết quả mong đợi

- Mọi domain list/show/create/update/delete đi qua Pinia store — devtools inspect được, không fetch lặp, stale-guard tập trung.
- Composables chỉ còn local state (form, dialog guard, watcher nghiệp vụ).
- Không đổi contract API/backend; pages hiện tại ít sửa nhất nhờ alias.
