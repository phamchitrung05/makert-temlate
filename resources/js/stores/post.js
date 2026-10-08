/**
 * =====================================================================
 * CHỨC NĂNG FILE: Quản lý state và API action của Post Admin bằng Pinia.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - fetchPosts(): tải bảng theo filter/page và bỏ response truy vấn đã cũ.
 * - fetchPost()/fetchPostAuthors(): tải chi tiết hoặc option bộ lọc tác giả.
 * - runMutation(): quản lý loading/lỗi của CRUD và lifecycle action.
 * - clearCurrent(): dọn Post đang chỉnh sửa và lỗi hiện tại.
 * INPUT/OUTPUT CỦA FILE (tổng thể): query/ID/payload -> state Post và tác giả.
 * SIDE EFFECT: gọi service API; state expose readonly, mutation không mở transaction.
 * =====================================================================
 */
import { computed, readonly, shallowRef } from 'vue'
import { defineStore } from 'pinia'
import { postService } from '@/services/post'

export const usePostStore = defineStore('post', () => {
  const items = shallowRef([])
  const authors = shallowRef([])
  const current = shallowRef(null)
  const totalItems = shallowRef(0)
  const isLoading = shallowRef(false)
  const isMutating = shallowRef(false)
  const error = shallowRef(null)
  const isAuthorsLoading = shallowRef(false)
  const authorsError = shallowRef(null)
  const hasItems = computed(() => items.value.length > 0)
  let listRequestId = 0

  /**
   * =====================================================================
   * CHỨC NĂNG: Tải Post theo bộ lọc và phân trang hiện tại
   * =====================================================================
   * INPUT: query gồm search/status/author_id/created_from/created_to/page/per_page.
   * OUTPUT: response danh sách hoặc null khi lỗi; chỉ request mới nhất cập nhật bảng.
   * SIDE EFFECT: gọi GET API, cập nhật items/totalItems/loading/error.
   * EXCEPTION/TRANSACTION: lưu lỗi API vào state; không tự mở transaction.
   * =====================================================================
   */
  const fetchPosts = async params => {
    const requestId = ++listRequestId

    isLoading.value = true
    error.value = null
    try {
      const response = await postService.list(params)

      if (requestId !== listRequestId) return null
      items.value = response.items
      totalItems.value = response.itemsLength
      
      return response
    }
    catch (requestError) {
      if (requestId !== listRequestId) return null
      error.value = requestError
      
      return null
    }
    finally {
      if (requestId === listRequestId) isLoading.value = false
    }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Tải một Post để mở màn hình edit
   * =====================================================================
   * INPUT: Post ID.
   * OUTPUT: Post đã normalize hoặc null khi lỗi.
   * SIDE EFFECT: gọi GET API, cập nhật current/isLoading/error.
   * EXCEPTION/TRANSACTION: lưu lỗi API; không mở transaction.
   * =====================================================================
   */
  const fetchPost = async id => {
    isLoading.value = true
    error.value = null
    try {
      current.value = await postService.show(id)
      
      return current.value
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
   * =====================================================================
   * CHỨC NĂNG: Tải danh sách tác giả đang có Post cho bộ lọc
   * =====================================================================
   * INPUT: không có; cần session Admin với posts.view/posts.manage.
   * OUTPUT: danh sách id/name/email hoặc [] nếu API lỗi.
   * SIDE EFFECT: gọi GET API, cập nhật authors/loading/error của bộ lọc.
   * EXCEPTION/TRANSACTION: lưu lỗi để có thể tải lại; không mở transaction.
   * =====================================================================
   */
  const fetchPostAuthors = async () => {
    isAuthorsLoading.value = true
    authorsError.value = null
    try {
      authors.value = await postService.authors()

      return authors.value
    }
    catch (requestError) {
      authorsError.value = requestError

      return []
    }
    finally {
      isAuthorsLoading.value = false
    }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Chạy một mutation Post với state loading/lỗi dùng chung
   * =====================================================================
   * INPUT: callback service đã bind payload/ID.
   * OUTPUT: response Post hoặc ném lại lỗi cho caller.
   * SIDE EFFECT: gọi API mutation, cập nhật current/isMutating/error.
   * EXCEPTION/TRANSACTION: lỗi được lưu rồi rethrow; transaction do backend.
   * =====================================================================
   */
  const runMutation = async callback => {
    isMutating.value = true
    error.value = null
    try {
      const response = await callback()

      current.value = response ?? current.value
      
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

  return {
    items: readonly(items),
    authors: readonly(authors),
    current: readonly(current),
    totalItems: readonly(totalItems),
    isLoading: readonly(isLoading),
    isMutating: readonly(isMutating),
    error: readonly(error),
    isAuthorsLoading: readonly(isAuthorsLoading),
    authorsError: readonly(authorsError),
    hasItems,
    fetchPosts,
    fetchPost,
    fetchPostAuthors,
    createPost: payload => runMutation(() => postService.create(payload)),
    updatePost: (id, payload) => runMutation(() => postService.update(id, payload)),
    submitPostReview: id => runMutation(() => postService.submitReview(id)),
    publishPost: id => runMutation(() => postService.publish(id)),
    rejectPost: (id, reason) => runMutation(() => postService.reject(id, reason)),
    archivePost: id => runMutation(() => postService.archive(id)),
    deletePost: id => runMutation(() => postService.remove(id)),
    clearCurrent: () => { current.value = null; error.value = null },
  }
})
