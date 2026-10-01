<!--
  =====================================================================
  CHỨC NĂNG FILE: Layout dùng chung cho dialog/picker Media Library.
  =====================================================================

  Component giữ hierarchy ba vùng của project: category/filter, grid asset và
  detail panel. Nó chỉ phát event cho dialog cha; việc attach, upload và retry
  vẫn do store/service của caller quyết định.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - fileOf()/previewOf()/isSelected(): đọc metadata và selection.
  - categories, selectedPreview*: computed cho sidebar/detail.
  - syncDetailDraft(): đồng bộ asset đang preview vào draft metadata.
  - selectCategory(), handleToggle(), saveDetailDraft(), copyFileUrl().
  - openFilePicker(), handleUploadInput(): chọn file và emit upload.
  - watcher previewAsset: cập nhật detail draft khi đổi item.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : assets, selectedAssets, previewAsset, capability, filter/pagination models.
  - OUTPUT: close/confirm/refresh/retry/toggle/upload và update preview event.
  =====================================================================
-->
<script setup>
import { computed, reactive, shallowRef, useTemplateRef, watch } from 'vue'
import { PerfectScrollbar } from 'vue3-perfect-scrollbar'
import MediaAssetGrid from './MediaAssetGrid.vue'
import { kindAccept } from './mediaAssetFields'

const props = defineProps({
  fieldTitle: {
    type: String,
    default: 'Media Library',
  },
  fieldName: {
    type: String,
    default: '',
  },
  fieldKind: {
    type: String,
    default: null,
  },
  fieldKindMismatch: {
    type: Boolean,
    default: false,
  },
  kindLocked: {
    type: Boolean,
    default: false,
  },
  effectiveKind: {
    type: String,
    default: null,
  },
  canAttach: {
    type: Boolean,
    default: true,
  },
  canUpload: {
    type: Boolean,
    default: false,
  },
  canRetry: {
    type: Boolean,
    default: false,
  },
  isLoading: {
    type: Boolean,
    default: false,
  },
  isMutating: {
    type: Boolean,
    default: false,
  },
  hasError: {
    type: Boolean,
    default: false,
  },
  errorMessage: {
    type: String,
    default: '',
  },
  assets: {
    type: Array,
    default: () => [],
  },
  selectedAssets: {
    type: Array,
    default: () => [],
  },
  previewAsset: {
    type: Object,
    default: null,
  },
  multiple: {
    type: Boolean,
    default: false,
  },
  itemsLength: {
    type: Number,
    default: 0,
  },
})

const emit = defineEmits([
  'close',
  'confirm',
  'refresh',
  'retry',
  'toggle',
  'upload',
  'update:previewAsset',
])

const searchQuery = defineModel('searchQuery', { type: String, default: '' })
const selectedVisibility = defineModel('selectedVisibility', { type: String, default: null })
const selectedScanStatus = defineModel('selectedScanStatus', { type: String, default: null })
const selectedKind = defineModel('selectedKind', { type: String, default: null })
const page = defineModel('page', { type: Number, default: 1 })
const itemsPerPage = defineModel('itemsPerPage', { type: Number, default: 12 })

const viewMode = shallowRef('grid')
const uploadInput = useTemplateRef('uploadInput')

const detailDraft = reactive({
  altText: '',
  caption: '',
  description: '',
})

const savedDetailDraft = shallowRef({
  altText: '',
  caption: '',
  description: '',
})

const kindOptions = [
  { title: 'All Types', value: null },
  { title: 'Images', value: 'image' },
  { title: 'Videos', value: 'video' },
  { title: 'Documents', value: 'document' },
  { title: 'Archives', value: 'archive' },
]

const scanStatusOptions = [
  { title: 'All Scan Status', value: null },
  { title: 'Rejected', value: 'rejected' },
  { title: 'Error', value: 'error' },
]

