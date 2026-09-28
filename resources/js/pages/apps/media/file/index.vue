<!--
  =====================================================================
  CHỨC NĂNG FILE: Điều phối màn hình quản lý file Media Library
  =====================================================================

  Page giữ query/filter/pagination state, nối MediaAssetTable và upload dialog
  với Pinia media asset store. HTTP không nằm trong page; service/store là
  boundary chung cho fake API và Laravel API thật.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - fetchAssets(): tải danh sách theo query hiện tại
  - syncQueryToUrl(): giữ filter/pagination/sort trong URL
  - handleUpload/delete/retry/download(): điều phối mutation từ UI
  - errorMessage(): chuyển lỗi API thành message hiển thị được
  - watcher query state: reload list khi filter/pagination thay đổi

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : route query, thao tác filter/table/upload và MediaAsset store
  - OUTPUT: màn hình File trong nhóm Media, dialog upload/detail và feedback UI
  =====================================================================
-->
<script setup>
import { computed, shallowRef, watch } from 'vue'
import { storeToRefs } from 'pinia'
import MediaAssetDetails from '@/views/apps/media/MediaAssetDetails.vue'
import MediaAssetTable from '@/views/apps/media/MediaAssetTable.vue'
import MediaAssetUploadDialog from '@/views/apps/media/MediaAssetUploadDialog.vue'
import { useMediaAssetStore } from '@/stores/mediaAsset'

const route = useRoute()
const router = useRouter()
const mediaAssetStore = useMediaAssetStore()

const {
  items,
  itemsLength,
  selectedAsset,
  isLoading,
  isMutating,
  uploadProgress,
  error,
} = storeToRefs(mediaAssetStore)

const searchQuery = shallowRef(String(route.query.search ?? ''))
const selectedKind = shallowRef(route.query.kind || null)
const selectedVisibility = shallowRef(route.query.visibility || null)
const selectedScanStatus = shallowRef(route.query['scan_status'] || null)
const itemsPerPage = shallowRef(Number(route.query['per_page']) || 20)
const page = shallowRef(Number(route.query.page) || 1)
const sortBy = shallowRef(route.query.sort || 'created_at')
const orderBy = shallowRef(route.query.direction || 'desc')
const isUploadDialogVisible = shallowRef(false)
const isDetailsVisible = shallowRef(false)
const feedbackMessage = shallowRef('')
const feedbackColor = shallowRef('success')
const isFeedbackVisible = shallowRef(false)

const kindOptions = [
  { title: 'Image', value: 'image' },
  { title: 'Document', value: 'document' },
  { title: 'Archive', value: 'archive' },
  { title: 'Video', value: 'video' },
]

const visibilityOptions = [
  { title: 'Public', value: 'public' },
  { title: 'Private', value: 'private' },
]

const scanStatusOptions = [
  { title: 'Pending', value: 'pending' },
  { title: 'Clean', value: 'clean' },
  { title: 'Rejected', value: 'rejected' },
  { title: 'Error', value: 'error' },
]

const hasItems = computed(() => items.value.length > 0)
const hasError = computed(() => Boolean(error.value))

/**
 * Chuyển lỗi ofetch/BaseResponse thành message người dùng đọc được.
 *
 * Input: error reactive từ MediaAsset store.
 * Output: message ưu tiên lỗi validation theo field hoặc message API.
 */
const errorMessage = computed(() => {
  const payload = error.value?.data ?? error.value?.response?._data ?? {}
  const fieldErrors = payload.errors ?? {}
  const messages = Object.values(fieldErrors).flat().filter(Boolean)

  return messages.join(' ') || payload.message || 'Không thể tải danh sách file.'
})

/**
 * Tải danh sách theo filter, pagination và sort hiện tại.
 *
 * Input: state query của màn hình File.
 * Output: Promise từ Pinia store; store cập nhật items/loading/error.
 */
const fetchAssets = () => mediaAssetStore.fetchMediaAssets({
  search: searchQuery.value || undefined,
  kind: selectedKind.value || undefined,
  visibility: selectedVisibility.value || undefined,
  'scan_status': selectedScanStatus.value || undefined,
  page: page.value,
  'per_page': itemsPerPage.value,
  sort: sortBy.value,
  direction: orderBy.value,
})

