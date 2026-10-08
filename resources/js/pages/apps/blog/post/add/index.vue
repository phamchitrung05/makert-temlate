<!--
  =====================================================================
  CHỨC NĂNG FILE: Điều phối trang tạo và chỉnh sửa bài viết trong admin
  =====================================================================

  Page đọc Post từ route, nối PostForm với Pinia store và điều hướng về danh
  sách sau khi mutation thành công. Toàn bộ giao diện nhập liệu nằm trong
  PostForm để page chỉ giữ trách nhiệm orchestration.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - postId: lấy Post ID từ query string khi mở chế độ chỉnh sửa
  - errorMessage: chuẩn hóa lỗi API thành nội dung hiển thị
  - watcher postId: tải Post tương ứng hoặc reset form create
  - handleSubmit(): lưu nội dung rồi gọi lifecycle save/review/publish

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : route query `post`, state từ usePostStore
  - OUTPUT: render PostForm, gọi API qua store và điều hướng sau khi lưu
  =====================================================================
-->
<script setup>
import { computed, watch } from 'vue'
import { storeToRefs } from 'pinia'
import PostForm from '@/views/apps/blog/post/PostForm.vue'
import { usePostStore } from '@/stores/post'

const route = useRoute()
const router = useRouter()
const store = usePostStore()
const { current, isLoading, isMutating, error } = storeToRefs(store)
const postId = computed(() => Number(route.query.post) || null)
const errorMessage = computed(() => error.value?.data?.message || error.value?.response?._data?.message || error.value?.message || '')

watch(postId, id => {
  store.clearCurrent()
  if (id)
    void store.fetchPost(id)
}, { immediate: true })

/**
 * INPUT: payload Post do PostForm emit.
 * OUTPUT: không trả dữ liệu cho template.
 * SIDE EFFECT: tạo/cập nhật Post, gọi lifecycle endpoint nếu được yêu cầu và điều hướng khi thành công.
 * EXCEPTION: store giữ lỗi API để PostForm hiển thị; page không điều hướng khi lỗi.
 */
const handleSubmit = async payload => {
  try {
    const workflowAction = payload.workflowAction ?? 'save'
    const formPayload = { ...payload }

    delete formPayload.workflowAction

    // Create luôn đi qua draft; review/publish được thực hiện ở endpoint riêng.
    if (!postId.value)
      formPayload.status = 'draft'

    const saved = postId.value
      ? await store.updatePost(postId.value, formPayload)
      : await store.createPost(formPayload)

    const savedId = saved?.id ?? postId.value

    if (!savedId)
      throw new Error('Không xác định được Post sau khi lưu.')

    if (workflowAction === 'review')
      await store.submitPostReview(savedId)
    else if (workflowAction === 'publish' && saved?.status !== 'published')
      await store.publishPost(savedId)

    await router.push({ name: 'apps-blog-post-list' })
  }
  catch {}
}
</script>

<template>
  <PostForm
    :post="current"
    :loading="isLoading"
    :saving="isMutating"
    :error="errorMessage"
    @submit="handleSubmit"
    @discard="router.push({ name: 'apps-blog-post-list' })"
  />
</template>
