<!--
  =====================================================================
  CHỨC NĂNG FILE: Điều phối dialog chọn asset trong Media Library
  =====================================================================

  Dialog giữ query, filter, pagination và selection cục bộ; request đi qua
  mediaAsset store. Component có thể dùng trong Resource, Post và Resource
  Version mà không biết cách attach nghiệp vụ cụ thể của form cha.

  CÁC HÀM/METHOD TRONG FILE:
  - fetchAssets(): tải danh sách theo kind/field/filter hiện tại
  - toggleAsset()/confirmSelection(): xử lý single/multiple selection
  - handleUpload()/handleRetry(): điều phối mutation qua store
  - errorMessage()/canAttach/canUpload(): trạng thái UI và capability
  - resetSelection(): sao chép lựa chọn hiện có vào draft
  - close()/handleDialogUpdate(): đóng dialog không commit draft
  - showFeedback()/showDetails()/focusSearch(): feedback, chi tiết và focus

  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : open, kind, field, multiple, visibility, initialSelection và capability.
  - OUTPUT: update:open, select asset hoặc asset array.
  =====================================================================
-->
<script setup>
import { computed, nextTick, shallowRef, useTemplateRef, watch } from 'vue'
import { storeToRefs } from 'pinia'
import MediaAssetDetails from '@/views/apps/media/MediaAssetDetails.vue'
import { useMediaAssetStore } from '@/stores/mediaAsset'
import MediaAssetGrid from './MediaAssetGrid.vue'
import MediaUploadDropZone from './MediaUploadDropZone.vue'
import { getMediaAssetFieldConfig } from './mediaAssetFields'
import { useMediaCapabilities } from './useMediaCapabilities'

const props = defineProps({
  initialSelection: {
    type: Array,
    default: () => [],
  },
  open: {
    type: Boolean,
    default: false,
  },
  kind: {
    type: String,
    default: null,
  },
  field: {
    type: String,
    default: '',
  },
  multiple: {
    type: Boolean,
    default: false,
  },
  visibility: {
    type: String,
    default: null,
  },
  canAttach: {
    type: Boolean,
    default: null,
  },
  canUpload: {
    type: Boolean,
    default: null,
  },
})

const emit = defineEmits(['update:open', 'select'])

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

const {
  canAttach: authCanAttach,
  canUpload: authCanUpload,
  canRetry: authCanRetry,
} = useMediaCapabilities()

const searchInput = useTemplateRef('searchInput')
const searchQuery = shallowRef('')
const selectedVisibility = shallowRef(props.visibility)
const selectedScanStatus = shallowRef(null)
const page = shallowRef(1)
const itemsPerPage = shallowRef(12)
const selectedAssets = shallowRef([])
const isDetailsVisible = shallowRef(false)
const isFeedbackVisible = shallowRef(false)
const feedbackMessage = shallowRef('')
const feedbackColor = shallowRef('success')

const visibilityOptions = [
  { title: 'All visibility', value: null },
  { title: 'Public', value: 'public' },
  { title: 'Private', value: 'private' },
]

const scanStatusOptions = [
  { title: 'All scan status', value: null },
  { title: 'Pending', value: 'pending' },
  { title: 'Clean', value: 'clean' },
  { title: 'Rejected', value: 'rejected' },
  { title: 'Error', value: 'error' },
]

const fieldConfig = computed(() => getMediaAssetFieldConfig(props.field))
const effectiveKind = computed(() => props.kind || fieldConfig.value?.kind || null)

const fieldKindMismatch = computed(() => Boolean(
  fieldConfig.value?.kind && props.kind && fieldConfig.value.kind !== props.kind,
))

const fieldTitle = computed(() => fieldConfig.value?.title || props.field || 'Media Library')
const canAttach = computed(() => props.canAttach ?? authCanAttach.value)
const canUpload = computed(() => props.canUpload ?? authCanUpload.value)
const canRetry = computed(() => authCanRetry.value)
const selectedAssetForDetails = computed(() => selectedAsset.value)
const hasItems = computed(() => items.value.length > 0)
const hasError = computed(() => Boolean(error.value))

const errorMessage = computed(() => {
  const payload = error.value?.data ?? error.value?.response?._data ?? {}
  const fieldErrors = payload.errors ?? {}
  const messages = Object.values(fieldErrors).flat().filter(Boolean)

  return messages.join(' ') || payload.message || 'Không thể tải Media Library.'
})

