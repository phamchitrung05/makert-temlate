<!--
  =====================================================================
  CHỨC NĂNG FILE: Trình bày thư viện media theo khung dialog của project.
  =====================================================================
  Header/footer cố định; bộ lọc, danh sách và preview nằm trong body.
  Grid phụ trách chọn file; MediaLibraryPreview trình bày metadata đã lưu.
  CÁC HÀM/METHOD TRONG FILE:
  - fileOf()/previewOf()/isSelected(): đọc metadata và lựa chọn từ props.
  - canSelectAsset()/handleToggle(): chuyển lựa chọn hợp lệ về caller.
  - openFilePicker()/handleUploadInput(): chọn file và phát payload upload.
  - selectionDescription: computed hướng dẫn theo chế độ chọn.
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : assets, selection, capability và model lọc/phân trang.
  - OUTPUT: close/confirm/refresh/retry/toggle/upload/update:previewAsset.
  - SIDE EFFECT: mở file picker; không gọi API hoặc sửa asset đầu vào.
  =====================================================================
-->
<script setup>
import { computed, shallowRef, useTemplateRef } from 'vue'
import { PerfectScrollbar } from 'vue3-perfect-scrollbar'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import MediaAssetGrid from './MediaAssetGrid.vue'
import MediaLibraryPreview from './MediaLibraryPreview.vue'
import { kindAccept } from './mediaAssetFields'

const props = defineProps({
  fieldTitle: { type: String, default: 'Media Library' },
  fieldName: { type: String, default: '' },
  fieldKind: { type: String, default: null },
  fieldKindMismatch: { type: Boolean, default: false },
  kindLocked: { type: Boolean, default: false },
  effectiveKind: { type: String, default: null },
  canAttach: { type: Boolean, default: true },
  canUpload: { type: Boolean, default: false },
  canRetry: { type: Boolean, default: false },
  isLoading: { type: Boolean, default: false },
  isMutating: { type: Boolean, default: false },
  hasError: { type: Boolean, default: false },
  errorMessage: { type: String, default: '' },
  assets: { type: Array, default: () => [] },
  selectedAssets: { type: Array, default: () => [] },
  previewAsset: { type: Object, default: null },
  multiple: { type: Boolean, default: false },
  itemsLength: { type: Number, default: 0 },
})

const emit = defineEmits(['close', 'confirm', 'refresh', 'retry', 'toggle', 'upload', 'update:previewAsset'])
const searchQuery = defineModel('searchQuery', { type: String, default: '' })
const selectedVisibility = defineModel('selectedVisibility', { type: String, default: null })
const selectedScanStatus = defineModel('selectedScanStatus', { type: String, default: null })
const selectedKind = defineModel('selectedKind', { type: String, default: null })
const page = defineModel('page', { type: Number, default: 1 })
const itemsPerPage = defineModel('itemsPerPage', { type: Number, default: 12 })
const viewMode = shallowRef('grid')
const uploadInput = useTemplateRef('uploadInput')

const kindOptions = [
  { title: 'Tất cả', value: null },
  { title: 'Ảnh', value: 'image' },
  { title: 'Video', value: 'video' },
  { title: 'Tài liệu', value: 'document' },
  { title: 'File nén', value: 'archive' },
]

const scanStatusOptions = [
  { title: 'Tất cả', value: null },
  { title: 'Bị từ chối', value: 'rejected' },
  { title: 'Lỗi', value: 'error' },
]

const selectionDescription = computed(() => props.multiple
  ? 'Chọn các file cần sử dụng, sau đó xác nhận lựa chọn.'
  : 'Chọn một file để thêm vào nội dung của bạn.')

/** Input: asset. Output: metadata file; không truy vấn hoặc mutate asset. */
const fileOf = asset => asset?.file ?? {}

/** Input: asset. Output: URL xem trước đã được API cấp hoặc null. */
const previewOf = asset => fileOf(asset).preview_url || fileOf(asset).url || null

/** Input: asset. Output: true nếu ID thuộc selection từ caller. */
const isSelected = asset => props.selectedAssets.some(selected => selected.id === asset.id)


