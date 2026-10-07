# A-02 — kiểm chứng backend Roles và Permissions

**Ngày:** 07/10/2026. Phạm vi đã được chủ dự án điều chỉnh: triển khai backend, để hai page Vue trống để bổ sung giao diện riêng sau.

## Đã triển khai

- CRUD role admin, gán permission từ catalog, danh sách/chi tiết/gán role tài khoản admin.
- Quyền đọc/ghi qua guard admin, hạn chế scope người quản lý, bảo vệ super-admin/system roles và tự khóa quyền quản lý.
- Version conflict, transaction lock, audit cùng transaction và invalidation cache sau commit.
- Permission catalog không có CRUD tên quyền; customer list và CASL/giao diện mới ngoài phạm vi đợt này.
- Seeder giữ permission tùy chỉnh của role mặc định đã tồn tại; super-admin tiếp tục đồng bộ catalog.
- Hai page `/apps/roles`, `/apps/permissions` chỉ có khung trống, không gọi API. Các component demo cũ không được nối vào page.

## Kiểm thử

Scoped A-02: **16 tests, 112 assertions PASS**. Các ca kiểm:

- Quyền đọc và chặn toàn bộ mutation với reader.
- Custom role CRUD, đếm người dùng, phân trang/filter và audit subject alias role.
- Role/user version cũ trả 409, không ghi đè dữ liệu mới.
- Không cấp/sửa/gán quyền ngoài phạm vi manager, không sửa root, không đổi tên/xóa system role.
- Chặn tự đổi role hoặc bỏ users.manage từ role đang dùng.
- Role còn được dùng không xóa; catalog ngoài allowlist/sai guard/duplicate/missing field bị chặn.
- Permission thay đổi có hiệu lực với token admin hiện có.
- Seeder không ghi đè role đã được quản lý; root được đồng bộ catalog.
- Không lộ credential, không trộn customer vào danh sách admin.
- Customer token (kể cả ability admin) và admin suspended đều bị chặn.
- Trigger từ chối audit khiến mutation role/pivot rollback.

| Kiểm tra | Kết quả |
| --- | --- |
| A-02 scoped | **16 tests / 112 assertions PASS** |
| Full backend | **488 tests / 3426 assertions PASS** |
| Full frontend | **51 files / 362 tests PASS** |
| Production build | **PASS**, 2896 modules, 1 phút 41 giây |
| Pint scoped | **PASS** |
| ESLint hai page trống và mock test đã sửa | **PASS** |
| Route list và git diff check | **PASS** |

Các lượt scoped nằm trong lượt full; không cộng chúng thành tổng mới. Log local: `backend-full.log`, `frontend-tests.log`, `build.log` trong thư mục báo cáo này.

Trong lượt frontend đầu, 4 ca `aiProviderModelEditing` không load catalog vì mock cũ coi mọi options là request ghi; service Settings hiện có thêm timeout/retry cho GET. Đã sửa đúng một điều kiện mock thành `options?.method`; scoped 6/6 test và full frontend đều đạt. Không thay đổi giao diện/logic AI Providers.

Build vẫn có cảnh báo asset demo `section-title-icon.png` không resolve lúc build. Đây là asset ngoài A-02; build exit 0. Các cảnh báo npm config và component stub ở test frontend không làm test thất bại.

## Giới hạn kiểm chứng

Feature tests dùng SQLite in-memory cô lập, không sửa dữ liệu ứng dụng hoặc gọi AI/provider thật. Các kiểm tra version/rollback đã đạt; chưa nghiệm thu cạnh tranh nhiều connection trên MySQL production. Giao diện mới do chủ dự án bổ sung sau; chưa có QA thao tác UI cho Roles/Permissions.

Contract để nối giao diện: [ACCESS_MANAGEMENT_API.md](../../api/ACCESS_MANAGEMENT_API.md).