/** Input: message/màu. Output: mở snackbar feedback. */
const showFeedback = (message, color = 'success') => {
  feedbackMessage.value = message
  feedbackColor.value = color
  isFeedbackVisible.value = true
}

/** Input: query của dialog. Output: Promise load store; field visibility cố định được ưu tiên. */
const fetchAssets = () => {
  if (!props.open || fieldKindMismatch.value)
    return Promise.resolve(null)

  return mediaAssetStore.fetchMediaAssets({
    search: searchQuery.value || undefined,
    kind: effectiveKind.value || undefined,
    field: props.field || undefined,
    visibility: props.visibility || selectedVisibility.value || undefined,
    'scan_status': selectedScanStatus.value || undefined,
    page: page.value,
    'per_page': itemsPerPage.value,
    sort: 'created_at',
    direction: 'desc',
  })
}

/** Input: initialSelection. Output: draft mới không trùng ID, không mutate selection cha. */
const resetSelection = () => {
  selectedAssets.value = [...new Map(props.initialSelection.map(asset => [asset.id, asset])).values()]
}

/** Input: không có. Output: đóng nếu không upload, không emit select. */
const close = () => {
  if (isMutating.value)
    return

  emit('update:open', false)
}

/** Input: trạng thái VDialog. Output: forward open hoặc đóng an toàn. */
const handleDialogUpdate = value => {
  if (value)
    emit('update:open', true)
  else
    close()
}

/** Input: asset. Output: single commit ngay; multiple chỉ cập nhật draft. */
const toggleAsset = asset => {
  if (!canAttach.value)
    return

  if (!props.multiple) {
    selectedAssets.value = [asset]
    emit('select', asset)
    close()

    return
  }

  const index = selectedAssets.value.findIndex(selected => selected.id === asset.id)

  if (index >= 0) {
    selectedAssets.value = selectedAssets.value.filter(selected => selected.id !== asset.id)

    return
  }

  selectedAssets.value = [...selectedAssets.value, asset]
}

/** Input: draft. Output: emit selection (kể cả gallery rỗng), sau đó đóng. */
const confirmSelection = () => {
  if (!canAttach.value || (!props.multiple && !selectedAssets.value.length))
    return

  emit('select', props.multiple ? [...selectedAssets.value] : selectedAssets.value[0])
  close()
}

/** Input: file payload. Output: upload độc lập với Post rồi refresh list hoặc báo lỗi. */
const handleUpload = async payload => {
  try {
    await mediaAssetStore.uploadMediaAsset(payload)
    showFeedback('File đã được upload. Bạn có thể chọn file trong danh sách.')
    await fetchAssets()
  }
  catch {
    showFeedback(errorMessage.value, 'error')
  }
}

/** Input: asset. Output: retry pipeline khi có quyền, hiển thị feedback. */
const handleRetry = async asset => {
  if (!canRetry.value)
    return

  try {
    await mediaAssetStore.retryMediaAsset(asset.id)
    showFeedback('File đã được đưa vào hàng đợi xử lý lại.')
    await fetchAssets()
  }
  catch {
    showFeedback(errorMessage.value, 'error')
  }
}

/** Input: asset. Output: tải metadata và mở panel chi tiết. */
const showDetails = async asset => {
  await mediaAssetStore.fetchMediaAsset(asset.id)

  if (selectedAssetForDetails.value)
    isDetailsVisible.value = true
}

/** Input: không có. Output: focus ô tìm kiếm sau DOM update. */
const focusSearch = async () => {
  await nextTick()
  searchInput.value?.$el?.querySelector?.('input')?.focus()
}

watch(() => props.visibility, value => {
  selectedVisibility.value = value
})

watch(() => props.open, isOpen => {
  if (!isOpen) {
    resetSelection()

    return
  }

  page.value = 1
  resetSelection()
  void fetchAssets()
  void focusSearch()
}, { immediate: true })

watch(
  [searchQuery, selectedVisibility, selectedScanStatus, page, itemsPerPage, () => props.kind, () => props.field],
  (values, previousValues) => {
    if (!props.open)
      return

    const filterIndexes = [0, 1, 2, 4, 5, 6]

    const filterChanged = previousValues
      && filterIndexes.some(index => values[index] !== previousValues[index])

    if (filterChanged && page.value !== 1) {
      page.value = 1

      return
    }

    void fetchAssets()
  },
)
</script>

