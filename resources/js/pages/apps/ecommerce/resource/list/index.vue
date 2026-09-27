<!--
  =====================================================================
  CHỨC NĂNG FILE: Điều phối danh sách Resource trong Ecommerce admin
  =====================================================================

  Page giữ query/filter state và nối ResourceTable với Pinia resource store.
  Store chịu trách nhiệm request; page chỉ dựng query, điều phối row action và
  navigation nên có thể chuyển fake API sang Laravel API mà không đổi table.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - updateOptions(): nhận sort từ VDataTableServer
  - fetchResources(): gửi query hiện tại tới resource store
  - errorMessage(): chuyển lỗi API thành message hiển thị được
  - handlePublish/archive/delete(): điều phối mutation từ row action
  - watcher query state: reload list khi filter/pagination/sort thay đổi

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : thao tác filter/table và state từ useResourceStore
  - OUTPUT: bộ lọc, bảng server-side, feedback mutation và navigation Add/Edit
  =====================================================================
-->
<script setup>
import { computed, shallowRef, watch } from 'vue'
import { storeToRefs } from 'pinia'
import ResourceTable from '@/views/apps/ecommerce/resource/ResourceTable.vue'
import { useResourceStore } from '@/stores/resource'

const resourceStore = useResourceStore()
const { resources, totalItems, isLoading, error } = storeToRefs(resourceStore)

const searchQuery = shallowRef('')
const selectedStatus = shallowRef()
const selectedType = shallowRef()
const itemsPerPage = shallowRef(10)
const page = shallowRef(1)
const sortBy = shallowRef()
const orderBy = shallowRef()
const feedbackMessage = shallowRef('')
const feedbackColor = shallowRef('success')
const isFeedbackVisible = shallowRef(false)

const statusOptions = [
  { title: 'Published', value: 'published' },
  { title: 'Pending review', value: 'pending_review' },
  { title: 'Draft', value: 'draft' },
  { title: 'Suspended', value: 'suspended' },
  { title: 'Archived', value: 'archived' },
]

const typeOptions = [
  { title: 'Template', value: 'template' },
  { title: 'UI kit', value: 'ui_kit' },
  { title: 'Component', value: 'component' },
  { title: 'Theme', value: 'theme' },
  { title: 'Snippet', value: 'snippet' },
  { title: 'Plugin', value: 'plugin' },
  { title: 'Icon pack', value: 'icon_pack' },
  { title: 'Ebook', value: 'ebook' },
  { title: 'Course', value: 'course' },
]

const hasError = computed(() => Boolean(error.value))
const hasResources = computed(() => resources.value.length > 0)

/**
 * Chuyển lỗi ofetch/BaseResponse thành message hiển thị ở alert.
 *
 * Input: error reactive từ Resource store.
 * Output: message tiếng Việt, ưu tiên lỗi validation theo field.
 */
const errorMessage = computed(() => {
  const payload = error.value?.data ?? error.value?.response?._data ?? {}
  const fieldErrors = payload.errors ?? {}
  const messages = Object.values(fieldErrors).flat().filter(Boolean)

  return messages.join(' ') || payload.message || 'Không thể tải danh sách resource.'
})

/**
 * Nhận thay đổi sort từ VDataTableServer.
 *
 * Input: options gồm sortBy descriptor của Vuetify.
 * Output: không trả dữ liệu; cập nhật sortBy/orderBy để watcher reload list.
 */
const updateOptions = options => {
  sortBy.value = options.sortBy?.[0]?.key
  orderBy.value = options.sortBy?.[0]?.order
}

/**
 * Tải list theo query state hiện tại.
 *
 * Input: state search/filter/pagination/sort của page.
 * Output: Promise từ resource store; lỗi được store giữ lại để hiển thị.
 */
const fetchResources = () => resourceStore.fetchResources({
  search: searchQuery.value,
  status: selectedStatus.value,
  type: selectedType.value,
  page: page.value,
  'per_page': itemsPerPage.value,
  sortBy: sortBy.value,
  orderBy: orderBy.value,
})

/**
 * Hiển thị feedback ngắn sau mutation thành công hoặc thất bại.
 *
 * Input: message và color Vuetify.
 * Output: không trả dữ liệu; mở snackbar.
 */
const showFeedback = (message, color = 'success') => {
  feedbackMessage.value = message
  feedbackColor.value = color
  isFeedbackVisible.value = true
}

/**
 * Publish một dòng resource từ action menu.
 *
 * Input: item resource từ ResourceTable.
 * Output: Promise mutation và reload list.
 * Exception: lỗi được store giữ lại; snackbar hiển thị thông báo.
 */