/**
 * Giữ query filter hiện tại trong URL để refresh/bookmark không mất ngữ cảnh.
 *
 * Input: state search/filter/page/sort của page.
 * Output: Promise navigation replace; không tạo thêm history entry.
 */
const syncQueryToUrl = () => {
  const query = {}

  if (searchQuery.value)
    query.search = searchQuery.value
  if (selectedKind.value)
    query.kind = selectedKind.value
  if (selectedVisibility.value)
    query.visibility = selectedVisibility.value
  if (selectedScanStatus.value)
    query['scan_status'] = selectedScanStatus.value
  if (page.value !== 1)
    query.page = String(page.value)
  if (itemsPerPage.value !== 20)
    query['per_page'] = String(itemsPerPage.value)
  if (sortBy.value !== 'created_at')
    query.sort = sortBy.value
  if (orderBy.value !== 'desc')
    query.direction = orderBy.value

  return router.replace({ query })
}

/**
 * Hiển thị snackbar feedback sau mutation hoặc thao tác download.
 *
 * Input: message và màu Vuetify.
 * Output: không trả dữ liệu; mở snackbar ngắn ở góc màn hình.
 */
const showFeedback = (message, color = 'success') => {
  feedbackMessage.value = message
  feedbackColor.value = color
  isFeedbackVisible.value = true
}

/**
 * Nhận sort descriptor từ VDataTableServer.
 *
 * Input: options gồm sortBy descriptor của Vuetify.
 * Output: cập nhật sort state để watcher tải lại server-side.
 */
const updateOptions = options => {
  const descriptor = options.sortBy?.[0]

  if (!descriptor)
    return

  sortBy.value = descriptor.key
  orderBy.value = descriptor.order
}

/**
 * Reset filter và quay về trang đầu tiên.
 *
 * Input: không có.
 * Output: query state mặc định; watcher sẽ tải lại list.
 */
const resetFilters = () => {
  searchQuery.value = ''
  selectedKind.value = null
  selectedVisibility.value = null
  selectedScanStatus.value = null
  page.value = 1
}

/**
 * Upload file qua Pinia store và đóng dialog khi backend trả thành công.
 *
 * Input: payload form gồm File và metadata.
 * Output: Promise upload; list được tải lại sau khi tạo asset.
 * Exception: lỗi được giữ trong store và hiển thị tại dialog.
 */
const handleUpload = async payload => {
  const file = Array.isArray(payload.file) ? payload.file[0] : payload.file

  try {
    await mediaAssetStore.uploadMediaAsset({ ...payload, file })
    isUploadDialogVisible.value = false
    showFeedback('Upload file thành công.')
    await fetchAssets()
  }
  catch {
    showFeedback(errorMessage.value, 'error')
  }
}

/**
 * Mở detail dialog và tải detail đầy đủ của asset.
 *
 * Input: asset row từ bảng.
 * Output: Promise fetch detail; selectedAsset được store cập nhật.
 */
const handleSelect = async asset => {
  await mediaAssetStore.fetchMediaAsset(asset.id)

  if (selectedAsset.value)
    isDetailsVisible.value = true
}

/**
 * Soft-delete asset sau khi admin xác nhận.
 *
 * Input: asset row từ bảng.
 * Output: Promise delete và reload list.
 * Exception: lỗi usage/permission hiển thị qua snackbar.
 */
const handleDelete = async asset => {
  if (typeof window !== 'undefined' && !window.confirm(`Xóa file "${asset.title}"?`))
    return

  try {
    await mediaAssetStore.deleteMediaAsset(asset.id)
    showFeedback('File đã được xóa.')
    await fetchAssets()
  }
  catch {
    showFeedback(errorMessage.value, 'error')
  }
}

/**
 * Retry scan hoặc conversion theo trạng thái backend.
 *
 * Input: asset row có scan/conversion error.
 * Output: Promise retry và reload list.
 * Exception: lỗi retry hiển thị qua snackbar.
 */
const handleRetry = async asset => {
  try {
    await mediaAssetStore.retryMediaAsset(asset.id)
    showFeedback('File đã được đưa vào hàng đợi xử lý lại.')
    await fetchAssets()
  }
  catch {
    showFeedback(errorMessage.value, 'error')
  }
}

/**
 * Lấy payload download qua backend và mở URL public/temporary nếu có.
 *
 * Input: asset row từ bảng.
 * Output: Promise download; private asset không tự suy đoán storage path.
 */
