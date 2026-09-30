<!--
  =====================================================================
  CHỨC NĂNG FILE: Trang danh sách Post và điểm kích hoạt AI Agent tạo draft.
  =====================================================================

  Trang tải danh sách Post qua Pinia store và mở CreateWithAiDialog khi admin
  bấm nút tạo nội dung bằng AI. Dialog chỉ trả candidate; việc lưu/publish vẫn
  thuộc PostForm và API Post.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - watcher page/itemsPerPage/search: tải lại Post list khi query thay đổi.
  - errorMessage: chuẩn hóa lỗi API để hiển thị trong bảng.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : route, Post store và trạng thái query của bảng.
  - OUTPUT: bảng Post, điều hướng Add Post và mở dialog AI; không gọi provider
  AI trực tiếp.
  =====================================================================
-->
<script setup>
import { computed, shallowRef, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { usePostStore } from '@/stores/post'
import CreateWithAiDialog from '@/views/apps/blog/post/dialog/CreateWithAiDialog.vue'

const router = useRouter()
const store = usePostStore()
const { items, totalItems, isLoading, error } = storeToRefs(store)
const page = shallowRef(1)
const itemsPerPage = shallowRef(15)
const search = shallowRef('')
const isCreateWithAiDialogVisible = shallowRef(false)
const errorMessage = computed(() => error.value?.data?.message || error.value?.response?._data?.message || 'Không thể tải bài viết.')

watch([page, itemsPerPage, search], () => void store.fetchPosts({ page: page.value, 'per_page': itemsPerPage.value, search: search.value || undefined }), { immediate: true })
</script>

<template>
  <div>
    <div class="d-flex flex-wrap justify-space-between gap-y-4 mb-6">
      <div>
        <h4 class="text-h4 font-weight-medium">
          Posts
        </h4><div class="text-body-1">
          Quản lý nội dung và media của bài viết.
        </div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <VBtn
          prepend-icon="tabler-wand"
          variant="tonal"
          color="secondary"
          @click="isCreateWithAiDialogVisible = true"
        >
          Create With AI
        </VBtn>
        <VBtn
          prepend-icon="tabler-plus"
          @click="router.push({ name: 'apps-blog-post-add' })"
        >
          Add Post
        </VBtn>
      </div>
    </div>
    <CreateWithAiDialog v-model="isCreateWithAiDialogVisible" />
    <VCard>
      <VCardText>
        <AppTextField
          v-model="search"
          placeholder="Search posts"
          clearable
        />
      </VCardText>
      <VAlert
        v-if="error"
        color="error"
        variant="tonal"
        class="ma-4"
      >
        {{ errorMessage }}
      </VAlert>
      <VDataTableServer
        v-model:items-per-page="itemsPerPage"
        v-model:page="page"
        :items="items"
        :items-length="totalItems"
        :loading="isLoading"
        :headers="[{ title: 'Title', key: 'title' }, { title: 'Status', key: 'status' }, { title: 'Thumbnail', key: 'media.thumbnail' }, { title: 'Actions', key: 'actions', sortable: false }]"
      >
        <template #item.status="{ item }">
          <VChip size="small">
            {{ item.status }}
          </VChip>
        </template>
        <template #item.media.thumbnail="{ item }">
          {{ item.media?.thumbnail?.title || '—' }}
        </template>
        <template #item.actions="{ item }">
          <VBtn
            icon="tabler-edit"
            variant="text"
            size="small"
            @click="router.push({ name: 'apps-blog-post-add', query: { post: item.id } })"
          />
        </template>
      </VDataTableServer>
    </VCard>
  </div>
</template>
