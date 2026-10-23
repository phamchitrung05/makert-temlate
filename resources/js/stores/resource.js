/**
 * =====================================================================
 * CHỨC NĂNG FILE: Pinia store cho Resource admin (Refactor sang createCrudStore).
 * =====================================================================
 *
 * Giữ nguyên public API cũ (fetchResources/fetchResource/createResource/
 * updateResource/deleteResource/publishResource/archiveResource/clearCurrentResource/
 * clearError) để các page Resource hiện tại không phải sửa. Implementation
 * giờ đi qua factory createCrudStore — giảm code trùng và chuẩn hóa với các
 * store domain khác.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : query, id và payload do page/form/table truyền vào.
 * - OUTPUT: state readonly + action Promise; không truy cập database.
 * =====================================================================
 */
import { resourceService } from '@/services/resource'
import { createCrudStore } from '@/stores/createCrudStore'

export const useResourceStore = createCrudStore('resource', resourceService, {
  extraActions(service, core) {
    return {
      // Giữ tên method cũ để pages không đổi
      fetchResources: core.actions.fetchItems,
      fetchResource: core.actions.fetchItem,
      createResource: core.actions.createItem,
      updateResource: core.actions.updateItem,
      deleteResource: core.actions.removeItem,
      publishResource: id => core.actions.runMutation(() => service.publish(id)),
      archiveResource: id => core.actions.runMutation(() => service.archive(id)),
      clearCurrentResource: core.actions.clearCurrent,
      clearError: core.actions.clearError,

      // Alias state cũ
      resources: core.state.list,
      currentResource: core.state.detail,
      totalItems: core.state.itemsLength,
    }
  },
})
