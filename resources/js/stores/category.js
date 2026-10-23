/**
 * =====================================================================
 * CHỨC NĂNG FILE: Pinia store cho Category (taxonomy dùng chung).
 * =====================================================================
 * Store giữ list/detail category và các mutation CRUD qua categoryService.
 * Taxonomy page và các dialog cần danh mục (VisualStyleEditDialog, PostForm...)
 * dùng chung store này thay vì gọi service trực tiếp, giữ một source of truth
 * và tránh fetch lặp.
 *
 * CÁC HÀM/METHOD TRONG FILE (alias của createCrudStore):
 * - fetchCategories/fetchCategory/createCategory/updateCategory/removeCategory:
 *   CRUD chuẩn (fetchItems/fetchItem/createItem/updateItem/removeItem).
 * - fetchAllCategories(): tải toàn bộ (per_page lớn) làm option cho select.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : query/id/payload từ pages và dialogs.
 * - OUTPUT: state readonly (items/current/pagination/loading/error) + actions.
 * =====================================================================
 */
import { categoryService } from '@/services/category'
import { createCrudStore } from '@/stores/createCrudStore'

export const useCategoryStore = createCrudStore('category', categoryService, {
  listDefaults: { perPage: 100 },

  extraActions(service, core) {
    /** Output: toàn bộ category làm option; tái dùng fetchItems với perPage lớn. */
    const fetchAllCategories = () => core.actions.fetchItems({ perPage: 500, page: 1 })

    return {
      fetchCategories: core.actions.fetchItems,
      fetchCategory: core.actions.fetchItem,
      createCategory: core.actions.createItem,
      updateCategory: core.actions.updateItem,
      removeCategory: core.actions.removeItem,
      fetchAllCategories,
    }
  },
})