/** Input: asset. Output: quyền chọn theo capability và scan của file nén. */
const canSelectAsset = asset => props.canAttach && !props.isMutating
  && !(asset.kind === 'archive' && fileOf(asset).scan_status !== 'clean')


/** Input: asset được nhấn. Output: mở preview, phát toggle khi được phép chọn. */
const handleToggle = asset => {
  emit('update:previewAsset', asset)
  if (canSelectAsset(asset)) emit('toggle', asset)
}


/** Input: không có. Output: mở input khi có quyền upload, chưa có mutation. */
const openFilePicker = () => {
  if (props.canUpload && !props.isMutating) uploadInput.value?.click()
}


/** Input: change event. Output: upload payload; reset input để chọn lại file. */
const handleUploadInput = event => {
  const file = event.target.files?.[0]

  event.target.value = ''
  if (!file || !props.canUpload || props.isMutating) return
  const kind = props.effectiveKind || 'image'

  emit('upload', {
    file, kind,
    title: file.name?.replace(/\.[^/.]+$/, '') || file.name || 'File mới',
    visibility: kind === 'archive' ? 'private' : selectedVisibility.value || 'public',
  })
}
</script>

<template>
  <AppDialogLayout
    class="media-library-dialog-card"
    title="Thư viện media"
    :subtitle="selectionDescription"
    :body-scroll="false"
    :close-disabled="props.isMutating"
    close-label="Đóng thư viện media"
    @close="emit('close')"
  >
    <VCardText class="pa-0 media-library-dialog-body">
      <div class="media-library-toolbar">
        <AppTextField
          v-model="searchQuery"
          class="media-library-search"
          label="Tìm kiếm"
          placeholder="Tìm theo tên file..."
          aria-label="Tìm media"
          prepend-inner-icon="tabler-search"
          clearable
          hide-details
        />
        <AppSelect
          v-model="selectedKind"
          :items="kindOptions"
          :disabled="props.kindLocked"
          label="Loại file"
          aria-label="Loại file"
          hide-details
        />
        <AppSelect
          v-model="selectedScanStatus"
          :items="scanStatusOptions"
          label="Kiểm tra file"
          aria-label="Trạng thái kiểm tra file"
          hide-details
        />
        <VBtnToggle
          v-model="viewMode"
          mandatory
          divided
          variant="outlined"
          color="primary"
          density="compact"
          class="media-library-view-toggle"
        >
          <VBtn
            icon="tabler-layout-grid"
            value="grid"
            aria-label="Xem dạng lưới"
          />
          <VBtn
            icon="tabler-list"
            value="list"
            aria-label="Xem dạng danh sách"
          />
        </VBtnToggle>
        <VBtn
          prepend-icon="tabler-upload"
          :disabled="!props.canUpload || props.isMutating"
          :loading="props.isMutating"
          class="media-library-upload"
          @click="openFilePicker"
        >
          Tải file lên
        </VBtn>
        <input
          ref="uploadInput"
          class="d-none"
          type="file"
          :accept="kindAccept(props.effectiveKind || 'image')"
          :disabled="!props.canUpload || props.isMutating"
          @change="handleUploadInput"
        >
      </div>
      <div class="media-library-panels">
        <section
          class="media-library-main"
          aria-label="Danh sách media"
        >
          <div class="media-library-summary">
            <span class="text-subtitle-2">{{ props.fieldTitle }}</span>
            <VChip
              size="small"
              color="secondary"
              variant="tonal"
            >
              {{ props.itemsLength }} file
            </VChip>
          </div>
          <div
            v-if="props.fieldKindMismatch || !props.canAttach || props.hasError"
            class="px-6 pb-4"
          >
            <VAlert
              v-if="props.fieldKindMismatch"
              type="error"
              variant="tonal"
            >
              {{ props.fieldTitle }} ({{ props.fieldName }}) yêu cầu loại file {{ props.fieldKind }}.
            </VAlert>
            <VAlert
              v-else-if="!props.canAttach"
              type="warning"
              variant="tonal"
            >
              Bạn có thể xem file nhưng chưa có quyền chọn media cho nội dung này.
            </VAlert>
            <VAlert
              v-if="props.hasError"
              type="error"
              variant="tonal"
              :class="{ 'mt-3': !props.canAttach }"
            >
              {{ props.errorMessage }}
              <VBtn
                variant="text"
                color="error"
                class="ms-2"
                @click="emit('refresh')"
              >
                Thử lại
              </VBtn>
            </VAlert>
          </div>
          <VProgressLinear
            v-if="props.isLoading"
            indeterminate
            color="primary"
            aria-label="Đang tải media"
          />
          <PerfectScrollbar
            class="media-library-items"
            :options="{ wheelPropagation: false, suppressScrollX: true }"
          >
            <div
              v-if="!props.isLoading && !props.hasError && !props.assets.length && !props.fieldKindMismatch"
              class="media-library-empty"
            >
              <VAvatar
                size="64"
                color="secondary"
                variant="tonal"
                rounded="lg"
                class="mb-4"
              >
                <VIcon
                  icon="tabler-photo-search"
                  size="30"
                />
              </VAvatar>
              <div class="text-subtitle-1 mb-1">
                Chưa có file phù hợp
              </div>
              <p class="text-body-2 text-medium-emphasis mb-0">
                Thử thay đổi bộ lọc hoặc tải file mới lên thư viện.
              </p>
            </div>
            <MediaAssetGrid
              v-else-if="viewMode === 'grid' && !props.fieldKindMismatch"
              :assets="props.assets"
              :selected-assets="props.selectedAssets"
              :multiple="props.multiple"
              :can-select="props.canAttach && !props.isMutating"
              :can-retry="props.canRetry"
              @toggle="handleToggle"
              @retry="emit('retry', $event)"
            />
            <VList
              v-else-if="viewMode === 'list' && !props.fieldKindMismatch"
              class="pa-0 media-library-list"
              lines="two"
            >
              <VListItem
                v-for="asset in props.assets"
                :key="asset.id"
                :active="isSelected(asset)"
                :title="asset.title"
                :subtitle="fileOf(asset).original_name || fileOf(asset).mime_type || asset.kind"
                rounded
                class="mb-2"
                @click="handleToggle(asset)"
              >
                <template #prepend>
                  <VAvatar
                    rounded
                    size="48"
                    variant="tonal"
                    color="secondary"
                    class="me-3"
                  >
                    <VImg
                      v-if="previewOf(asset) && asset.kind === 'image'"
                      :src="previewOf(asset)"
                      cover
                      alt=""
                    />
                    <VIcon
                      v-else
                      :icon="asset.kind === 'video' ? 'tabler-video' : asset.kind === 'archive' ? 'tabler-file-zip' : 'tabler-file-text'"
                    />
                  </VAvatar>
                </template>
                <template #append>
                  <VIcon
                    v-if="isSelected(asset)"
                    icon="tabler-circle-check-filled"
                    color="primary"
                  />
                  <VIcon
                    v-else-if="!canSelectAsset(asset)"
                    icon="tabler-lock"
                    color="secondary"
                    size="18"
                  />
                </template>
              </VListItem>
            </VList>
          </PerfectScrollbar>
          <TablePagination
            v-if="!props.fieldKindMismatch"
            class="media-library-pagination"
            :page="page"
            :items-per-page="itemsPerPage"
            :total-items="props.itemsLength"
            @update:page="page = $event"
          />
        </section>
        <aside
          class="media-library-preview"
          :class="{ 'media-library-preview--empty': !props.previewAsset }"
          aria-label="Thông tin media"
        >
          <PerfectScrollbar
            class="media-library-preview-scroll"
            :options="{ wheelPropagation: false, suppressScrollX: true }"
          >
            <MediaLibraryPreview
              :asset="props.previewAsset"
              :selected="props.previewAsset ? isSelected(props.previewAsset) : false"
            />
          </PerfectScrollbar>
        </aside>
      </div>
    </VCardText>
    <template #footer>
      <VCardActions class="justify-space-between">
        <div class="d-flex align-center gap-2 text-body-2 text-medium-emphasis">
          <VIcon
            icon="tabler-circle-check"
            size="18"
            :color="props.selectedAssets.length ? 'primary' : 'secondary'"
          />
          {{ props.selectedAssets.length }} file đã chọn
        </div>
        <div class="d-flex gap-3">
          <VBtn
            variant="tonal"
            color="secondary"
            :disabled="props.isMutating"
            @click="emit('close')"
          >
            Hủy
          </VBtn>
          <VBtn
            v-if="props.multiple"
            variant="flat"
            color="primary"
            :disabled="!props.canAttach || props.isMutating"
            prepend-icon="tabler-check"
            @click="emit('confirm')"
          >
            Chọn media
          </VBtn>
        </div>
      </VCardActions>
    </template>
  </AppDialogLayout>