const categories = computed(() => [
  {
    name: 'All Media',
    value: null,
    icon: 'tabler-folder',
    count: props.assets.length,
  },
  {
    name: 'Images',
    value: 'image',
    icon: 'tabler-photo',
    count: props.assets.filter(asset => asset.kind === 'image').length,
  },
  {
    name: 'Videos',
    value: 'video',
    icon: 'tabler-video',
    count: props.assets.filter(asset => asset.kind === 'video').length,
  },
  {
    name: 'Documents',
    value: 'document',
    icon: 'tabler-file-text',
    count: props.assets.filter(asset => asset.kind === 'document').length,
  },
  {
    name: 'Archives',
    value: 'archive',
    icon: 'tabler-file-zip',
    count: props.assets.filter(asset => asset.kind === 'archive').length,
  },
])

/** Input: asset. Output: file metadata hoặc object rỗng. */
const fileOf = asset => asset?.file ?? {}

/** Input: asset. Output: preview URL public hoặc null. */
const previewOf = asset => fileOf(asset).preview_url || fileOf(asset).url || null

/** Input: asset. Output: true khi asset nằm trong selection hiện tại. */
const isSelected = asset => props.selectedAssets.some(selected => selected.id === asset.id)

const selectedPreviewFile = computed(() => fileOf(props.previewAsset))
const selectedPreviewUrl = computed(() => previewOf(props.previewAsset))
const selectedPreviewFileUrl = computed(() => selectedPreviewFile.value.url || selectedPreviewFile.value.preview_url || '—')

const selectedPreviewDate = computed(() => {
  if (!props.previewAsset?.created_at)
    return '—'

  return new Intl.DateTimeFormat('vi-VN', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(props.previewAsset.created_at))
})

const selectedPreviewSize = computed(() => {
  const size = Number(selectedPreviewFile.value.size)

  if (!Number.isFinite(size) || size <= 0)
    return '—'

  const units = ['B', 'KB', 'MB', 'GB']
  const exponent = Math.min(Math.floor(Math.log(size) / Math.log(1024)), units.length - 1)

  return `${(size / 1024 ** exponent).toFixed(exponent === 0 ? 0 : 1)} ${units[exponent]}`
})

const selectedPreviewDimensions = computed(() => {
  const width = selectedPreviewFile.value.width
  const height = selectedPreviewFile.value.height

  return width && height ? `${width} × ${height}` : '—'
})

/** Input: status backend. Output: nhãn hiển thị Title Case. */
const formatStatus = status => status
  ? status.replaceAll('_', ' ').replace(/\b\w/g, character => character.toUpperCase())
  : '—'

const selectedPreviewKind = computed(() => props.previewAsset?.kind || '—')
const selectedPreviewMimeType = computed(() => selectedPreviewFile.value.mime_type || '—')
const selectedPreviewVisibility = computed(() => props.previewAsset?.visibility || '—')
const selectedPreviewScanStatusValue = computed(() => selectedPreviewFile.value.scan_status || null)

const selectedPreviewHasScanStatus = computed(() => (
  selectedPreviewScanStatusValue.value
  && !['pending', 'ready', 'clean'].includes(selectedPreviewScanStatusValue.value)
))

const selectedPreviewScanStatus = computed(() => formatStatus(selectedPreviewScanStatusValue.value))

const selectedPreviewHasConversionStatus = computed(() => (
  selectedPreviewFile.value.conversion_status
  && !['pending', 'ready'].includes(selectedPreviewFile.value.conversion_status)
))

const selectedPreviewConversionStatus = computed(() => formatStatus(selectedPreviewFile.value.conversion_status))

const hasDetailChanges = computed(() => (
  detailDraft.altText !== savedDetailDraft.value.altText
  || detailDraft.caption !== savedDetailDraft.value.caption
  || detailDraft.description !== savedDetailDraft.value.description
))

/** Input: asset preview mới. Output: đồng bộ draft metadata local. */
const syncDetailDraft = asset => {
  detailDraft.altText = asset?.alt_text || ''
  detailDraft.caption = asset?.caption || ''
  detailDraft.description = asset?.description || ''
  savedDetailDraft.value = { ...detailDraft }
}

