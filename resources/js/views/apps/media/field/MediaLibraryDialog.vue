<!--
  =====================================================================
  CHỨC NĂNG FILE: Điều phối dialog chọn asset trong Media Library
  =====================================================================

  Dialog giữ query, filter, pagination và selection cục bộ; request đi qua
  mediaAsset store. Component có thể dùng trong Resource, Post và Resource
  Version mà không biết cách attach nghiệp vụ cụ thể của form cha.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - fetchAssets(): tải danh sách theo kind/field/filter hiện tại
  - toggleAsset()/confirmSelection(): xử lý single/multiple selection
  - handleUpload()/handleRetry(): điều phối mutation qua store
  - errorMessage()/canAttach/canUpload(): trạng thái UI và capability
  - resetSelection(): sao chép lựa chọn hiện có vào draft
  - close()/handleDialogUpdate(): đóng dialog không commit draft
  - showFeedback(): feedback thao tác upload/retry

  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : open, kind, field, multiple, visibility, initialSelection và capability.
  - OUTPUT: update:open, select asset hoặc asset array.
  =====================================================================
-->
<script setup>
import { computed, shallowRef, watch } from 'vue'
import { storeToRefs } from 'pinia'
import MediaLibraryDialogLayout from '@/views/apps/media/field/MediaLibraryDialogLayout.vue'
import { useMediaAssetStore } from '@/stores/mediaAsset'
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
  isLoading,
  isMutating,
  error,
} = storeToRefs(mediaAssetStore)

const {
  canAttach: authCanAttach,
  canUpload: authCanUpload,
  canRetry: authCanRetry,
} = useMediaCapabilities()

const searchQuery = shallowRef('')
const selectedVisibility = shallowRef(props.visibility)
const selectedScanStatus = shallowRef(null)
const selectedKind = shallowRef(props.kind || getMediaAssetFieldConfig(props.field)?.kind || null)
const page = shallowRef(1)
const itemsPerPage = shallowRef(12)
const selectedAssets = shallowRef([])
const previewAsset = shallowRef(null)
const isFeedbackVisible = shallowRef(false)
const feedbackMessage = shallowRef('')
const feedbackColor = shallowRef('success')

const fieldConfig = computed(() => getMediaAssetFieldConfig(props.field))
const effectiveKind = computed(() => props.kind || fieldConfig.value?.kind || selectedKind.value || null)

const fieldKindMismatch = computed(() => Boolean(
  fieldConfig.value?.kind && props.kind && fieldConfig.value.kind !== props.kind,
))

const fieldTitle = computed(() => fieldConfig.value?.title || props.field || 'Media Library')
const canAttach = computed(() => props.canAttach ?? authCanAttach.value)
const canUpload = computed(() => props.canUpload ?? authCanUpload.value)
const canRetry = computed(() => authCanRetry.value)
const hasError = computed(() => Boolean(error.value))

const errorMessage = computed(() => {
  const payload = error.value?.data ?? error.value?.response?._data ?? {}
  const fieldErrors = payload.errors ?? {}
  const messages = Object.values(fieldErrors).flat().filter(Boolean)

  return messages.join(' ') || payload.message || 'Không thể tải Media Library.'
})

const kindLocked = computed(() => Boolean(props.kind || fieldConfig.value?.kind))

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

watch(() => props.visibility, value => {
  selectedVisibility.value = value
})

watch([() => props.kind, () => props.field], () => {
  selectedKind.value = props.kind || getMediaAssetFieldConfig(props.field)?.kind || null
})

watch(() => props.open, isOpen => {
  if (!isOpen) {
    resetSelection()
    previewAsset.value = null

    return
  }

  page.value = 1
  previewAsset.value = null
  resetSelection()
  void fetchAssets()
}, { immediate: true })

watch(
  [searchQuery, selectedVisibility, selectedScanStatus, selectedKind, page, itemsPerPage, () => props.kind, () => props.field],
  (values, previousValues) => {
    if (!props.open)
      return

    const filterIndexes = [0, 1, 2, 3, 5, 6, 7]

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
    scrollable
    max-width="1600"
    height="calc(100% - 24px)"
    max-height="calc(100% - 24px)"
    content-class="media-library-dialog-overlay"
    @update:model-value="handleDialogUpdate"
  >
    <MediaLibraryDialogLayout
      id="view-moi"
      v-model:search-query="searchQuery"
      v-model:selected-visibility="selectedVisibility"
      v-model:selected-scan-status="selectedScanStatus"
      v-model:selected-kind="selectedKind"
      v-model:page="page"
      v-model:items-per-page="itemsPerPage"
      v-model:preview-asset="previewAsset"
      :field-title="fieldTitle"
      :field-name="props.field"
      :field-kind="fieldConfig?.kind"
      :field-kind-mismatch="fieldKindMismatch"
      :kind-locked="kindLocked"
      :effective-kind="effectiveKind"
      :can-attach="canAttach"
      :can-upload="canUpload"
      :can-retry="canRetry"
      :is-loading="isLoading"
      :is-mutating="isMutating"
      :has-error="hasError"
      :error-message="errorMessage"
      :assets="items"
      :selected-assets="selectedAssets"
      :multiple="props.multiple"
      :items-length="itemsLength"
      @close="close"
      @confirm="confirmSelection"
      @refresh="fetchAssets"
      @retry="handleRetry"
      @toggle="toggleAsset"
      @upload="handleUpload"
    />
  </VDialog>

  <VSnackbar
    v-model="isFeedbackVisible"
    :color="feedbackColor"
    location="top end"
  >
    {{ feedbackMessage }}
  </VSnackbar>
</template>

<style lang="scss">
.media-library-dialog-overlay {
  margin: 12px !important;
}
</style>
