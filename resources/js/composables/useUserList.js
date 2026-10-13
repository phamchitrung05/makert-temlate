import { computed, onMounted, onScopeDispose, shallowRef, watch } from 'vue'
import { getCatalog, listUsers } from '@/services/accessManagement'

const DEFAULT_SORT = 'name'
const DEFAULT_DIRECTION = 'asc'

const getApiError = error => {
  const payload = error?.data ?? error?.response?._data ?? {}
  const fieldErrors = Object.values(payload.errors ?? {}).flat().filter(Boolean)

  return fieldErrors.join(' ') || payload.message || 'Không thể tải danh sách người dùng.'
}

const formatRoleTitle = role => String(role ?? '')
  .replace(/[-_]/g, ' ')
  .replace(/\b\w/g, character => character.toUpperCase())

/**
 * Quản lý query, tải dữ liệu và trạng thái của bảng User server-side.
 *
 * Input: filter, sort và pagination state từ màn hình User List.
 * Output: rows, total, options và actions để page trình bày bằng các component con.
 * Side effect: gọi API access management; request cũ không được phép ghi đè request mới.
 */
export function useUserList() {
  const users = shallowRef([])
  const totalUsers = shallowRef(0)
  const roleOptions = shallowRef([])
  const searchQuery = shallowRef('')
  const selectedStatus = shallowRef(null)
  const selectedRoleId = shallowRef(null)
  const itemsPerPage = shallowRef(10)
  const page = shallowRef(1)
  const sortBy = shallowRef(DEFAULT_SORT)
  const orderBy = shallowRef(DEFAULT_DIRECTION)
  const isLoading = shallowRef(false)
  const errorMessage = shallowRef('')

  const statusOptions = [
    { title: 'Active', value: 'active' },
    { title: 'Pending', value: 'pending' },
    { title: 'Inactive', value: 'inactive' },
    { title: 'Suspended', value: 'suspended' },
  ]

  let latestRequestId = 0

  const hasActiveFilters = computed(() => Boolean(searchQuery.value || selectedStatus.value || selectedRoleId.value))

  const fetchUsers = async () => {
    const requestId = ++latestRequestId

    isLoading.value = true
    errorMessage.value = ''

    try {
      const response = await listUsers({
        search: searchQuery.value,
        status: selectedStatus.value,
        roleId: selectedRoleId.value,
        page: page.value,
        perPage: itemsPerPage.value,
        sort: sortBy.value,
        direction: orderBy.value,
      })

      if (requestId !== latestRequestId)
        return

      users.value = response?.items ?? []
      totalUsers.value = Number(response?.itemsLength ?? response?.pagination?.total ?? 0)
    }
    catch (error) {
      if (requestId !== latestRequestId)
        return

      users.value = []
      totalUsers.value = 0
      errorMessage.value = getApiError(error)
    }
    finally {
      if (requestId === latestRequestId)
        isLoading.value = false
    }
  }

  const fetchRoleOptions = async () => {
    try {
      const catalog = await getCatalog()

      roleOptions.value = (catalog?.role_options ?? []).map(role => ({
        title: formatRoleTitle(role.name),
        value: role.id,
      }))
    }
    catch {
      // The user table remains useful when the optional role catalog is unavailable.
      roleOptions.value = []
    }
  }

  const updateTableOptions = options => {
    const descriptor = options?.sortBy?.[0]

    if (!descriptor || !['name', 'email', 'username', 'status', 'created_at'].includes(descriptor.key))
      return

    sortBy.value = descriptor.key
    orderBy.value = descriptor.order || DEFAULT_DIRECTION
  }

  const resetFilters = () => {
    searchQuery.value = ''
    selectedStatus.value = null
    selectedRoleId.value = null
    page.value = 1
  }

  onMounted(fetchRoleOptions)

  watch(
    [searchQuery, selectedStatus, selectedRoleId, itemsPerPage, page, sortBy, orderBy],
    (values, previousValues, onCleanup) => {
      const filterChanged = previousValues
        && values.slice(0, 4).some((value, index) => value !== previousValues[index])

      const sortChanged = previousValues
        && values.slice(5).some((value, index) => value !== previousValues[index + 5])

      if ((filterChanged || sortChanged) && page.value !== 1) {
        page.value = 1

        return
      }

      const searchChanged = previousValues && values[0] !== previousValues[0]
      const timer = setTimeout(() => void fetchUsers(), searchChanged ? 300 : 0)

      onCleanup(() => clearTimeout(timer))
    },
    { immediate: true },
  )

  onScopeDispose(() => {
    latestRequestId += 1
  })

  return {
    users,
    totalUsers,
    roleOptions,
    statusOptions,
    searchQuery,
    selectedStatus,
    selectedRoleId,
    itemsPerPage,
    page,
    sortBy,
    orderBy,
    isLoading,
    errorMessage,
    hasActiveFilters,
    fetchUsers,
    resetFilters,
    updateTableOptions,
  }
}
