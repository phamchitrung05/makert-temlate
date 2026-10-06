<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị lưới media co giãn với các ô vuông cho Media Picker.
  =====================================================================

  Component chỉ nhận asset và selection từ dialog, sau đó phát event khi user
  chọn hoặc retry. Không gọi HTTP và không tự quyết định policy.

  CÁC HÀM/METHOD TRONG FILE:
  - selectedIds: computed tập ID để tra cứu các file đã chọn.
  - fileOf()/previewOf()/fileInfo(): lấy metadata hiển thị an toàn
  - resolveStatus(): ánh xạ status sang nhãn/màu
  - isSelected()/canSelect()/selectionHint(): xác định trạng thái chọn
  - shouldShowStatus()/shouldShowScanStatus()/shouldShowConversionStatus(): ẩn trạng thái mặc định.
  - handleKeydown(): hỗ trợ chọn bằng Enter/Space.

  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : assets, selected assets, multiple và capability.
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

/** Input: selected asset list. Output: Set ID để lookup O(1). */
const selectedIds = computed(() => new Set(props.selectedAssets.map(asset => asset.id)))

/** Input: asset. Output: file metadata hoặc object rỗng. */
const fileOf = asset => asset?.file ?? {}

/** Input: asset. Output: preview URL public hoặc null. */
const previewOf = asset => fileOf(asset).preview_url || fileOf(asset).url || null

/** Input: asset. Output: định dạng và kích thước file thật cho card. */
const fileInfo = asset => {
  const file = fileOf(asset)
  const format = file.mime_type?.split('/').pop()?.toUpperCase() || asset.kind
  const size = Number(file.size)
  if (!Number.isFinite(size) || size <= 0) return format
  const units = ['B', 'KB', 'MB', 'GB']
  const index = Math.min(Math.floor(Math.log(size) / Math.log(1024)), units.length - 1)

  return `${format} · ${(size / 1024 ** index).toFixed(index === 0 ? 0 : 1)} ${units[index]}`
}

/** Input: asset. Output: true khi asset đang được chọn. */
const isSelected = asset => selectedIds.value.has(asset.id)

/** Input: status backend. Output: nhãn và màu Vuetify. */
const resolveStatus = status => ({
  pending: { text: 'Pending', color: 'warning' },
  processing: { text: 'Processing', color: 'warning' },
  clean: { text: 'Clean', color: 'success' },
  ready: { text: 'Ready', color: 'success' },
  rejected: { text: 'Rejected', color: 'error' },
  error: { text: 'Error', color: 'error' },
  failed: { text: 'Failed', color: 'error' },
}[status] ?? { text: status || 'Unknown', color: 'default' })

/** Input: asset. Output: true khi policy UI cho phép chọn. */
const canSelect = asset => {
  return props.canSelect && !(asset.kind === 'archive' && fileOf(asset).scan_status !== 'clean')
}

/** Input: asset. Output: hướng dẫn accessibility cho card. */
const selectionHint = asset => {
  if (!props.canSelect)
    return 'Bạn không có quyền attach media.'

  if (asset.kind === 'archive' && fileOf(asset).scan_status !== 'clean')
    return 'Package chỉ chọn được sau khi security scan clean.'

  return `Chọn ${asset.title || 'file'}`
}

/** Input: status API. Output: ẩn trạng thái mặc định clean/pending khỏi card. */
const shouldShowStatus = status => Boolean(status) && !['clean', 'pending', 'ready'].includes(status)

/** Input: asset. Output: true khi cần hiển thị scan status. */
const shouldShowScanStatus = asset => shouldShowStatus(fileOf(asset).scan_status)

/** Input: asset. Output: true khi cần hiển thị conversion status. */
const shouldShowConversionStatus = asset => shouldShowStatus(fileOf(asset).conversion_status)

/** Input: keyboard event/card asset. Output: emit toggle khi Enter/Space hợp lệ. */
const handleKeydown = (event, asset) => {
  if (!canSelect(asset) || !['Enter', ' '].includes(event.key))
    return

  event.preventDefault()
  emit('toggle', asset)
}
</script>