</template>

<style scoped>
.media-library-dialog-card {
  block-size: 100%;
  min-block-size: 0;
}

.media-library-dialog-body {
  display: flex;
  overflow: hidden !important;
  flex-direction: column;
  block-size: 100%;
  min-block-size: 0;
}

.media-library-toolbar {
  display: grid;
  flex: 0 0 auto;
  align-items: end;
  border-block-end: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
  gap: 12px;
  grid-template-columns: minmax(180px, 1fr) 170px 180px auto auto;
  padding-block: 20px;
  padding-inline: 24px;
}

.media-library-view-toggle {
  border-color: rgba(var(--v-border-color), var(--v-border-opacity));
  block-size: 38px;
}

.media-library-view-toggle :deep(.v-btn) {
  padding: 0;
  inline-size: 38px;
  min-inline-size: 38px;
}

.media-library-panels {
  display: grid;
  flex: 1 1 0%;
  grid-template-columns: minmax(0, 1fr) 300px;
  min-block-size: 0;
}

.media-library-main {
  display: flex;
  flex-direction: column;
  min-block-size: 0;
  min-inline-size: 0;
}

.media-library-summary {
  display: flex;
  flex: 0 0 auto;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding-block: 20px 16px;
  padding-inline: 24px;
}

.media-library-items {
  flex: 1 1 0%;
  min-block-size: 0;
  padding-block: 4px 20px;
  padding-inline: 24px;
}

