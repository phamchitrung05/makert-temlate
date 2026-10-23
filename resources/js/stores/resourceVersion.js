/**
 * =====================================================================
 * CHỨC NĂNG FILE: Pinia store cho Resource Version (Refactor sang createCrudStore).
 * =====================================================================
 *
 * Giữ nguyên public API cũ (fetchVersions/fetchVersion/createVersion/
 * updateVersion/readyVersion/promoteVersion/rollbackVersion/deleteVersion/
 * clearCurrent) để các page Resource Version hiện tại không phải sửa.
 * Implementation giờ đi qua factory createCrudStore.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : query, id và payload do page/form/table truyền vào.
 * - OUTPUT: state readonly + action Promise; không truy cập database.
 * =====================================================================
 */
import { resourceVersionService } from '@/services/resourceVersion'
import { createCrudStore } from '@/stores/createCrudStore'

export const useResourceVersionStore = createCrudStore('resourceVersion', resourceVersionService, {
  extraActions(service, core) {
    return {
      fetchVersions: core.actions.fetchItems,
      fetchVersion: core.actions.fetchItem,
      createVersion: core.actions.createItem,
      updateVersion: core.actions.updateItem,
      deleteVersion: core.actions.removeItem,
      readyVersion: id => core.actions.runMutation(() => service.ready(id)),
      promoteVersion: id => core.actions.runMutation(() => service.promote(id)),
      rollbackVersion: id => core.actions.runMutation(() => service.rollback(id)),
      clearCurrent: core.actions.clearCurrent,
      clearError: core.actions.clearError,
    }
  },
})
