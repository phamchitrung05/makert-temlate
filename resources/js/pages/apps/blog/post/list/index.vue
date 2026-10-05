<!--
  =====================================================================
  CHỨC NĂNG FILE: Trang danh sách Post và điều hướng tạo/chỉnh sửa bài viết.
  =====================================================================

  Trang tải danh sách Post qua Pinia store và điều hướng tới PostForm.
  Việc tạo nội dung bằng AI được quản lý tại trang AI Content.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - loadPosts(): tải danh sách theo trang, tìm kiếm và trạng thái.
  - watcher page/itemsPerPage/search: tải lại Post list khi query thay đổi.
  - errorMessage: chuẩn hóa lỗi API để hiển thị trong bảng.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : route, Post store và trạng thái query của bảng.
  - OUTPUT: bảng Post và điều hướng Add/Edit Post.
  =====================================================================
-->
<script setup>
import { computed, shallowRef, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { usePostStore } from '@/stores/post'

const router = useRouter()
const store = usePostStore()
const { items, totalItems, isLoading, error } = storeToRefs(store)
const page = shallowRef(1)
const itemsPerPage = shallowRef(15)
const search = shallowRef('')
const status = shallowRef(null)
const errorMessage = computed(() => error.value?.data?.message || error.value?.response?._data?.message || 'Không thể tải bài viết.')

/** Input: filter/page thay đổi. Output: tải lại Post bằng Pinia store. */
const loadPosts = () => store.fetchPosts({
  page: page.value,
  'per_page': itemsPerPage.value,
  search: search.value || undefined,
  status: status.value || undefined,
})

watch([search, status, itemsPerPage], () => {
  if (page.value !== 1) page.value = 1
  else void loadPosts()
})
watch(page, () => void loadPosts(), { immediate: true })
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
          prepend-icon="tabler-plus"
          @click="router.push({ name: 'apps-blog-post-add' })"
        >
          Add Post
        </VBtn>
      </div>
    </div>
    <VCard>
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="8"
          >
            <AppTextField
              v-model="search"
              placeholder="Search posts"
              clearable
            />
          </VCol>
          <VCol
            cols="12"
            md="4"
          >
            <AppSelect
              v-model="status"
              label="Status"
              :items="[{ title: 'Draft', value: 'draft' }, { title: 'Published', value: 'published' }, { title: 'Archived', value: 'archived' }]"
              clearable
            />
          </VCol>
        </VRow>
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
