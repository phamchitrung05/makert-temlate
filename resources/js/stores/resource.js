/**
 * =====================================================================
 * CHỨC NĂNG FILE: Quản lý state và action dùng chung cho Resource admin
 * =====================================================================
 *
 * Store giữ danh sách/detail Resource, trạng thái request và các mutation
 * nghiệp vụ. HTTP không nằm trong page; mọi request đi qua resourceService để
 * page, table và form dùng cùng một source of truth.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - fetchResources(): tải danh sách theo query server-side
 * - fetchResource(): tải chi tiết một resource
 * - createResource(): tạo resource
 * - updateResource(): cập nhật resource
 * - deleteResource(): xoá mềm resource
 * - publishResource(): publish resource
 * - archiveResource(): archive resource
 * - clearCurrentResource(): reset detail trước khi đổi Add/Edit route
 * - clearError(): xoá lỗi request hiện tại
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : query, id và payload do page/form/table truyền vào
 * - OUTPUT: state reactive, action Promise và lỗi request; không truy cập database
 * =====================================================================
 */
import { computed, readonly, shallowRef } from 'vue'
import { defineStore } from 'pinia'
import { resourceService } from '@/services/resource'

export const useResourceStore = defineStore('resource', () => {
  const resources = shallowRef([])
  const currentResource = shallowRef(null)
  const totalItems = shallowRef(0)
  const pagination = shallowRef(null)
  const isLoading = shallowRef(false)
  const isMutating = shallowRef(false)
  const error = shallowRef(null)

  const hasResources = computed(() => resources.value.length > 0)

  /**
   * Tải danh sách resource theo filter/sort/pagination.
   *
   * Input: params query của VDataTableServer.
   * Output: object list normalized hoặc null khi request lỗi.
   * Side effect: cập nhật resources, totalItems, pagination, isLoading và error.
   */
  const fetchResources = async (params = {}) => {
    isLoading.value = true
    error.value = null

    try {
      const response = await resourceService.list(params)

      resources.value = response.items
      totalItems.value = response.itemsLength
      pagination.value = response.pagination

      return response
    }
    catch (requestError) {
      error.value = requestError

      return null
    }
    finally {
      isLoading.value = false
    }
  }

  /**
   * Tải chi tiết resource cho form.
   *
   * Input: id resource.
   * Output: ResourceItem normalized hoặc null khi request lỗi.
   * Side effect: cập nhật currentResource, isLoading và error.
   */
  const fetchResource = async id => {
    isLoading.value = true
    error.value = null

    try {
      currentResource.value = await resourceService.show(id)

      return currentResource.value
    }
    catch (requestError) {
      error.value = requestError

      return null
    }
    finally {
      isLoading.value = false
    }
  }

  /**
   * Chạy một mutation và gom loading/error behavior dùng chung.
   *
   * Input: callback gọi resourceService.
   * Output: giá trị callback trả về; lỗi được lưu vào store rồi ném lại cho page.
   * Side effect: cập nhật isMutating và error.
   */
  const runMutation = async callback => {
    isMutating.value = true
    error.value = null

    try {
      const response = await callback()

      currentResource.value = response ?? currentResource.value

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

  /**
   * Tạo resource mới.
   *
   * Input: payload form Resource.
   * Output: ResourceItem vừa tạo.
   * Side effect: cập nhật currentResource và trạng thái mutation.
   */
  const createResource = payload => runMutation(() => resourceService.create(payload))

  /**
   * Cập nhật resource hiện có.
   *
   * Input: id resource và payload form Resource.
   * Output: ResourceItem sau cập nhật.
   * Side effect: cập nhật currentResource và trạng thái mutation.
   */
  const updateResource = (id, payload) => runMutation(() => resourceService.update(id, payload))

  /**
   * Xoá mềm resource.
   *
   * Input: id resource.
   * Output: response xoá từ service.
   * Side effect: cập nhật trạng thái mutation; page chịu trách nhiệm reload list.
   */
  const deleteResource = id => runMutation(() => resourceService.remove(id))

  /**
   * Publish resource.
   *
   * Input: id resource.
   * Output: ResourceItem sau publish.
   * Side effect: cập nhật currentResource và trạng thái mutation.
   */
  const publishResource = id => runMutation(() => resourceService.publish(id))

  /**
   * Archive resource.
   *
   * Input: id resource.
   * Output: ResourceItem sau archive.
   * Side effect: cập nhật currentResource và trạng thái mutation.
   */
  const archiveResource = id => runMutation(() => resourceService.archive(id))

  /**
   * Reset detail resource khi page chuyển từ Edit sang Add hoặc resource khác.
   *
   * Input: không có.
   * Output: không trả dữ liệu; currentResource và error trở về trạng thái rỗng.
   */
  const clearCurrentResource = () => {
    currentResource.value = null
    error.value = null
  }

  /**
   * Xóa lỗi request hiện tại trước khi mở một thao tác mới.
   *
   * Input: không có.
   * Output: không trả dữ liệu; error trở về null.
   */
  const clearError = () => {
    error.value = null
  }

  return {
    resources: readonly(resources),
    currentResource: readonly(currentResource),
    totalItems: readonly(totalItems),
    pagination: readonly(pagination),
    isLoading: readonly(isLoading),
    isMutating: readonly(isMutating),
    error: readonly(error),
    hasResources,
    fetchResources,
    fetchResource,
    createResource,
    updateResource,
    deleteResource,
    publishResource,
    archiveResource,
    clearCurrentResource,
    clearError,
  }
})
