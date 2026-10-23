/**
 * =====================================================================
 * CHỨC NĂNG FILE: Wrapper tương thích — ủy quyền data User List cho user store.
 * =====================================================================
 *
 * Giữ nguyên signature cũ để `pages/apps/user/list/index.vue` không đổi.
 * Phần fetch users + role options giờ nằm trong Pinia store `user` (chia sẻ
 * state giữa list và view/[id]); composable chỉ còn giữ filter/sort/pagination
 * cục bộ và debounce search.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : filter/sort/pagination từ UI.
 * - OUTPUT: refs trỏ vào store (users/roleOptions/loading/error) + query refs.
 * - SIDE EFFECT: điều phối watcher và gọi store.fetchUsers/fetchRoleOptions.
 * =====================================================================
 */
import { computed, onMounted, onScopeDispose, shallowRef, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useUserStore } from '@/stores/user'

const DEFAULT_SORT = 'name'
const DEFAULT_DIRECTION = 'asc'

const getApiError = error => {
  const payload = error?.data ?? error?.response?._data ?? {}
  const fieldErrors = Object.values(payload.errors ?? {}).flat().filter(Boolean)

  return fieldErrors.join(' ') || payload.message || 'Không thể tải danh sách người dùng.'
}

export function useUserList() {
  const store = useUserStore()
  const { users, totalUsers, roleOptions, isLoading, error: storeError } = storeToRefs(store)

  const searchQuery = shallowRef('')
  const selectedStatus = shallowRef(null)
  const selectedRoleId = shallowRef(null)
  const itemsPerPage = shallowRef(10)
  const page = shallowRef(1)
  const sortBy = shallowRef(DEFAULT_SORT)
  const orderBy = shallowRef(DEFAULT_DIRECTION)

  const errorMessage = computed(() => (storeError.value ? getApiError(storeError.value) : ''))

  const statusOptions = [
    { title: 'Active', value: 'active' },
    { title: 'Pending', value: 'pending' },
    { title: 'Inactive', value: 'inactive' },
    { title: 'Suspended', value: 'suspended' },
  ]

  const hasActiveFilters = computed(() => Boolean(searchQuery.value || selectedStatus.value || selectedRoleId.value))

  const fetchUsers = () => store.fetchUsers({
    search: searchQuery.value,
    status: selectedStatus.value,
    roleId: selectedRoleId.value,
    page: page.value,
    perPage: itemsPerPage.value,
    sort: sortBy.value,
    direction: orderBy.value,
  })

  const fetchRoleOptions = () => store.fetchRoleOptions()

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
    // Không reset store — user list có thể mở lại; chỉ bỏ watcher cục bộ.
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