watch(() => props.previewAsset, syncDetailDraft, { immediate: true })

/** Input: category sidebar. Output: cập nhật kind filter nếu không bị khóa. */
const selectCategory = category => {
  if (props.kindLocked && category.value !== props.effectiveKind)
    return

  selectedKind.value = category.value
}

/** Input: asset card. Output: emit preview và toggle lên dialog cha. */
const handleToggle = asset => {
  emit('update:previewAsset', asset)
  emit('toggle', asset)
}

/** Input: không có. Output: lưu draft metadata local qua event cha khi cần. */
const saveDetailDraft = () => {
  if (!props.previewAsset || !hasDetailChanges.value)
    return

  savedDetailDraft.value = { ...detailDraft }
}

/** Input: không có. Output: copy URL preview vào clipboard nếu được phép. */
const copyFileUrl = async () => {
  if (!selectedPreviewFileUrl.value || selectedPreviewFileUrl.value === '—')
    return

  await navigator.clipboard?.writeText(selectedPreviewFileUrl.value)
}

/** Input: không có. Output: mở input file khi upload capability hợp lệ. */
const openFilePicker = () => {
  if (!props.canUpload || props.isMutating)
    return

  uploadInput.value?.click()
}

/** Input: change event của input file. Output: emit multipart upload payload. */
const handleUploadInput = event => {
  const file = event.target.files?.[0]

  event.target.value = ''

  if (!file || !props.canUpload || props.isMutating)
    return

  const title = file.name?.replace(/\.[^/.]+$/, '') || file.name || 'Uploaded file'
  const kind = props.effectiveKind || 'image'

  emit('upload', {
    file,
    kind,
    title,
    visibility: kind === 'archive' ? 'private' : selectedVisibility.value || 'public',
  })
}
</script>