const handleDownload = async asset => {
  try {
    const payload = await mediaAssetStore.downloadMediaAsset(asset.id)
    const url = payload?.url ?? payload?.temporary_url ?? payload?.download_url

    if (!url) {
      showFeedback('Download chưa có URL hợp lệ.', 'warning')

      return
    }

    window.open(url, '_blank', 'noopener,noreferrer')
  }
  catch {
    showFeedback(errorMessage.value, 'error')
  }
}

watch(
  [searchQuery, selectedKind, selectedVisibility, selectedScanStatus, itemsPerPage, page, sortBy, orderBy],
  (values, previousValues) => {
    const filterChanged = previousValues
      && values.slice(0, 4).some((value, index) => value !== previousValues[index])

    if (filterChanged && page.value !== 1) {
      page.value = 1

      return
    }

    void syncQueryToUrl()
    void fetchAssets()
  },
  { immediate: true },
)
</script>

<template>
  <div>
    <div class="d-flex flex-wrap justify-space-between gap-y-4 gap-x-6 mb-6">
      <div class="d-flex flex-column justify-center">
        <h4 class="text-h4 font-weight-medium">
          Media Files
        </h4>
        <div class="text-body-1">
          Quản lý file dùng chung cho Resource, Post và Resource Version.
        </div>
      </div>

      <div class="d-flex gap-4 align-center flex-wrap">
        <VBtn
          variant="tonal"
          color="secondary"
          prepend-icon="tabler-refresh"
          :loading="isLoading"
          @click="fetchAssets"
        >
          Refresh
        </VBtn>
        <VBtn
          prepend-icon="tabler-upload"
          @click="isUploadDialogVisible = true"
        >
          Upload file
        </VBtn>
      </div>
    </div>

    <VCard>
      <VCardText>
        <div class="d-flex flex-wrap gap-4 align-center">
          <AppTextField
            v-model="searchQuery"
            placeholder="Search files"
            prepend-inner-icon="tabler-search"
            clearable
            style="min-inline-size: 240px; max-inline-size: 300px;"
          />
          <AppSelect
            v-model="selectedKind"
            placeholder="Kind"
            :items="kindOptions"
            clearable
            clear-icon="tabler-x"
            style="min-inline-size: 150px;"
          />
          <AppSelect
            v-model="selectedVisibility"
            placeholder="Visibility"
            :items="visibilityOptions"
            clearable
            clear-icon="tabler-x"
            style="min-inline-size: 150px;"
          />
          <AppSelect
            v-model="selectedScanStatus"
            placeholder="Scan status"
            :items="scanStatusOptions"
            clearable
            clear-icon="tabler-x"
            style="min-inline-size: 160px;"
          />
          <VSpacer />
          <VBtn
            variant="text"
            color="secondary"
            @click="resetFilters"
          >
            Clear filters
          </VBtn>
          <AppSelect
            v-model="itemsPerPage"
            :items="[10, 20, 50]"
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
          @click="fetchAssets"
        >
          Thử lại
        </VBtn>
      </VAlert>

      <VAlert
        v-if="!isLoading && !hasError && !hasItems"
        color="info"
        variant="tonal"
        class="ma-4"
      >
        Chưa có file phù hợp với bộ lọc hiện tại.
      </VAlert>

      <MediaAssetTable
        :assets="items"
        :items-per-page="itemsPerPage"
        :page="page"
        :total-items="itemsLength"
        :loading="isLoading"
        @update:items-per-page="itemsPerPage = $event"
        @update:page="page = $event"
        @update:options="updateOptions"
        @select="handleSelect"
        @retry="handleRetry"
        @download="handleDownload"
        @delete="handleDelete"
      />
    </VCard>

    <MediaAssetUploadDialog
      v-model="isUploadDialogVisible"
      :loading="isMutating"
      :progress="uploadProgress"
      :error="hasError ? errorMessage : ''"
      @submit="handleUpload"
    />

    <MediaAssetDetails
      v-model="isDetailsVisible"
      :asset="selectedAsset"
    />

    <VSnackbar
      v-model="isFeedbackVisible"
      :color="feedbackColor"
      location="top end"
    >
      {{ feedbackMessage }}
    </VSnackbar>
  </div>
</template>
