/**
 * =====================================================================
 * CHỨC NĂNG FILE: Pinia store cho User admin + Roles (access management).
 * =====================================================================
 *
 * Store giữ danh sách tài khoản admin, role options, chi tiết user và CRUD
 * role. `useUserList` giờ chỉ giữ filter/sort/pagination cục bộ, còn mọi
 * request listUsers/getUser/getCatalog và mutation role đi qua store này.
 * Revision counter bỏ response đến trễ; role options chỉ fetch một lần.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - fetchUsers(params): tải danh sách user (search/status/roleId/page/sort).
 * - fetchUser(id): tải chi tiết một admin user.
 * - fetchRoleOptions(): tải catalog role_options (cache 1 lần).
 * - fetchRoles(params)/createRole/updateRole/deleteRole: CRUD role.
 * - fetchPermissionCatalog(): catalog permission cho ma trận role.
 * - reset(): dọn state và vô hiệu hóa request cũ.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : query user/role và payload form.
 * - OUTPUT: state readonly (users/roleOptions/roles/current) + actions.
 * - SIDE EFFECT: gọi accessManagement service; không gọi API trực tiếp.
 * =====================================================================
 */
import { readonly, shallowRef } from 'vue'
import { defineStore } from 'pinia'
import {
  createRole,
  deleteRole,
  getCatalog,
  getRole,
  getUser,
  listRoles,
  listUsers,
  updateRole,
} from '@/services/accessManagement'

export const useUserStore = defineStore('user', () => {
  const users = shallowRef([])
  const totalUsers = shallowRef(0)
  const userPagination = shallowRef(null)
  const roleOptions = shallowRef([])
  const roles = shallowRef([])
  const rolesTotal = shallowRef(0)
  const current = shallowRef(null)
  const permissionCatalog = shallowRef(null)
  const isLoading = shallowRef(false)
  const isDetailLoading = shallowRef(false)
  const isMutating = shallowRef(false)
  const error = shallowRef(null)
  const detailError = shallowRef(null)
  const rolesLoaded = shallowRef(false)
  let listRevision = 0
  let detailRevision = 0

  /** Input: query {search,status,roleId,page,perPage,sort,direction}. Output: users + pagination. */
  const fetchUsers = async (params = {}) => {
    const revision = ++listRevision

    isLoading.value = true
    error.value = null

    try {
      const response = await listUsers(params)

      if (revision !== listRevision) return null
      users.value = response?.items ?? []
      totalUsers.value = Number(response?.itemsLength ?? response?.pagination?.total ?? 0)
      userPagination.value = response?.pagination ?? null

      return response
    }
    catch (requestError) {
      if (revision === listRevision) {
        users.value = []
        totalUsers.value = 0
        error.value = requestError
      }

      return null
    }
    finally {
      if (revision === listRevision) isLoading.value = false
    }
  }

  /** Input: user id. Output: detail admin user. */
  const fetchUser = async id => {
    const revision = ++detailRevision

    if (!id) {
      current.value = null
      isDetailLoading.value = false
      detailError.value = null

      return null
    }
    isDetailLoading.value = true
    detailError.value = null

    try {
      const response = await getUser(id)

      if (revision !== detailRevision) return null
      current.value = response

      return current.value
    }
    catch (requestError) {
      if (revision === detailRevision) detailError.value = requestError

      return null
    }
    finally {
      if (revision === detailRevision) isDetailLoading.value = false
    }
  }

  /** Output: role_options cho filter — chỉ fetch một lần mỗi session store. */
  const fetchRoleOptions = async () => {
    if (rolesLoaded.value) return roleOptions.value
    try {
      const catalog = await getCatalog()

      roleOptions.value = (catalog?.role_options ?? []).map(role => ({
        title: String(role?.name ?? '')
          .replace(/[-_]/g, ' ')
          .replace(/\b\w/g, c => c.toUpperCase()),
        value: role.id,
      }))
      rolesLoaded.value = true
    }
    catch {
      roleOptions.value = []
    }

    return roleOptions.value
  }

  /** Output: catalog permission đầy đủ cho ma trận role (không cache — thay đổi theo quyền actor). */
  const fetchPermissionCatalog = async () => {
    permissionCatalog.value = await getCatalog()

    return permissionCatalog.value
  }

  /** Input: query role list. Output: roles + pagination. */
  const fetchRoles = async (params = {}) => {
    const response = await listRoles(params)

    roles.value = response?.items ?? []
    rolesTotal.value = Number(response?.itemsLength ?? response?.pagination?.total ?? 0)

    return response
  }

  const runRoleMutation = async callback => {
    isMutating.value = true
    error.value = null
    try {
      return await callback()
    }
    catch (requestError) {
      error.value = requestError
      throw requestError
    }
    finally {
      isMutating.value = false
    }
  }

  const fetchRole = async id => getRole(id)
  const createRoleAction = payload => runRoleMutation(() => createRole(payload))
  const updateRoleAction = (id, payload) => runRoleMutation(() => updateRole(id, payload))
  const deleteRoleAction = (id, expectedVersion) => runRoleMutation(() => deleteRole(id, expectedVersion))

  /** Output: dọn toàn bộ state. */
  const reset = () => {
    listRevision++
    detailRevision++
    users.value = []
    totalUsers.value = 0
    userPagination.value = null
    roleOptions.value = []
    roles.value = []
    rolesTotal.value = 0
    current.value = null
    permissionCatalog.value = null
    isLoading.value = false
    isDetailLoading.value = false
    isMutating.value = false
    error.value = null
    detailError.value = null
    rolesLoaded.value = false
  }

  return {
    users: readonly(users),
    totalUsers: readonly(totalUsers),
    userPagination: readonly(userPagination),
    roleOptions: readonly(roleOptions),
    roles: readonly(roles),
    rolesTotal: readonly(rolesTotal),
    current: readonly(current),
    permissionCatalog: readonly(permissionCatalog),
    isLoading: readonly(isLoading),
    isDetailLoading: readonly(isDetailLoading),
    isMutating: readonly(isMutating),
    error: readonly(error),
    detailError: readonly(detailError),
    fetchUsers,
    fetchUser,
    fetchRoleOptions,
    fetchPermissionCatalog,
    fetchRoles,
    fetchRole,
    createRole: createRoleAction,
    updateRole: updateRoleAction,
    deleteRole: deleteRoleAction,
    reset,
  }
})