const handlePublish = async item => {
  try {
    await resourceStore.publishResource(item.id)
    showFeedback('Resource đã được publish thành công.')
    await fetchResources()
  }
  catch {
    showFeedback(errorMessage.value, 'error')
  }
}

/**
 * Archive một dòng resource từ action menu.
 *
 * Input: item resource từ ResourceTable.
 * Output: Promise mutation và reload list.
 * Exception: lỗi được store giữ lại; snackbar hiển thị thông báo.
 */
const handleArchive = async item => {
  try {
    await resourceStore.archiveResource(item.id)
    showFeedback('Resource đã được archive thành công.')
    await fetchResources()
  }
  catch {
    showFeedback(errorMessage.value, 'error')
  }
}

/**
 * Xoá mềm một dòng resource sau khi admin xác nhận.
 *
 * Input: item resource từ ResourceTable.
 * Output: Promise mutation và reload list.
 * Exception: lỗi được store giữ lại; snackbar hiển thị thông báo.
 */
const handleDelete = async item => {
  if (typeof window !== 'undefined' && !window.confirm(`Xoá resource "${item.title}"?`))
    return

  try {
    await resourceStore.deleteResource(item.id)
    showFeedback('Resource đã được xoá mềm.')
    await fetchResources()
  }
  catch {
    showFeedback(errorMessage.value, 'error')
  }
}

watch(
  [searchQuery, selectedStatus, selectedType, itemsPerPage, page, sortBy, orderBy],
  (values, previousValues) => {
    const filtersChanged = previousValues
      && values.slice(0, 3).some((value, index) => value !== previousValues[index])

    if (filtersChanged && page.value !== 1) {
      page.value = 1

      return
    }

    void fetchResources()
  },
  { immediate: true },
)
</script>

<template>
  <div>
    <div class="d-flex flex-wrap justify-space-between gap-y-4 gap-x-6 mb-6">
      <div class="d-flex flex-column justify-center">
        <h4 class="text-h4 font-weight-medium">
          Resources
        </h4>
        <div class="text-body-1">
          Quản lý tài nguyên số trong cửa hàng.
        </div>
      </div>

      <div class="d-flex gap-4 align-center flex-wrap">
        <VBtn
          variant="tonal"
          color="secondary"
          prepend-icon="tabler-refresh"
          :loading="isLoading"
          @click="fetchResources"
        >
          Refresh
        </VBtn>
        <VBtn
          prepend-icon="tabler-plus"
          :to="{ name: 'apps-ecommerce-resource-add' }"
        >
          Add Resource
        </VBtn>
      </div>
    </div>

    <VCard>
      <VCardText>
        <div class="d-flex flex-wrap gap-4 align-center">
          <AppTextField
            v-model="searchQuery"
            placeholder="Search resource"
            style="max-inline-size: 280px; min-inline-size: 220px;"
            clearable
          />
          <AppSelect
            v-model="selectedStatus"
            placeholder="Status"
            :items="statusOptions"
            clearable
            clear-icon="tabler-x"
            style="min-inline-size: 160px;"
          />
          <AppSelect
            v-model="selectedType"
            placeholder="Type"
            :items="typeOptions"
            clearable
            clear-icon="tabler-x"
            style="min-inline-size: 160px;"
          />
          <VSpacer />
          <AppSelect
            v-model="itemsPerPage"
            :items="[5, 10, 20, 50]"
            style="min-inline-size: 90px;"
          />
        </div>
      </VCardText>

      <VDivider />

      <VAlert
        v-if="hasError"
        color="error"
        variant="tonal"
        class="ma-4"
      >
        {{ errorMessage }}
        <VBtn
          variant="text"
          color="error"
          class="ms-2"
          @click="fetchResources"
        >
          Thử lại
        </VBtn>
      </VAlert>

      <VAlert
        v-if="!isLoading && !hasError && !hasResources"
        color="info"
        variant="tonal"
        class="ma-4"
      >
        Không có resource phù hợp với bộ lọc hiện tại.
      </VAlert>

      <ResourceTable
        :resources="resources"
        :items-per-page="itemsPerPage"
        :page="page"
        :total-items="totalItems"
        :loading="isLoading"
        @update:items-per-page="itemsPerPage = $event"
        @update:page="page = $event"
        @update:options="updateOptions"
        @publish="handlePublish"
        @archive="handleArchive"
        @delete="handleDelete"
      />
    </VCard>

    <VSnackbar
      v-model="isFeedbackVisible"
      :color="feedbackColor"
      location="top end"
    >
      {{ feedbackMessage }}
    </VSnackbar>
  </div>
</template>
