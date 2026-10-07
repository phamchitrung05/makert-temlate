# Roles và Permissions — A-02 backend

**Cập nhật:** 07/10/2026. Backend đã triển khai; hai page Vue `/apps/roles` và `/apps/permissions` để khung trống theo yêu cầu chủ dự án, giao diện mới bổ sung sau.

## Xác thực và quyền

Mọi endpoint bên dưới có prefix `/api/admin`, dùng Bearer token Sanctum có ability `admin`, tài khoản `App\Models\User` đang active và permission guard `admin`.

- Đọc: `users.view` **hoặc** `users.manage`.
- Tạo/sửa/xóa role và gán role: `users.manage`.
- Manager chỉ cấp các permission mình đã có; chỉ sửa role và tài khoản nằm trong phạm vi quyền của mình.
- `super-admin` có toàn bộ catalog từ seeder, role này không được sửa/xóa qua API. Chỉ super-admin được gán/gỡ role super-admin cho **tài khoản khác**.
- `admin`, `editor`, `support` giữ nguyên tên và không được xóa; permission của các role này có thể sửa theo scope.
- Không được tự đổi danh sách role. Khi sửa role mình đang dùng, phải giữ `users.manage` qua role đó, role khác hoặc direct permission.
- Chỉ xóa custom role chưa được gán cho admin nào.

Backend kiểm quyền ở middleware/FormRequest và kiểm lại actor/scope dưới transaction lock khi ghi. Các cờ trả về cho UI hỗ trợ hiển thị thao tác; server vẫn kiểm lại mọi request.

## Endpoint

| Method | Path | Dữ liệu / hành vi |
| --- | --- | --- |
| GET | `/roles` | Danh sách role admin: `search`, `page`, `per_page` (mặc định 12) |
| GET | `/roles/{id}` | Chi tiết role và version mới nhất |
| POST | `/roles` | Tạo role: `name`, `permission_ids`; HTTP 201 |
| PATCH | `/roles/{id}` | Thay toàn bộ tên/quyền: `name`, `permission_ids`, `expected_version` |
| DELETE | `/roles/{id}` | JSON body `expected_version`; HTTP 204 khi xóa thành công |
| GET | `/permissions` | Catalog phân trang: `search`, `group`, `page`, `per_page` (mặc định 25) |
| GET | `/permissions/catalog` | Nhóm quyền và lựa chọn role kèm cờ `assignable` |
| GET | `/access/users` | Admin thật: `search` (tên/email), `status`, `role_id`, `page`, `per_page` (mặc định 10) |
| GET | `/access/users/{id}` | Hồ sơ tối thiểu, role và version mới nhất |
| PATCH | `/access/users/{id}/roles` | Thay toàn bộ role: `role_ids`, `expected_version` |

Sort danh sách theo `name`, rồi `id`; `page >= 1`, `1 <= per_page <= 100`, search tối đa 120 ký tự. `group` phải thuộc `config/permissions.php`; `role_id` phải thuộc guard admin.

Danh sách trả envelope chuẩn:

```json
{
  "success": true,
  "message": null,
  "data": { "items": [], "itemsLength": 0 },
  "errors": [],
  "meta": { "pagination": { "current_page": 1, "per_page": 12, "total": 0 } }
}
```

Ví dụ trên lược bớt các field pagination/links khác. `/roles` có thêm `meta.can_manage`.

## DTO cho giao diện mới

**Role** trả `id`, `name`, `permissions: [{id, name}]`, `users_count`, `version`, `is_system`, `can_edit`, `can_delete`.

**Permission** trả `id`, `name`, `group`, `action`, `roles: [{id, name}]`, `created_at`. Danh sách chỉ hiển thị permission guard admin có trong catalog ứng dụng. Không cung cấp CRUD tên permission; thêm quyền nghiệp vụ bằng config/code rồi chạy seeder.