<template>
  <VCard class="media-library-dialog-card d-flex flex-column">
    <VCardItem class="px-6 py-4 border-b">
      <template #prepend>
        <VAvatar
          color="primary"
          variant="tonal"
          rounded="lg"
          size="42"
          class="me-3"
        >
          <VIcon
            icon="tabler-photo"
            size="22"
          />
        </VAvatar>
      </template>
      <VCardTitle>Select Media</VCardTitle>
      <VCardSubtitle>
        {{ props.fieldTitle }}
        <span
          v-if="props.fieldName"
          class="text-caption text-medium-emphasis"
        >
          · {{ props.fieldName }} ({{ props.fieldKind || props.effectiveKind || '—' }})
        </span>
        · Chọn media từ thư viện hoặc upload file mới.
      </VCardSubtitle>
    </VCardItem>

    <VCardText class="pa-0 media-library-dialog-body">
      <VRow
        no-gutters
        class="fill-height media-library-dialog-content"
      >
        <VCol
          cols="12"
          md="3"
          lg="2"
          class="border-e pa-4 d-flex flex-column justify-space-between media-library-sidebar"
        >
          <PerfectScrollbar
            class="media-library-sidebar-list flex-grow-1"
            :options="{ wheelPropagation: false, suppressScrollX: true }"
          >
            <VList
              density="compact"
              nav
              class="pa-0 bg-transparent"
            >
              <VListItem
                v-for="category in categories"
                :key="category.name"
                :active="selectedKind === category.value"
                :disabled="props.kindLocked && category.value !== props.effectiveKind"
                :prepend-icon="category.icon"
                active-color="primary"
                rounded="lg"
                class="media-library-sidebar-item mb-1"
                @click="selectCategory(category)"
              >
                <VListItemTitle class="media-library-sidebar-title">
                  {{ category.name }}
                </VListItemTitle>
                <template #append>
                  <VChip
                    size="x-small"
                    :color="selectedKind === category.value ? 'primary' : 'secondary'"
                    variant="tonal"
                  >
                    {{ category.count }}
                  </VChip>
                </template>
              </VListItem>
            </VList>
          </PerfectScrollbar>

          <VCard
            border
            elevation="0"
            class="pa-4 mt-4 flex-shrink-0"
          >
            <div class="d-flex align-center mb-2">
              <VIcon
                icon="tabler-cloud"
                size="18"
                color="primary"
                class="me-2"
              />
              <span class="text-caption font-weight-bold">Storage Usage</span>
            </div>
            <VProgressLinear
              :model-value="48"
              color="primary"
              height="6"
              rounded
              class="mb-2"
            />
            <div class="d-flex justify-space-between text-caption text-medium-emphasis mb-3">
              <span>2.4 GB of 5 GB used</span>
              <span class="font-weight-bold">48%</span>
            </div>
            <VBtn
              block
              color="primary"
              prepend-icon="tabler-upload"
              :disabled="!props.canUpload || props.isMutating"
              :loading="props.isMutating"
              class="text-none"
              @click="openFilePicker"
            >
              Upload Files
            </VBtn>
            <input
              ref="uploadInput"
              class="d-none"
              type="file"
              :accept="kindAccept(props.effectiveKind || 'image')"
              :disabled="!props.canUpload || props.isMutating"
              @change="handleUploadInput"
            >
          </VCard>
        </VCol>

        <VCol
          cols="12"
          md="6"
          lg="7"
          class="pa-5 border-e media-main-column"
        >
          <VRow
            align="center"
            class="mb-4 media-filters"
          >
            <VCol
              cols="12"
              sm="5"
            >
              <AppTextField
                v-model="searchQuery"
                label="Search media"
                placeholder="Search media..."
                prepend-inner-icon="tabler-search"
                clearable
                hide-details
              />
            </VCol>
            <VCol
              cols="6"
              sm="3"
            >
              <AppSelect
                v-model="selectedKind"
                label="Type"
                :items="kindOptions"
                :disabled="props.kindLocked"
                clearable
                hide-details
              />
            </VCol>
            <VCol
              cols="6"
              sm="2"
            >
              <AppSelect
                v-model="selectedScanStatus"
                label="Scan status"
                :items="scanStatusOptions"
                clearable
                hide-details
              />
            </VCol>
            <VCol
              cols="12"
              sm="2"
              class="d-flex justify-center"
            >
              <VBtnToggle
                v-model="viewMode"
                mandatory
                density="comfortable"
                color="primary"
                rounded="lg"
              >
                <VBtn
                  icon="tabler-layout-grid"
                  value="grid"
                  size="small"
                  aria-label="Grid view"
                />
                <VBtn
                  icon="tabler-list"
                  value="list"
                  size="small"
                  aria-label="List view"
                />
              </VBtnToggle>
            </VCol>
          </VRow>

          <VAlert
            v-if="props.fieldKindMismatch"
            color="error"
            variant="tonal"
            class="mb-4"
          >
            Field này không tương thích với loại media đang chọn.
          </VAlert>

          <VAlert
            v-else-if="!props.canAttach"
            color="warning"
            variant="tonal"
            class="mb-4"
          >
            Bạn có thể xem file nhưng không có quyền attach media vào field này.
          </VAlert>

          <VProgressLinear
            v-if="props.isLoading"
            indeterminate
            color="primary"
            class="mb-4"
          />

          <VAlert
            v-if="props.hasError"
            color="error"
            variant="tonal"
            class="mb-4"
          >
            {{ props.errorMessage }}
            <VBtn
              variant="text"
              color="error"
              class="ms-2"
              @click="emit('refresh')"
            >
              Retry
            </VBtn>
          </VAlert>

          <VAlert
            v-if="!props.isLoading && !props.hasError && !props.assets.length && !props.fieldKindMismatch"
            color="info"
            variant="tonal"
            class="mb-4"
          >
            Không có file phù hợp với bộ lọc hiện tại.
          </VAlert>

          <PerfectScrollbar
            class="media-items-scroll"
            :options="{ wheelPropagation: false, suppressScrollX: true }"
          >
            <MediaAssetGrid
              v-if="viewMode === 'grid' && !props.fieldKindMismatch"
              :assets="props.assets"
              :selected-assets="props.selectedAssets"
              :multiple="props.multiple"
              :can-select="props.canAttach"
              :can-retry="props.canRetry"
              @toggle="handleToggle"
              @retry="emit('retry', $event)"
            />

            <VList
              v-else-if="viewMode === 'list' && !props.fieldKindMismatch"
              lines="one"
              border
              rounded="lg"
            >
              <VListItem
                v-for="asset in props.assets"
                :key="asset.id"
                :active="isSelected(asset)"
                active-color="primary"
                @click="handleToggle(asset)"
              >
                <template #prepend>
                  <VAvatar
                    rounded="lg"
                    size="48"
                    variant="tonal"
                    color="primary"
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
              </VListItem>
            </VList>
          </PerfectScrollbar>

          <div
            v-if="!props.fieldKindMismatch"
            class="media-pagination mx-n5"
          >
            <TablePagination
              :page="page"
              :items-per-page="itemsPerPage"
              :total-items="props.itemsLength"
              @update:page="page = $event"
            />
          </div>
        </VCol>

        <VCol
          cols="12"
          md="3"
          lg="3"
          class="pa-5 d-flex flex-column media-details-column"
          :class="{ 'media-details-column--empty': !props.previewAsset }"
        >
          <PerfectScrollbar
            v-if="props.previewAsset"
            class="media-details-scroll"
            :options="{ wheelPropagation: false, suppressScrollX: true }"
          >
            <VCardTitle class="px-0 pt-0 text-subtitle-2">
              Preview
            </VCardTitle>

            <VImg
              v-if="selectedPreviewUrl && props.previewAsset.kind === 'image'"
              :src="selectedPreviewUrl"
              height="160"
              cover
              rounded="lg"
              class="border mb-3 bg-grey-lighten-3"
            />
            <VSheet
              v-else
              height="160"
              rounded="lg"
              border
              class="d-flex align-center justify-center mb-3"
            >
              <VIcon
                icon="tabler-file"
                size="52"
                color="primary"
              />
            </VSheet>

            <div class="d-flex justify-space-between align-start mb-3 gap-2">
              <div class="media-preview-heading">
                <div class="media-preview-title text-subtitle-1 font-weight-bold">
                  {{ props.previewAsset.title }}
                </div>
              </div>
            </div>

            <div class="d-flex flex-column gap-3">
              <AppTextField
                v-model="detailDraft.altText"
                label="Alt Text"
              />
              <AppTextarea
                v-model="detailDraft.caption"
                label="Caption"
                rows="2"
              />
              <AppTextarea
                v-model="detailDraft.description"
                label="Description"
                rows="3"
              />
              <AppTextField
                label="File URL"
                :model-value="selectedPreviewFileUrl"
                readonly
              >
                <template #append-inner>
                  <VBtn
                    icon="tabler-copy"
                    size="x-small"
                    variant="text"
                    color="primary"
                    aria-label="Copy file URL"
                    @click="copyFileUrl"
                  />
                </template>
              </AppTextField>
              <VList
                density="compact"
                class="media-info-list pa-0 bg-transparent"
              >
                <VListSubheader class="px-0 text-caption text-medium-emphasis">
                  File information
                </VListSubheader>
                <VListItem
                  title="Type"
                  class="media-info-row px-0"
                >
                  <template #prepend>
                    <VIcon
                      icon="tabler-file-type"
                      size="18"
                      class="me-2"
                    />
                  </template>
                  <template #append>
                    <span class="media-info-value text-body-2 text-medium-emphasis">
                      {{ selectedPreviewKind }}
                    </span>
                  </template>
                </VListItem>
                <VListItem
                  title="MIME type"
                  class="media-info-row px-0"
                >
                  <template #prepend>
                    <VIcon
                      icon="tabler-file-description"
                      size="18"
                      class="me-2"
                    />
                  </template>
                  <template #append>
                    <span class="media-info-value text-body-2 text-medium-emphasis">
                      {{ selectedPreviewMimeType }}
                    </span>
                  </template>
                </VListItem>
                <VListItem
                  title="Visibility"
                  class="media-info-row px-0"
                >
                  <template #prepend>
                    <VIcon
                      icon="tabler-eye"
                      size="18"
                      class="me-2"
                    />
                  </template>
                  <template #append>
                    <span class="media-info-value text-body-2 text-medium-emphasis">
                      {{ selectedPreviewVisibility }}
                    </span>
                  </template>
                </VListItem>
                <VListItem
                  title="Dimensions"
                  class="media-info-row px-0"
                >
                  <template #prepend>
                    <VIcon
                      icon="tabler-aspect-ratio"
                      size="18"
                      class="me-2"
                    />
                  </template>
                  <template #append>
                    <span class="media-info-value text-body-2 text-medium-emphasis">
                      {{ selectedPreviewDimensions }}
                    </span>
                  </template>
                </VListItem>
                <VListItem
                  title="File size"
                  class="media-info-row px-0"
                >
                  <template #prepend>
                    <VIcon
                      icon="tabler-file-zip"
                      size="18"
                      class="me-2"
                    />
                  </template>
                  <template #append>
                    <span class="media-info-value text-body-2 text-medium-emphasis">
                      {{ selectedPreviewSize }}
                    </span>
                  </template>
                </VListItem>
                <VListItem
                  v-if="selectedPreviewHasScanStatus"
                  title="Scan status"
                  class="media-info-row px-0"
                >
                  <template #prepend>
                    <VIcon
                      icon="tabler-shield-check"
                      size="18"
                      class="me-2"
                    />
                  </template>
                  <template #append>
                    <span class="media-info-value text-body-2 text-medium-emphasis">
                      {{ selectedPreviewScanStatus }}
                    </span>
                  </template>
                </VListItem>
                <VListItem
                  v-if="selectedPreviewHasConversionStatus"
                  title="Conversion status"
                  class="media-info-row px-0"
                >
                  <template #prepend>
                    <VIcon
                      icon="tabler-arrows-converge"
                      size="18"
                      class="me-2"
                    />
                  </template>
                  <template #append>
                    <span class="media-info-value text-body-2 text-medium-emphasis">
                      {{ selectedPreviewConversionStatus }}
                    </span>
                  </template>
                </VListItem>
                <VListItem
                  title="Uploaded Date"
                  class="media-info-row px-0"
                >
                  <template #prepend>
                    <VIcon
                      icon="tabler-calendar"
                      size="18"
                      class="me-2"
                    />
                  </template>
                  <template #append>
                    <span class="media-info-value text-body-2 text-medium-emphasis">
                      {{ selectedPreviewDate }}
                    </span>
                  </template>
                </VListItem>
              </VList>
            </div>
          </PerfectScrollbar>
          <VAlert
            v-else
            color="info"
            class="media-details-empty-message mb-auto"
            variant="text"
          >
            Chọn một media để xem thông tin chi tiết.
          </VAlert>
          <VBtn
            v-if="props.previewAsset"
            :variant="hasDetailChanges ? 'flat' : 'tonal'"
            color="primary"
            prepend-icon="tabler-device-floppy"
            class="media-details-save align-self-end flex-shrink-0 mt-4 text-none"
            :disabled="!hasDetailChanges"
            @click="saveDetailDraft"
          >
            Save
          </VBtn>
        </VCol>
      </VRow>
    </VCardText>

    <VDivider />

    <VCardActions class="px-6 py-3 d-flex justify-space-between align-center flex-wrap gap-3">
      <div class="text-body-2 text-medium-emphasis">
        {{ props.selectedAssets.length }} file đã chọn
      </div>
      <div class="d-flex gap-2">
        <VBtn
          variant="tonal"
          :disabled="props.isMutating"
          @click="emit('close')"
        >
          Cancel
        </VBtn>
        <VBtn
          v-if="props.multiple"
          color="primary"
          :disabled="!props.canAttach || props.isMutating"
          prepend-icon="tabler-check"
          @click="emit('confirm')"
        >
          Select Media
        </VBtn>
      </div>
    </VCardActions>
  </VCard>
