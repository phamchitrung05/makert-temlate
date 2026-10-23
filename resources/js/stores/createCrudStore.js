/* eslint-disable camelcase -- Pagination/query keys keep Laravel snake_case contract. */

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lõi state CRUD dùng chung cho các Pinia store domain.
 * =====================================================================
 *
 * useCrudCore cung cấp state items/current/pagination/loading/error và các
 * action list/show/create/update/remove đi qua service. Mọi domain chỉ cần
 * truyền service tương ứng; store giữ nguyên contract `items` / `itemsLength`
 * / `pagination` mà BaseResponse::dataTable trả về. Revision counter bỏ qua
 * response đến trễ khi request mới hơn đã chạy.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - useCrudCore(service, options): tạo state + action CRUD dùng chung.
 * - fetchItems(params): tải danh sách; bỏ response cũ khi có request mới.
 * - fetchItem(id, fallback): tải chi tiết; hỗ trợ item tóm tắt để hiển thị ngay.
 * - createItem(payload)/updateItem(id, payload)/removeItem(id): mutation chuẩn.
 * - runMutation(callback): bọc mutation với isMutating/error; đồng bộ current.
 * - setFilters()/setPagination(): cập nhật query state; reset page khi filter đổi.
 * - applyListResponse(): đồng bộ list + pagination từ payload service.
 * - clearError()/clearCurrent()/reset(): dọn state và vô hiệu hóa request cũ.
 * - exposed: object state + action để store spread hoặc đặt tên alias.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : service { list, show, create, update, remove } và options
 *   { listDefaults, readItems, readTotal, readPagination, onMutated }.
 * - OUTPUT: reactive state readonly + các action Promise; không gọi API trực tiếp.
 * =====================================================================
 */
import { computed, reactive, readonly, shallowRef } from 'vue'

const defaultFilters = () => ({})

const defaultPagination = () => ({
  current_page: 1,
  per_page: 20,
  total: 0,
  last_page: 1,
  from: null,
  to: null,
})

/**
 * =====================================================================
 * CHỨC NĂNG: Tạo lõi state CRUD cho một domain service.
 * =====================================================================
 * INPUT: service có list/show/create/update/remove và options tùy chỉnh:
 *  - listDefaults: query mặc định gộp vào mọi fetchItems.
 *  - readItems(response): trích mảng items từ response list.
 *  - readTotal(response): trích tổng số bản ghi (fallback items.length).
 *  - readPagination(response): trích pagination object.
 *  - onMutated(item): hook sau create/update để patch thêm vào items.
 * OUTPUT: { state readonly, actions, runMutation, exposed }.
 * SIDE EFFECT: chỉ giữ state reactive; không tự fetch khi khởi tạo.
 * EXCEPTION: lỗi service được ghi vào `error`/`detailError`; không rethrow
 * ở fetchItems/fetchItem (trả null) nhưng runMutation rethrow cho mutation.
 * =====================================================================
 */
