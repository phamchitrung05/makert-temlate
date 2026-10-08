/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cổng gọi API quản lý role, permission và tài khoản admin.
 * =====================================================================
 * File chuẩn hóa endpoint access management để page Roles không phụ thuộc
 * trực tiếp vào cấu trúc envelope của Laravel.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - unwrap(): lấy payload data từ response envelope.
 * - listRoles(): tải danh sách role admin.
 * - getRole(): tải chi tiết một role.
 * - getCatalog(): tải catalog permission và role options.
 * - listUsers(): tải danh sách admin đang được gán role.
 * - createRole(): tạo role tùy chỉnh.
 * - updateRole(): cập nhật tên/quyền của role.
 * - deleteRole(): xóa role tùy chỉnh chưa được sử dụng.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : query/payload access management và admin Bearer token từ $api.
 * - OUTPUT: Promise payload nghiệp vụ; lỗi HTTP được giữ nguyên để UI xử lý.
 * - SIDE EFFECT: Gửi request đến API Laravel, không tự lưu state giao diện.
 * =====================================================================
 */
import { $api } from '@/utils/api'

/**
 * Lấy payload nghiệp vụ từ envelope chung của API.
 *
 * Input: response JSON từ Laravel hoặc response phẳng của mock API.
 * Output: trường data nếu envelope có data, ngược lại trả response nguyên bản.
 */
const unwrap = response => {
  if (response && typeof response === 'object' && 'success' in response && 'data' in response)
    return response.data

  return response
}

/**
 * Tải danh sách role admin theo bộ lọc phân trang.
 *
 * Input: search, page và perPage tùy chọn.
 * Output: payload gồm items/itemsLength và pagination.
 */
const listRoles = ({ search = '', page = 1, perPage = 100 } = {}) => $api('/admin/roles', {
  query: {
    search: search || undefined,
    page,
    'per_page': perPage,
  },
}).then(response => ({
  ...unwrap(response),
  pagination: response?.meta?.pagination ?? {},
}))

/**
 * Tải chi tiết role để lấy version mới nhất trước khi mutation.
 *
 * Input: roleId số nguyên.
 * Output: DTO role từ API.
 */
const getRole = roleId => $api(`/admin/roles/${roleId}`).then(unwrap)

/**
 * Tải catalog permission thật cho ma trận và dialog tạo role.
 *
 * Input: Không có ngoài admin Bearer token hiện tại.
 * Output: groups, role_options, can_manage, can_create_role, can_update_role,
 * can_delete_role và actor_id.
 */
const getCatalog = () => $api('/admin/permissions/catalog').then(unwrap)

/**
 * Tải tài khoản admin để hiển thị người dùng theo role.
 *
 * Input: query search/page/perPage tùy chọn.
 * Output: payload bảng user và pagination.
 */
const listUsers = ({ search = '', page = 1, perPage = 100 } = {}) => $api('/admin/access/users', {
  query: {
    search: search || undefined,
    page,
    'per_page': perPage,
  },
}).then(response => ({
  ...unwrap(response),
  pagination: response?.meta?.pagination ?? {},
}))

/**
 * Tạo role tùy chỉnh từ dialog.
 *
 * Input: name dạng slug và permission_ids là mảng ID permission.
 * Output: DTO role vừa tạo.
 * Side effect: Ghi role, pivot và audit qua backend transaction.
 */
const createRole = payload => $api('/admin/roles', {
  method: 'POST',
  body: payload,
}).then(unwrap)

/**
 * Cập nhật toàn bộ tên/quyền role theo optimistic version.
 *
 * Input: roleId và name/permission_ids/expected_version.
 * Output: DTO role sau mutation.
 * Side effect: Ghi role, pivot và audit qua backend transaction.
 */
const updateRole = (roleId, payload) => $api(`/admin/roles/${roleId}`, {
  method: 'PATCH',
  body: payload,
}).then(unwrap)

/**
 * Xóa role tùy chỉnh sau khi backend kiểm tra version/scope.
 *
 * Input: roleId và expectedVersion.
 * Output: Promise hoàn tất với HTTP 204.
 * Side effect: Xóa role/pivot và ghi audit nếu backend cho phép.
 */
const deleteRole = (roleId, expectedVersion) => $api(`/admin/roles/${roleId}`, {
  method: 'DELETE',
  body: { 'expected_version': expectedVersion },
}).then(unwrap)

export {
  createRole,
  deleteRole,
  getCatalog,
  getRole,
  listRoles,
  listUsers,
  updateRole,
}
