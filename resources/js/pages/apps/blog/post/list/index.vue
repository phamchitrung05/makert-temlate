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
      <VBtn
        prepend-icon="tabler-plus"
        @click="router.push({ name: 'apps-blog-post-add' })"
      >
        Add Post
      </VBtn>
    </div>
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