export function useCrudCore(service, options = {}) {
  const {
    listDefaults = {},
    readItems = response => response?.items ?? [],
    readTotal = response => response?.itemsLength ?? response?.pagination?.total ?? readItems(response).length,
    readPagination = response => response?.pagination ?? response?.meta?.pagination ?? null,
    onMutated = null,
  } = options

  const items = shallowRef([])
  const itemsLength = shallowRef(0)
  const current = shallowRef(null)
  const filters = reactive({ ...defaultFilters(), ...listDefaults })
  const pagination = shallowRef({ ...defaultPagination() })
  const isLoading = shallowRef(false)
  const isDetailLoading = shallowRef(false)
  const isMutating = shallowRef(false)
  const error = shallowRef(null)
  const detailError = shallowRef(null)
  let listRevision = 0
  let detailRevision = 0

  const list = computed(() => items.value)
  const detail = computed(() => current.value)
  const hasItems = computed(() => items.value.length > 0)

  const query = computed(() => ({
    ...filters,
    page: pagination.value.current_page,
    per_page: pagination.value.per_page,
  }))

  /** Input: response list service. Output: đồng bộ items + pagination. */
  const applyListResponse = response => {
    items.value = readItems(response)
    itemsLength.value = readTotal(response)
    pagination.value = {
      ...defaultPagination(),
      ...pagination.value,
      ...(readPagination(response) ?? {}),
      total: readTotal(response),
    }

    return response
  }

  /** Input: params query. Output: response mới nhất hoặc null khi stale/lỗi. */
  const fetchItems = async (params = {}) => {
    const revision = ++listRevision

    isLoading.value = true
    error.value = null

    try {
      const nextParams = { ...listDefaults, ...query.value, ...params }

      Object.assign(filters, nextParams)

      const response = await service.list(nextParams)

      if (revision !== listRevision) return null

      if (params.page)
        pagination.value = { ...pagination.value, current_page: params.page }
      if (params.per_page)
        pagination.value = { ...pagination.value, per_page: params.per_page }

      return applyListResponse(response)
    }
    catch (requestError) {
      if (revision === listRevision) error.value = requestError

      return null
    }
    finally {
      if (revision === listRevision) isLoading.value = false
    }
  }

  /** Input: id + fallback item. Output: detail mới nhất hoặc null. */
  const fetchItem = async (id, fallback = null) => {
    const revision = ++detailRevision

    if (!id) {
      current.value = null
      isDetailLoading.value = false
      detailError.value = null

      return null
    }
    if (fallback) current.value = fallback
    isDetailLoading.value = true
    detailError.value = null

    try {
      const response = await service.show(id)
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

  /**
   * Input: callback service mutation + option {syncCurrent, syncItems}.
   * Output: response hoặc rethrow lỗi. Sub-resource (shots, usage...) truyền
   * syncCurrent/syncItems=false để entity con không đè current/items của cha.
   */
  const runMutation = async (callback, { syncCurrent = true, syncItems = true } = {}) => {
    isMutating.value = true
    error.value = null

    try {
      const response = await callback()

      // Service trả `{data, message}` cho mutation → lấy data làm state, giữ response để caller đọc apiMessage
      const entity = response?.data ?? response

      if (entity?.id) {
        if (syncCurrent) current.value = entity

        if (syncItems) {
          const index = items.value.findIndex(item => item.id === entity.id)

          if (index >= 0) {
            const nextItems = [...items.value]

            nextItems[index] = entity
            items.value = nextItems
          }
        }
        onMutated?.(entity, items)
      }

      return response
    }
    catch (requestError) {
      error.value = requestError
      throw requestError
    }
    finally {
      isMutating.value = false
    }
  }

  /** Input: payload tạo. Output: entity vừa tạo (đẩy đầu list). */
  const createItem = payload => runMutation(async () => {
    const response = await service.create(payload)

    const entity = response?.data ?? response

    if (entity?.id) {
      items.value = [entity, ...items.value]
      itemsLength.value += 1
    }

    return response
  })

  /** Input: id + payload. Output: entity đã cập nhật. */
  const updateItem = (id, payload) => runMutation(() => service.update(id, payload))

  /** Input: id. Output: xóa item khỏi state sau khi service thành công. */
  const removeItem = id => runMutation(async () => {
    const response = await service.remove(id)

    items.value = items.value.filter(item => item.id !== id)
    itemsLength.value = Math.max(0, itemsLength.value - 1)
    if (current.value?.id === id) current.value = null

    return response
  })

  /** Input: filter partial. Output: state filter mới + reset page về 1. */
  const setFilters = (nextFilters = {}) => {
    Object.assign(filters, nextFilters)
    pagination.value = { ...pagination.value, current_page: 1 }
  }

  /** Input: pagination partial. Output: pagination state mới. */
  const setPagination = nextPagination => {
    pagination.value = { ...pagination.value, ...nextPagination }
  }

  /** Output: xóa lỗi request hiện tại. */
  const clearError = () => {
    error.value = null
  }

  /** Output: bỏ current và vô hiệu hóa response detail đang chạy. */
  const clearCurrent = () => {
    detailRevision++
    current.value = null
    isDetailLoading.value = false
    detailError.value = null
  }

  /** Output: khôi phục state mặc định; mọi request cũ bị bỏ. */
  const reset = () => {
    listRevision++
    detailRevision++
    items.value = []
    itemsLength.value = 0
    current.value = null
    Object.assign(filters, { ...defaultFilters(), ...listDefaults })
    pagination.value = defaultPagination()
    isLoading.value = false
    isDetailLoading.value = false
    isMutating.value = false
    error.value = null
    detailError.value = null
  }

  const state = {
    items: readonly(items),
    list,
    itemsLength: readonly(itemsLength),
    current: readonly(current),
    detail,
    filters: readonly(filters),
    pagination: readonly(pagination),
    isLoading: readonly(isLoading),
    isDetailLoading: readonly(isDetailLoading),
    isMutating: readonly(isMutating),
    error: readonly(error),
    detailError: readonly(detailError),
    hasItems,
    query,
  }

  const actions = {
    fetchItems,
    fetchItem,
    createItem,
    updateItem,
    removeItem,
    runMutation,
    setFilters,
    setPagination,
    applyListResponse,
    clearError,
    clearCurrent,
    reset,
  }

  return {
    // Raw refs cho store cần ghi trực tiếp (ví dụ cập nhật current từ lifecycle).
    _refs: { items, itemsLength, current, filters, pagination, isLoading, isDetailLoading, isMutating, error, detailError },
    state,
    actions,
    exposed: { ...state, ...actions },
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Tạo một Pinia store CRUD chuẩn từ service.
 * =====================================================================
 * INPUT: tên store, service, options của useCrudCore + extraActions(service, core)
 *  trả thêm action domain (lifecycle, sub-resource...) gộp vào store.
 * OUTPUT: composable use{Name}Store của defineStore.
 * =====================================================================
 */
import { defineStore } from 'pinia'

export function createCrudStore(name, service, options = {}) {
  const { extraActions, ...coreOptions } = options

  return defineStore(name, () => {
    const core = useCrudCore(service, coreOptions)
    const extra = typeof extraActions === 'function' ? extraActions(service, core) : {}

    return { ...core.exposed, ...extra }
  })
}