<template>
  <div class="media-asset-grid">
    <div
      v-if="props.assets.length"
      class="media-asset-grid__list"
      role="listbox"
      :aria-multiselectable="props.multiple"
    >
      <div
        v-for="asset in props.assets"
        :key="asset.id"
        class="media-asset-grid__item"
      >
        <VCard
          class="media-asset-card"
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
              cover
              class="media-asset-card__image"
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

          <VCardText class="media-asset-card__caption">
            <div
              class="font-weight-medium media-asset-card__title"
              :title="asset.title"
            >
              {{ asset.title || fileOf(asset).original_name || 'File media' }}
            </div>
            <div class="media-asset-card__info media-asset-card__title">
              {{ fileInfo(asset) }}
            </div>
          </VCardText>

          <VCardText
            v-if="shouldShowScanStatus(asset) || shouldShowConversionStatus(asset)"
            class="media-asset-card__status"
          >
            <div class="d-flex flex-column align-start gap-1">
              <VChip
                v-if="shouldShowScanStatus(asset)"
                :color="resolveStatus(fileOf(asset).scan_status).color"
                :title="`Scan ${resolveStatus(fileOf(asset).scan_status).text}`"
                size="x-small"
                label
              >
                Scan {{ resolveStatus(fileOf(asset).scan_status).text }}
              </VChip>
              <VChip
                v-if="shouldShowConversionStatus(asset)"
                :color="resolveStatus(fileOf(asset).conversion_status).color"
                :title="resolveStatus(fileOf(asset).conversion_status).text"
                size="x-small"
                label
              >
                {{ resolveStatus(fileOf(asset).conversion_status).text }}
              </VChip>
            </div>
          </VCardText>

          <VCardActions
            v-if="props.canRetry && (fileOf(asset).scan_status === 'error' || fileOf(asset).conversion_status === 'failed')"
            class="media-asset-card__actions"
          >
            <VBtn
              icon="tabler-refresh"
              variant="tonal"
              size="x-small"
              :aria-label="`Retry ${asset.title}`"
              @click.stop="emit('retry', asset)"
              @keydown.stop
            />
          </VCardActions>
        </VCard>
      </div>
    </div>
  </div>
</template>

<style scoped>
.media-asset-grid__list {
  display: grid;
  gap: 12px;

  /* Khoảng 10 cột khi đủ rộng; giảm cột để mỗi ô không nhỏ hơn 96px. */
  grid-template-columns: repeat(auto-fill, minmax(min(100%, max(96px, calc((100% - 108px) / 10))), 1fr));
}

.media-asset-grid__item {
  min-inline-size: 0;
}

.media-asset-card {
  position: relative;
  overflow: hidden;
  border: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
  aspect-ratio: 1 / 1;
  background: rgb(var(--v-theme-surface));
  box-shadow: none;
  cursor: pointer;
  inline-size: 100%;
  transition: border-color 0.2s ease, background-color 0.2s ease;
}

.media-asset-card:hover {
  border-color: rgba(var(--v-theme-primary), 0.5);
}

.media-asset-card:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

.media-asset-card--selected {
  border-color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.04);
}

.media-asset-card[aria-disabled="true"] {
  cursor: not-allowed;
  opacity: 0.62;
}

.media-asset-card__preview {
  position: absolute;
  display: flex;
  overflow: hidden;
  align-items: center;
  justify-content: center;
  background: rgb(var(--v-theme-background));
  inset: 0;
}

.media-asset-card__preview :deep(.media-asset-card__image) {
  block-size: 100%;
  inline-size: 100%;
}

.media-asset-card__check {
  position: absolute;
  z-index: 2;
  border-radius: 50%;
  background: rgb(var(--v-theme-surface));
  inset-block-start: 6px;
  inset-inline-end: 6px;
}

.media-asset-card__caption {
  position: absolute;
  background: linear-gradient(transparent, rgba(var(--v-theme-surface), 0.96) 32%);
  color: rgb(var(--v-theme-on-surface));
  font-size: 12px;
  inset-block-end: 0;
  inset-inline: 0;
  line-height: 1.4;
  padding-block: 16px 6px !important;
  padding-inline: 8px !important;
}

.media-asset-card__info {
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-size: 10px;
}

.media-asset-card__status {
  position: absolute;
  padding: 0 !important;
  inset-block-start: 6px;
  inset-inline-start: 6px;
  max-inline-size: calc(100% - 40px);
}

.media-asset-card__status :deep(.v-chip) {
  background: rgb(var(--v-theme-surface));
  max-inline-size: 100%;
}

.media-asset-card__status :deep(.v-chip__content) {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.media-asset-card__actions {
  position: absolute;
  padding: 0;
  inset-block-start: 32px;
  inset-inline-end: 4px;
  min-block-size: 0;
}

.media-asset-card__actions :deep(.v-btn) {
  background: rgb(var(--v-theme-surface));
}

.media-asset-card__title {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>