<template>
  <VDialog
    :model-value="props.open"
    max-width="1120"
    scrollable
    @update:model-value="handleDialogUpdate"
  >
    <DialogCloseBtn @click="close" />

    <VCard>
      <VCardItem>
        <VCardTitle>Choose media</VCardTitle>
        <VCardSubtitle>{{ fieldTitle }}</VCardSubtitle>
      </VCardItem>

      <VCardText>
        <VAlert
          v-if="fieldKindMismatch"
          color="error"
          variant="tonal"
          class="mb-5"
        >
          Field {{ props.field }} chỉ nhận kind {{ fieldConfig.kind }}.
        </VAlert>

        <VAlert
          v-else-if="!canAttach"
          color="warning"
          variant="tonal"
          class="mb-5"
        >
          Bạn có thể xem file nhưng không có quyền attach media vào field này.
        </VAlert>

        <VRow class="mb-1">
          <VCol
            cols="12"
            md="5"
          >
            <AppTextField
              ref="searchInput"
              v-model="searchQuery"
              placeholder="Search files"
              prepend-inner-icon="tabler-search"
              clearable
              label="Search"
            />
          </VCol>
          <VCol
            cols="12"
            sm="6"
            md="3"
          >
            <AppSelect
              v-model="selectedVisibility"
              :disabled="Boolean(props.visibility)"
              label="Visibility"
              :items="visibilityOptions"
              clearable
            />
          </VCol>
          <VCol
            cols="12"
            sm="6"
            md="3"
          >
            <AppSelect
              v-model="selectedScanStatus"
              label="Scan status"
              :items="scanStatusOptions"
              clearable
            />
          </VCol>
          <VCol
            cols="12"
            md="1"
            class="d-flex align-end"
          >
            <AppSelect
              v-model="itemsPerPage"
              label="Rows"
              :items="[12, 24, 48]"
            />
          </VCol>
        </VRow>

        <VExpansionPanels class="mb-5">
          <VExpansionPanel title="Upload new file">
            <VExpansionPanelText>
              <MediaUploadDropZone
                :kind="effectiveKind || 'image'"
                :visibility="selectedVisibility || 'public'"
                :loading="isMutating"
                :progress="uploadProgress"
                :error="hasError ? errorMessage : ''"
                :can-upload="canUpload"
                @upload="handleUpload"
              />
            </VExpansionPanelText>
          </VExpansionPanel>
        </VExpansionPanels>

        <VProgressLinear
          v-if="isLoading"
          indeterminate
          color="primary"
          class="mb-4"
        />

        <VAlert
          v-if="hasError"
          color="error"
          variant="tonal"
          class="mb-4"
        >
          {{ errorMessage }}
          <VBtn
            variant="text"
            color="error"
            class="ms-2"
            @click="fetchAssets"
          >
            Retry
          </VBtn>
        </VAlert>

        <VAlert
          v-if="!isLoading && !hasError && !hasItems && !fieldKindMismatch"
          color="info"
          variant="tonal"
          class="mb-4"
        >
          Không có file phù hợp với bộ lọc hiện tại.
        </VAlert>

        <MediaAssetGrid
          v-if="!fieldKindMismatch"
          :assets="items"
          :selected-assets="selectedAssets"
          :multiple="props.multiple"
          :loading="isLoading"
          :can-select="canAttach"
          :can-retry="canRetry"
          @toggle="toggleAsset"
          @details="showDetails"
          @retry="handleRetry"
        />

        <div class="d-flex flex-wrap align-center justify-space-between gap-3 mt-5">
          <div class="text-body-2 text-medium-emphasis">
            {{ selectedAssets.length }} file đã chọn
          </div>
          <TablePagination
            :page="page"
            :items-per-page="itemsPerPage"
            :total-items="itemsLength"
            @update:page="page = $event"
          />
        </div>
      </VCardText>

      <VCardActions class="justify-end">
        <VBtn
          variant="tonal"
          :disabled="isMutating"
          @click="close"
        >
          Cancel
        </VBtn>
        <VBtn
          v-if="props.multiple"
          :disabled="!canAttach || isMutating"
          @click="confirmSelection"
        >
          Select {{ selectedAssets.length || '' }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <MediaAssetDetails
    v-model="isDetailsVisible"
    :asset="selectedAssetForDetails"
  />

  <VSnackbar
    v-model="isFeedbackVisible"
    :color="feedbackColor"
    location="top end"
  >
    {{ feedbackMessage }}
  </VSnackbar>
</template>