</template>

<style scoped>
.media-library-dialog-card {
  block-size: 100%;
  min-block-size: 0;
  overflow: hidden !important;
}

.media-library-dialog-body {
  flex: 1 1 0%;
  block-size: 0;
  min-block-size: 0;
  overflow: hidden !important;
}

.media-library-dialog-content,
.media-library-sidebar,
.media-main-column,
.media-details-column {
  block-size: 100%;
  min-block-size: 0;
}

.media-library-dialog-content {
  background-color: rgb(var(--v-theme-surface));
}

.media-library-sidebar {
  overflow: hidden;
  background-color: rgb(var(--v-theme-surface));
}

.media-library-sidebar-list {
  min-block-size: 0;
}

.media-library-sidebar-item {
  color: rgba(var(--v-theme-on-surface), 0.9);
}

.media-library-sidebar-item :deep(.v-list-item-title) {
  color: rgba(var(--v-theme-on-surface), 0.9);
  font-size: 0.875rem;
  font-weight: 600;
  letter-spacing: 0;
}

.media-library-sidebar-item :deep(.v-list-item__prepend > .v-icon) {
  color: rgba(var(--v-theme-on-surface), 0.82);
  opacity: 1;
}

.media-library-sidebar-item :deep(.v-list-item__append) {
  opacity: 1;
}