.media-library-list :deep(.v-list-item-title),
.media-library-summary .text-subtitle-2 {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.media-library-pagination {
  flex: 0 0 auto;
}

.media-library-preview {
  display: flex;
  border-inline-start: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
  min-block-size: 0;
  min-inline-size: 0;
}

.media-library-preview-scroll {
  flex: 1 1 0%;
  padding: 24px;
  min-block-size: 0;
}

.media-library-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 24px;
  min-block-size: 240px;
  text-align: center;
}

@media (max-width: 959.98px) {
  .media-library-dialog-body {
    overflow-y: auto !important;
  }

  .media-library-toolbar {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
  }

  .media-library-search {
    grid-column: 1 / 3;
  }

  .media-library-upload {
    grid-column: 1 / -1;
  }

  .media-library-panels {
    display: flex;
    flex: 0 0 auto;
    flex-direction: column;
  }

  .media-library-main {
    block-size: min(460px, 65dvh);
    min-block-size: 320px;
  }

  .media-library-preview {
    border-block-start: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-inline-start: 0;
  }

  .media-library-preview-scroll {
    flex: 1 1 auto;
  }

  .media-library-preview--empty {
    display: none;
  }
}

@media (max-width: 599.98px) {
  .media-library-toolbar {
    padding: 16px;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  }

  .media-library-search {
    grid-column: 1 / -1;
  }

  .media-library-upload {
    grid-column: auto;
  }

  .media-library-summary {
    padding: 16px;
  }

  .media-library-items {
    padding-block: 4px 16px;
    padding-inline: 16px;
  }

  .media-library-preview-scroll {
    padding: 16px;
  }
}
</style>