**Admin user** trả `id`, `name`, `email`, `username`, `status`, `roles: [{id, name}]`, `version`, `can_edit_roles`. Không trả password/token/remember_token. API này phục vụ gán quyền quản trị; danh sách Customer A-01 tiếp tục tạm hoãn. Direct permissions đang có được giữ nguyên khi sync role; API không quản lý direct permissions.

**Catalog** trả:

```json
{
  "groups": [
    { "name": "users", "permissions": [
      { "id": 1, "name": "users.view", "action": "view", "assignable": true }
    ] }
  ],
  "role_options": [{ "id": 2, "name": "editor", "assignable": true }],
  "can_manage": true,
  "actor_id": 3
}
```

ID trong ví dụ chỉ minh họa; lấy ID thật từ API, không hardcode.

## Payload và version

`name` là tên kỹ thuật tối đa 80 ký tự, được trim, bắt đầu bằng chữ thường và chỉ gồm `a-z`, `0-9`, `_`, `-`. Tên phải unique trong guard admin.

`permission_ids` và `role_ids` phải được gửi, là mảng số nguyên distinct tối đa 100 phần tử. Mảng rỗng hợp lệ để bỏ quyền hoặc bỏ role khi các quy tắc bảo vệ cho phép.

Tạo role:

```json
{ "name": "content-editor", "permission_ids": [12, 15] }
```

Cập nhật role:

```json
{
  "name": "content-editor",
  "permission_ids": [12],
  "expected_version": "<version lấy từ GET hoặc mutation thành công gần nhất>"
}
```

Gán role admin:

```json
{
  "role_ids": [2, 5],
  "expected_version": "<version của admin user>"
}
```

`version` là SHA-256 64 ký tự: role băm tên/permission IDs, admin user băm role IDs. UI giữ version lúc đọc và gửi lại qua `expected_version`; dùng version mới từ response sau khi lưu.

HTTP 409 khi dữ liệu đã đổi: tải lại chi tiết và cho người dùng xem trạng thái mới trước khi lưu lại. Không tự retry bằng version mới với payload cũ.

## Lỗi và audit

| Status | Ý nghĩa |
| --- | --- |
| 401 | Chưa xác thực |
| 403 | Sai ability/account status, thiếu permission, ngoài scope hoặc role root được bảo vệ |
| 404 | Không có bản ghi hoặc role thuộc guard khác |
| 422 | Field sai/thiếu version, tên system role bị đổi, tự đổi role hoặc tự bỏ users.manage |
| 409 | Version cũ, role còn được sử dụng hoặc system roles chưa được khởi tạo |
| 500 | Lỗi nội bộ; dữ liệu mutation/audit cùng rollback khi transaction lỗi |

Mutation ghi `activity_log`, `log_name = access`, events `role_created`, `role_updated`, `role_deleted`, `admin_roles_updated`, actor và subject (`role`/`user`). Properties chỉ có snapshot tên role/permission IDs/role IDs trước và sau; không lưu credential. Cache Spatie được xóa sau commit để request kế tiếp dùng quyền mới, kể cả token đã cấp trước đó.

Seeder tạo permission/role còn thiếu, đồng bộ toàn bộ catalog cho super-admin và giữ permission tùy chỉnh của admin/editor/support đã tồn tại. Sau khi thay đổi catalog code, chạy `php artisan db:seed --class=RolePermissionSeeder`; quyền mới của role khác cần gán qua API.

## Vị trí triển khai

- Routes: `routes/api.php`.
- Service: `app/Services/Access/RoleManagementService.php`.
- Controllers: `RoleController`, `AccessPermissionController`, `AdminUserRoleController`.
- FormRequests: `AccessIndexRequest`, `AccessVersionRequest`, `RoleSaveRequest` (base), `RoleCreateRequest`, `RoleUpdateRequest`, `AdminUserRolesRequest`.
- Resources: `RoleResource`, `AccessPermissionResource`, `AdminRoleUserResource`.
- Tests: `tests/Feature/AccessManagementApiTest.php`.
- [Kết quả kiểm chứng](../qa/ACCESS_MANAGEMENT_2026-10-07/README.md).