.media-library-sidebar-item :deep(.v-chip) {
  font-weight: 600;
}

.media-details-column {
  overflow: hidden;
  background-color: rgb(var(--v-theme-surface));
}

.media-details-column--empty {
  background-color: rgb(var(--v-theme-surface));
}

.media-details-scroll {
  flex: 1 1 auto;
  min-block-size: 0;
}

.media-main-column {
  display: flex;
  flex-direction: column;
  background-color: rgb(var(--v-theme-surface));
}

.media-filters {
  flex: 0 0 auto;
  background-color: rgb(var(--v-theme-surface));
}

.media-pagination {
  flex: 0 0 auto;
  background-color: rgb(var(--v-theme-surface));
}

.media-pagination {
  inline-size: auto;
}

.media-preview-title {
  display: block;
  max-block-size: 3em;
  overflow: hidden;
  overflow-wrap: anywhere;
  text-overflow: clip;
  white-space: normal;
  word-break: break-word;
  line-height: 1.5;
}

.media-preview-heading {
  min-inline-size: 0;
}

.media-info-list {
  overflow: visible;
}

.media-info-row {
  min-block-size: 40px !important;
  border-block-end: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.media-info-row:last-child {
  border-block-end: 0;
}

.media-info-row :deep(.v-list-item__content) {
  min-inline-size: 0;
}

.media-info-row :deep(.v-list-item-title) {
  flex: 0 0 auto;
  white-space: nowrap;
}

.media-info-row :deep(.v-list-item__append) {
  min-inline-size: 0;
  margin-inline-start: 0.5rem;
  flex: 0 1 auto;
}

.media-info-value {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  text-align: end;
  white-space: nowrap;
}

.media-items-scroll {
  flex: 1 1 auto;
  min-block-size: 0;
  background-color: rgb(var(--v-theme-surface));
}

@media (max-width: 959.98px) {
  .media-library-dialog-body {
    block-size: 0;
    overflow-y: auto !important;
  }

  .media-library-dialog-content,
  .media-library-sidebar,
  .media-main-column,
  .media-details-column {
    block-size: auto;
  }
}
</style>
