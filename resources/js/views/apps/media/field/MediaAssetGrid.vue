<!--
  =====================================================================
  CHỨC NĂNG FILE: Render grid asset cho Media Picker
  =====================================================================

  Component chỉ nhận asset và selection từ dialog, sau đó phát event khi user
  chọn hoặc retry. Không gọi HTTP và không tự quyết định policy.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - fileOf()/previewOf(): lấy metadata hiển thị an toàn
  - resolveStatus(): ánh xạ status sang nhãn/màu
  - isSelected()/canSelect(): xác định trạng thái chọn

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : assets, selected assets, loading, multiple và capability.
  - OUTPUT: emit toggle/retry cho MediaLibraryDialog.
  =====================================================================
-->
<script setup>
import { computed } from 'vue'

const props = defineProps({
  assets: {
    type: Array,
    default: () => [],
  },
  selectedAssets: {
    type: Array,
    default: () => [],
  },
  multiple: {
    type: Boolean,
    default: false,
  },
  loading: {
    type: Boolean,
    default: false,
  },
  canSelect: {
    type: Boolean,
    default: true,
  },
  canRetry: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['toggle', 'retry'])

const selectedIds = computed(() => new Set(props.selectedAssets.map(asset => asset.id)))

const fileOf = asset => asset?.file ?? {}

const previewOf = asset => fileOf(asset).preview_url || fileOf(asset).url || null

const isSelected = asset => selectedIds.value.has(asset.id)

const resolveStatus = status => ({
  pending: { text: 'Pending', color: 'warning' },
  processing: { text: 'Processing', color: 'warning' },
  clean: { text: 'Clean', color: 'success' },
  ready: { text: 'Ready', color: 'success' },
  rejected: { text: 'Rejected', color: 'error' },
  error: { text: 'Error', color: 'error' },
  failed: { text: 'Failed', color: 'error' },
}[status] ?? { text: status || 'Unknown', color: 'default' })

const canSelect = asset => {
  return props.canSelect && !(asset.kind === 'archive' && fileOf(asset).scan_status !== 'clean')
}

const selectionHint = asset => {
  if (!props.canSelect)
    return 'Bạn không có quyền attach media.'

  if (asset.kind === 'archive' && fileOf(asset).scan_status !== 'clean')
    return 'Package chỉ chọn được sau khi security scan clean.'

  return props.multiple ? 'Chọn asset này' : 'Chọn asset'
}

const shouldShowStatus = status => Boolean(status) && !['pending', 'ready', 'clean'].includes(status)

const shouldShowScanStatus = asset => shouldShowStatus(fileOf(asset).scan_status)

const shouldShowConversionStatus = asset => shouldShowStatus(fileOf(asset).conversion_status)

const handleKeydown = (event, asset) => {
  if (!canSelect(asset) || !['Enter', ' '].includes(event.key))
    return

  event.preventDefault()
  emit('toggle', asset)
}
</script>

<template>
  <div class="media-asset-grid">
    <VProgressLinear
      v-if="props.loading"
      indeterminate
      color="primary"
      class="mb-4"
      aria-label="Loading media assets"
    />

    <VRow
      v-if="props.assets.length"
      role="listbox"
      :aria-multiselectable="props.multiple"
    >
      <VCol
        v-for="asset in props.assets"
        :key="asset.id"
        cols="12"
        sm="6"
        md="4"
        lg="3"
      >
        <VCard
          class="media-asset-card h-100"
          :class="{ 'media-asset-card--selected': isSelected(asset) }"
          :tabindex="canSelect(asset) ? 0 : -1"
          :aria-disabled="!canSelect(asset)"
          :aria-label="selectionHint(asset)"
          :aria-selected="isSelected(asset)"
          role="option"
          @click="canSelect(asset) && emit('toggle', asset)"
          @keydown="handleKeydown($event, asset)"
        >
          <div class="media-asset-card__preview">
            <VImg
              v-if="previewOf(asset) && asset.kind === 'image'"
              :src="previewOf(asset)"
              height="148"
              cover
              alt=""
            />
            <VIcon
              v-else
              :icon="asset.kind === 'video' ? 'tabler-video' : asset.kind === 'archive' ? 'tabler-file-zip' : 'tabler-file-text'"
              size="52"
              color="primary"
            />
            <VIcon
              v-if="isSelected(asset)"
              class="media-asset-card__check"
              icon="tabler-circle-check-filled"
              color="primary"
              size="24"
            />
          </div>

          <VCardText
            v-if="shouldShowScanStatus(asset) || shouldShowConversionStatus(asset)"
            class="pb-2"
          >
            <div class="d-flex flex-wrap gap-1 mt-2">
              <VChip
                v-if="shouldShowScanStatus(asset)"
                :color="resolveStatus(fileOf(asset).scan_status).color"
                size="x-small"
                label
              >
                Scan {{ resolveStatus(fileOf(asset).scan_status).text }}
              </VChip>
              <VChip
                v-if="shouldShowConversionStatus(asset)"
                :color="resolveStatus(fileOf(asset).conversion_status).color"
                size="x-small"
                label
              >
                {{ resolveStatus(fileOf(asset).conversion_status).text }}
              </VChip>
            </div>
          </VCardText>

          <VCardActions
            v-if="props.canRetry && (fileOf(asset).scan_status === 'error' || fileOf(asset).conversion_status === 'failed')"
            class="pt-0 justify-end"
          >
            <VBtn
              icon="tabler-refresh"
              variant="text"
              size="small"
              :aria-label="`Retry ${asset.title}`"
              @click.stop="emit('retry', asset)"
            />
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style scoped>
.media-asset-card {
  cursor: pointer;
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  box-shadow: 0 2px 6px rgba(var(--v-theme-on-surface), 0.08);
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.media-asset-card:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

.media-asset-card--selected {
  border: 2px solid rgb(var(--v-theme-primary));
  box-shadow: 0 0 0 2px rgba(var(--v-theme-primary), 0.14), 0 2px 6px rgba(var(--v-theme-on-surface), 0.08);
}

.media-asset-card[aria-disabled='true'] {
  cursor: not-allowed;
  opacity: 0.62;
}

.media-asset-card__preview {
  position: relative;
  display: flex;
  min-block-size: 148px;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  background: rgb(var(--v-theme-grey-200));
}

.media-asset-card__check {
  position: absolute;
  inset-block-start: 10px;
  inset-inline-end: 10px;
}
</style>

