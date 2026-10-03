<!--
  =====================================================================
  CHỨC NĂNG FILE: Trang Media Asset giao diện quản lý Media Library
  =====================================================================

  Khôi phục bố cục ba cột nguyên bản và dùng dữ liệu thật từ Media Asset API.
  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - routeQueryValue()/parsePositiveInteger()/categoryRoute(): chuẩn hóa route.
  - displayFile()/formatStatus()/formatUploadDate(): map metadata card/panel.
  - selectedPreview*/copySelectedFileUrl(): dữ liệu panel chi tiết bên phải.
  - load()/select()/mutate()/upload()/remove()/update()/retry()/download():
  điều phối Pinia store cho list/detail/mutation.
  - handleUpload/handleUpdate/handleDelete(): event từ dialog.
  - toggleFileSelection()/resetDemoView()/selectFile(): thao tác UI local.
  - syncStateFromRoute()/syncRouteQuery()/queriesEqual(): đồng bộ URL filter.
  - updateItemsPerPage()/updateMediaScrollbars(): pagination/layout.
  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : thao tác filter, category, view mode, pagination và chọn file.
  - OUTPUT: giao diện Media Asset tại /apps/media/media-asset; state dùng
  trực tiếp useMediaAssetStore làm source of truth.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Laravel query keys preserve snake_case contract. */
import {
  computed,
  nextTick,
  onMounted,
  shallowRef,
  useTemplateRef,
  watch,
} from 'vue'
import { storeToRefs } from 'pinia'
import { useMediaAssetStore } from '@/stores/mediaAsset'
import MediaAssetUploadDialog from '@/views/apps/media/MediaAssetUploadDialog.vue'
import MediaAssetDetails from '@/views/apps/media/MediaAssetDetails.vue'
import { PerfectScrollbar } from 'vue3-perfect-scrollbar'
import csvFileIcon from '@images/icons/file/media-demo/csv.png'
import docxFileIcon from '@images/icons/file/media-demo/docx.png'
import mp3FileIcon from '@images/icons/file/media-demo/mp3.png'
import pdfFileIcon from '@images/icons/file/media-demo/pdf.png'
import pptxFileIcon from '@images/icons/file/media-demo/pptx.png'
import svgFileIcon from '@images/icons/file/media-demo/svg.png'
import txtFileIcon from '@images/icons/file/media-demo/txt.png'
import xlsxFileIcon from '@images/icons/file/media-demo/xlsx.png'
import zipFileIcon from '@images/icons/file/media-demo/zip.png'

definePage({
  meta: {
    navActiveLink: 'apps-media-media-asset',
    layoutWrapperClasses: 'layout-content-height-fixed',
  },
})

const route = useRoute()
const router = useRouter()

const folderFilterOptions = [
  { value: 'all', label: 'Tất cả thư mục' },
  { value: 'image', label: 'Hình ảnh' },
  { value: 'video', label: 'Video' },
  { value: 'document', label: 'Tài liệu' },
]

const folderFilterByValue = Object.fromEntries(folderFilterOptions.map(option => [option.value, option.label]))
const folderFilterByLabel = Object.fromEntries(folderFilterOptions.map(option => [option.label, option.value]))

const sortByValue = {
  newest: 'Mới nhất',
  oldest: 'Cũ nhất',
  name: 'Tên A-Z',
  size: 'Kích thước',
}

const scanStatusOptions = [
  { title: 'Scan: tất cả', value: null },
  { title: 'Scan: pending', value: 'pending' },
  { title: 'Scan: clean', value: 'clean' },
  { title: 'Scan: error', value: 'error' },
]

const conversionStatusOptions = [
  { title: 'Conversion: tất cả', value: null },
  { title: 'Conversion: pending', value: 'pending' },
  { title: 'Conversion: ready', value: 'ready' },
  { title: 'Conversion: failed', value: 'failed' },
]

/** Input: query key. Output: scalar route value hoặc undefined. */
const routeQueryValue = key => {
  const value = route.query[key]

  return Array.isArray(value) ? value[0] : value
}

/** Input: query value/fallback. Output: số nguyên dương hợp lệ. */
const parsePositiveInteger = (value, fallback) => {
  const parsed = Number.parseInt(value, 10)

  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback
}


const search = shallowRef(routeQueryValue('q') ?? '')
const folderFilter = shallowRef(folderFilterByValue[routeQueryValue('type')] ?? folderFilterOptions[0].label)
const sortBy = shallowRef(sortByValue[routeQueryValue('sort')] ?? sortByValue.newest)
const viewMode = shallowRef(['grid', 'list'].includes(routeQueryValue('view')) ? routeQueryValue('view') : 'grid')
const scanStatus = shallowRef(routeQueryValue('scan') || null)
const conversionStatus = shallowRef(routeQueryValue('conversion') || null)
const page = shallowRef(parsePositiveInteger(routeQueryValue('page'), 1))
const initialItemsPerPage = parsePositiveInteger(routeQueryValue('perPage'), 12)
const itemsPerPage = shallowRef([12, 24, 48].includes(initialItemsPerPage) ? initialItemsPerPage : 12)
const uploadVisible = shallowRef(false)
const detailsVisible = shallowRef(false)
const folderNoticeVisible = shallowRef(false)
const filesScrollbar = useTemplateRef('filesScrollbar')
const sidebarScrollbar = useTemplateRef('sidebarScrollbar')

const categories = [
  { slug: 'all', name: 'Tất cả tệp', icon: 'tabler-folder' },
  { slug: 'images', name: 'Hình ảnh', icon: 'tabler-photo', type: 'image' },
  { slug: 'videos', name: 'Video', icon: 'tabler-video', type: 'video' },
  { slug: 'documents', name: 'Tài liệu', icon: 'tabler-file-text', type: 'document' },
  { slug: 'trash', name: 'Thùng rác', icon: 'tabler-trash', isTrash: true },
]

const activeCategory = computed(() => categories.find(category => category.slug === route.params.folder) ?? categories[0])
const selectedCategory = computed(() => activeCategory.value.name)

/** Input: category slug. Output: route object giữ query hiện tại. */
const categoryRoute = slug => {
  const query = { ...route.query }

  delete query.page
  delete query.type

  if (slug === 'all')
    return { name: 'apps-media-media-asset', query }

  return {
    name: 'apps-media-media-asset-folder',
    params: { folder: slug },
    query,
  }
}

const fileTypeThumbnails = {
  csv: csvFileIcon,
  docx: docxFileIcon,
  mp3: mp3FileIcon,
  mp4: mp3FileIcon,
  pdf: pdfFileIcon,
  pptx: pptxFileIcon,
  svg: svgFileIcon,
  txt: txtFileIcon,
  xlsx: xlsxFileIcon,
  zip: zipFileIcon,
}

/* API query dùng pagination/sort phía server, giữ nguyên query URL của UI. */
const apiQuery = computed(() => ({
  search: search.value?.trim() || undefined,
  kind: activeCategory.value.type
    || (folderFilterByLabel[folderFilter.value] === 'all' ? undefined : folderFilterByLabel[folderFilter.value]),
  sort: sortBy.value === 'Tên A-Z' ? 'title' : 'created_at',
  direction: ['Cũ nhất', 'Tên A-Z'].includes(sortBy.value) ? 'asc' : 'desc',
  ['scan_status']: scanStatus.value || undefined,
  ['conversion_status']: conversionStatus.value || undefined,
  page: page.value,
  'per_page': itemsPerPage.value,
}))

const mediaStore = useMediaAssetStore()

const {
  items,
  itemsLength: total,
  selectedAsset: selected,
  isLoading: loading,
  isMutating: busy,
  uploadProgress: progress,
  error: requestError,
} = storeToRefs(mediaStore)

const error = computed(() => requestError.value?.data?.message || requestError.value?.message || '')

/** Input: không có. Output: tải list theo query hiện tại từ Pinia store. */
const load = () => mediaStore.fetchMediaAssets(apiQuery.value)

/** Input: asset ID và fallback card. Output: detail từ Pinia store. */
const select = (id, fallback = null) => mediaStore.fetchMediaAsset(id, fallback)

/** Input: mutation store. Output: boolean UI và list được refresh sau thành công. */
const mutate = async callback => {
  if (busy.value) return false
  try {
    await callback()
    await load()

    return true
  }
  catch {
    return false
  }
}

/** Input: upload payload. Output: upload asset và đồng bộ list. */
const upload = payload => mutate(() => mediaStore.uploadMediaAsset(payload))

/** Input: asset ID. Output: asset bị xóa và list được đồng bộ. */
const remove = id => mutate(() => mediaStore.deleteMediaAsset(id))

/** Input: asset ID/metadata. Output: asset được cập nhật. */
const update = (id, payload) => mutate(() => mediaStore.updateMediaAsset(id, payload))

/** Input: asset ID. Output: asset được đưa lại vào queue xử lý. */
const retry = id => mutate(() => mediaStore.retryMediaAsset(id))

/** Input: asset. Output: stream/temporary URL qua service backend. */
const download = asset => mutate(() => mediaStore.downloadFile(asset))

watch(apiQuery, load, { immediate: true, deep: true })

/** Input: API asset. Output: metadata tương thích card/panel cũ, không tạo dữ liệu mẫu. */
const displayFile = asset => {
  if (!asset) return null
  const name = asset.file?.original_name || asset.title
  const ext = name.split('.').pop()?.toLowerCase()
  const bytes = Number(asset.file?.size || 0)

  return {
    ...asset,
    name,
    ext: ext?.toUpperCase(),
    size: bytes < 1024 ? `${bytes} B` : `${(bytes / 1024 / 1024).toFixed(2)} MB`,
    type: asset.kind,
    typeLabel: asset.file?.mime_type || asset.kind,
    resolution: asset.file?.width && asset.file?.height ? `${asset.file.width} × ${asset.file.height}` : '—',
    thumbnail: asset.file?.preview_url || (asset.kind === 'image' ? asset.file?.url : null) || fileTypeThumbnails[ext],
    thumbnailMode: asset.kind === 'image' ? 'cover' : 'contain',
  }
}

const selectedFile = computed(() => displayFile(selected.value))
const isTrashView = computed(() => Boolean(activeCategory.value.isTrash))

const selectedPreviewFile = computed(() => selectedFile.value?.file ?? {})

const selectedPreviewImageUrl = computed(() => {
  if (selectedFile.value?.type !== 'image')
    return null

  return selectedPreviewFile.value.preview_url || selectedPreviewFile.value.url || null
})

const selectedPreviewFileUrl = computed(() => selectedPreviewFile.value.url || selectedPreviewFile.value.preview_url || '—')
const selectedPreviewMimeType = computed(() => selectedPreviewFile.value.mime_type || '—')
const selectedPreviewVisibility = computed(() => selectedFile.value?.visibility || '—')
const selectedPreviewScanStatusValue = computed(() => selectedPreviewFile.value.scan_status || null)
const selectedPreviewConversionStatusValue = computed(() => selectedPreviewFile.value.conversion_status || null)

const selectedPreviewHasScanStatus = computed(() => (
  selectedPreviewScanStatusValue.value
  && !['clean', 'pending', 'ready'].includes(selectedPreviewScanStatusValue.value)
))

const selectedPreviewHasConversionStatus = computed(() => (
  selectedPreviewConversionStatusValue.value
  && !['pending', 'ready'].includes(selectedPreviewConversionStatusValue.value)
))

/** Input: status API. Output: nhãn status dạng dễ đọc trong panel chi tiết. */
const formatStatus = status => status
  ? status.replaceAll('_', ' ').replace(/\b\w/g, character => character.toUpperCase())
  : '—'

const selectedPreviewScanStatus = computed(() => formatStatus(selectedPreviewScanStatusValue.value))
const selectedPreviewConversionStatus = computed(() => formatStatus(selectedPreviewConversionStatusValue.value))

const filteredFiles = computed(() => {
  if (isTrashView.value)
    return []

  const files = items.value.map(displayFile)

  return sortBy.value === 'Kích thước'
    ? [...files].sort((first, second) => Number(second.file?.size || 0) - Number(first.file?.size || 0))
    : files
})

const checkedIds = shallowRef([])
const closeDetails = () => mediaStore.clearSelection()

/**
 * Định dạng ngày tải lên theo locale tiếng Việt cho panel thông tin.
 *
 * Input: chuỗi ISO hoặc giá trị ngày hợp lệ.
 * Output: ngày tiếng Việt; trả dấu gạch khi API không có ngày.
 */
const formatUploadDate = value => value
  ? new Intl.DateTimeFormat('vi-VN', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(value))
  : '—'

/** Input: URL public của asset đang chọn. Output: sao chép URL nếu trình duyệt hỗ trợ. */
const copySelectedFileUrl = async () => {
  if (!selectedPreviewFileUrl.value || selectedPreviewFileUrl.value === '—')
    return

  await navigator.clipboard?.writeText(selectedPreviewFileUrl.value)
}

const handleUpload = async payload => {
  if (await upload({ ...payload, file: Array.isArray(payload.file) ? payload.file[0] : payload.file }))
    uploadVisible.value = false
}

const handleUpdate = async payload => {
  if (await update(payload.id, payload.data))
    detailsVisible.value = false
}

const handleDelete = async () => {
  if (selected.value && window.confirm(`Xóa "${selected.value.title}"?`))
    await remove(selected.value.id)
}

const routeQueryKeys = ['q', 'type', 'sort', 'page', 'perPage', 'view', 'scan', 'conversion']

/** Input: route query value. Output: chuỗi chuẩn để so sánh. */
const normalizedQueryValue = value => {
  if (Array.isArray(value))
    return value.join(',')

  return value == null ? undefined : String(value)
}

/** Input: hai route query. Output: true nếu cùng giá trị hiển thị. */
const queriesEqual = (firstQuery, secondQuery) => {
  const keys = new Set([...Object.keys(firstQuery), ...Object.keys(secondQuery)])

  return [...keys].every(key => normalizedQueryValue(firstQuery[key]) === normalizedQueryValue(secondQuery[key]))
}

/** Input: route hiện tại. Output: đồng bộ filter local từ URL. */
const syncStateFromRoute = () => {
  const nextSearch = routeQueryValue('q') ?? ''
  const nextFolderFilter = folderFilterByValue[routeQueryValue('type')] ?? folderFilterOptions[0].label
  const nextSortBy = sortByValue[routeQueryValue('sort')] ?? sortByValue.newest
  const nextViewMode = ['grid', 'list'].includes(routeQueryValue('view')) ? routeQueryValue('view') : 'grid'
  const nextScanStatus = scanStatusOptions.some(item => item.value === routeQueryValue('scan')) ? routeQueryValue('scan') : null
  const nextConversionStatus = conversionStatusOptions.some(item => item.value === routeQueryValue('conversion')) ? routeQueryValue('conversion') : null
  const nextPage = parsePositiveInteger(routeQueryValue('page'), 1)
  const nextItemsPerPageValue = parsePositiveInteger(routeQueryValue('perPage'), 12)

  search.value = nextSearch
  folderFilter.value = nextFolderFilter
  sortBy.value = nextSortBy
  viewMode.value = nextViewMode
  scanStatus.value = nextScanStatus
  conversionStatus.value = nextConversionStatus
  page.value = nextPage
  itemsPerPage.value = [12, 24, 48].includes(nextItemsPerPageValue) ? nextItemsPerPageValue : 12
}

/** Input: filter local. Output: cập nhật URL không reload trang. */
const syncRouteQuery = () => {
  const query = { ...route.query }

  routeQueryKeys.forEach(key => delete query[key])

  if (search.value?.trim())
    query.q = search.value.trim()
  if (folderFilterByLabel[folderFilter.value] !== 'all')
    query.type = folderFilterByLabel[folderFilter.value]
  if (sortBy.value !== sortByValue.newest)
    query.sort = Object.keys(sortByValue).find(key => sortByValue[key] === sortBy.value)
  if (page.value > 1)
    query.page = String(page.value)
  if (itemsPerPage.value !== 12)
    query.perPage = String(itemsPerPage.value)
  if (viewMode.value !== 'grid')
    query.view = viewMode.value
  if (scanStatus.value)
    query.scan = scanStatus.value
  if (conversionStatus.value)
    query.conversion = conversionStatus.value

  if (queriesEqual(query, route.query))
    return

  void router.replace({
    name: route.name,
    params: route.params,
    query,
  })
}

watch(
  () => [route.query.q, route.query.type, route.query.sort, route.query.page, route.query.perPage, route.query.view, route.query.scan, route.query.conversion],
  () => {
    syncStateFromRoute()
    syncRouteQuery()
  },
  { immediate: true },
)

watch(
  [search, folderFilter, sortBy, viewMode, page, itemsPerPage, scanStatus, conversionStatus],
  syncRouteQuery,
)

const visibleTotal = computed(() => isTrashView.value ? 0 : total.value)
const pageCount = computed(() => Math.max(1, Math.ceil(visibleTotal.value / itemsPerPage.value)))
const pagedFiles = filteredFiles

watch(pageCount, count => { if (page.value > count) page.value = count })
watch(() => route.params.folder, () => { page.value = 1; checkedIds.value = []; closeDetails() })
watch(filteredFiles, files => {
  if (isTrashView.value || !files.length) {
    closeDetails()

    return
  }

  if (!selected.value || !files.some(file => file.id === selected.value.id))
    void select(files[0].id, files[0])
}, { immediate: true })

/** Input: DOM sau render. Output: cập nhật scrollbar layout. */
const updateMediaScrollbars = async () => {
  await nextTick()
  filesScrollbar.value?.ps?.update()
  sidebarScrollbar.value?.ps?.update()
}

watch(
  [filteredFiles, pagedFiles, viewMode],
  updateMediaScrollbars,
  { flush: 'post' },
)

onMounted(updateMediaScrollbars)

/** Input: pagination state. Output: chuỗi range cho toolbar. */
const displayedRange = computed(() => {
  if (!visibleTotal.value) return '0 - 0 của 0 tệp'
  const start = (page.value - 1) * itemsPerPage.value + 1

  return `${start} - ${Math.min(start + items.value.length - 1, visibleTotal.value)} của ${visibleTotal.value} tệp`
})

/** Input: asset card. Output: chi tiết tải từ API. */
const selectFile = file => select(file.id, file)


/** Input: file. Output: chọn checkbox local, không tự attach usage. */
const toggleFileSelection = file => {
  checkedIds.value = checkedIds.value.includes(file.id)
    ? checkedIds.value.filter(id => id !== file.id)
    : [...checkedIds.value, file.id]
}


/** Input: không có. Output: refresh dữ liệu mà giữ filter hiện tại. */
const resetDemoView = () => load()

/** Input: không có. Output: mở dialog upload thật. */
const showUploadNotice = () => { uploadVisible.value = true }

/** Input: không có. Output: báo rõ API hiện chưa có nghiệp vụ tạo thư mục. */
const showFolderNotice = () => { folderNoticeVisible.value = true }

/** Input: page size UI. Output: page size mới và reset page đầu. */
const updateItemsPerPage = value => {
  itemsPerPage.value = Number(value)
  page.value = 1
}
</script>

<template>
  <div
    id="view-moi"
    class="bg-grey-lighten-4 media-manager d-flex flex-column"
  >
    <div class="d-flex flex-wrap justify-space-between gap-y-4 gap-x-6 mb-6 p-4">
      <div class="d-flex flex-column justify-center">
        <h4 class="text-h4 font-weight-medium">
          Media Asset
        </h4>
        <div class="text-body-1">
          Sandbox giao diện quản lý file dùng chung cho Resource, Post và Resource Version.
        </div>
      </div>

      <div class="d-flex gap-4 align-center flex-wrap">
        <VBtn
          variant="tonal"
          color="secondary"
          prepend-icon="tabler-refresh"
          @click="resetDemoView"
        >
          Refresh
        </VBtn>
        <VBtn
          prepend-icon="tabler-upload"
          @click="showUploadNotice"
        >
          Tải tệp lên
        </VBtn>
      </div>
    </div>

    <VRow
      class="media-manager__columns flex-grow-1"
      align="stretch"
      no-gutters
    >
      <VCol
        cols="12"
        md="3"
        lg="2"
        class="media-manager__column"
      >
        <VCard
          rounded="lg"
          border
          class="pa-2 h-100 d-flex flex-column media-manager__panel rounded-e-0"
        >
          <div class="d-flex align-center justify-space-between px-3 py-2">
            <span class="font-weight-bold text-subtitle-1">Thư mục</span>
            <VBtn
              icon="tabler-plus"
              size="x-small"
              variant="text"
              aria-label="Thêm thư mục"
              @click="showFolderNotice"
            />
          </div>
          <PerfectScrollbar
            ref="sidebarScrollbar"
            class="media-manager__sidebar-list flex-grow-1"
            :options="{ wheelPropagation: false, suppressScrollX: true }"
          >
            <VList
              density="compact"
              nav
            >
              <RouterLink
                v-for="item in categories"
                :key="item.slug"
                v-slot="{ isExactActive, href, navigate }"
                :to="categoryRoute(item.slug)"
                custom
              >
                <VListItem
                  :href="href"
                  :prepend-icon="item.icon"
                  :aria-current="isExactActive ? 'page' : undefined"
                  class="media-folder-item"
                  :class="{ 'media-folder-item--active text-primary': isExactActive }"
                  @click="navigate"
                >
                  <VListItemTitle class="text-body-1">
                    {{ item.name }}
                  </VListItemTitle>
                </VListItem>
              </RouterLink>
            </VList>
          </PerfectScrollbar>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        md="9"
        :lg="selectedFile ? 7 : 10"
        class="media-manager__column h-100"
      >
        <VCard
          border
          class="pa-4 h-100 d-flex flex-column media-manager__panel rounded-0"
        >
          <div class="d-flex justify-space-between align-center flex-wrap flex-md-nowrap gap-3 media-manager__summary">
            <div>
              <span class="text-subtitle-1 font-weight-bold">{{ selectedCategory }}</span>
              <span class="text-caption text-medium-emphasis text-no-wrap ms-2">
                Hiển thị {{ displayedRange }}
              </span>
            </div>
          </div>

          <VRow
            dense
            class="media-manager__filters mb-2 flex-shrink-0"
            align="center"
          >
            <VCol
              cols="12"
              sm="5"
            >
              <VTextField
                v-model="search"
                variant="outlined"
                placeholder="Tìm kiếm tệp..."
                prepend-inner-icon="tabler-search"
                hide-details
                clearable
                @update:model-value="page = 1"
              />
            </VCol>
            <VCol
              cols="6"
              sm="3"
            >
              <VSelect
                v-model="folderFilter"
                :items="folderFilterOptions.map(option => option.label)"
                variant="outlined"
                hide-details
                @update:model-value="page = 1"
              />
            </VCol>
            <VCol
              cols="6"
              sm="2"
            >
              <VSelect
                v-model="sortBy"
                :items="Object.values(sortByValue)"
                variant="outlined"
                hide-details
              />
            </VCol>
            <VCol
              cols="6"
              sm="2"
            >
              <VSelect
                v-model="scanStatus"
                :items="scanStatusOptions"
                item-title="title"
                item-value="value"
                variant="outlined"
                hide-details
              />
            </VCol>
            <VCol
              cols="6"
              sm="2"
            >
              <VSelect
                v-model="conversionStatus"
                :items="conversionStatusOptions"
                item-title="title"
                item-value="value"
                variant="outlined"
                hide-details
              />
            </VCol>
            <VCol
              cols="12"
              sm="1"
              class="text-end"
            >
              <VBtnToggle
                v-model="viewMode"
                mandatory
                density="compact"
                color="primary"
              >
                <VBtn
                  icon="tabler-layout-grid"
                  value="grid"
                  size="small"
                  aria-label="Xem dạng lưới"
                />
                <VBtn
                  icon="tabler-list"
                  value="list"
                  size="small"
                  aria-label="Xem dạng danh sách"
                />
              </VBtnToggle>
            </VCol>
          </VRow>

          <PerfectScrollbar
            ref="filesScrollbar"
            class="media-manager__files flex-grow-1"
            :options="{ wheelPropagation: false, suppressScrollX: true }"
          >
            <VProgressLinear
              v-if="loading"
              indeterminate
              class="mt-2"
            />
            <VAlert
              v-else-if="error"
              type="error"
              variant="tonal"
              class="mt-2"
            >
              {{ error }}
            </VAlert>
            <VAlert
              v-else-if="isTrashView"
              type="info"
              variant="tonal"
              class="mt-2"
            >
              API Media Asset hiện chưa hỗ trợ danh sách tệp đã xóa.
            </VAlert>
            <VAlert
              v-else-if="!pagedFiles.length"
              type="info"
              variant="tonal"
              class="mt-2"
            >
              Không có tệp phù hợp.
            </VAlert>
            <VRow
              v-if="!loading && !error && !isTrashView && pagedFiles.length && viewMode === 'grid'"
              class="mt-2"
            >
              <VCol
                v-for="file in pagedFiles"
                :key="file.id"
                cols="12"
                sm="6"
                md="4"
                lg="3"
              >
                <VCard
                  rounded="lg"
                  variant="outlined"
                  :class="{ 'border-primary': selectedFile?.id === file.id }"
                  class="file-card media-asset-tile position-relative"
                  :aria-label="file.name"
                  @click="selectFile(file)"
                >
                  <div class="d-flex justify-space-between position-absolute w-100 px-2 pt-2 file-card__actions">
                    <VCheckboxBtn
                      :model-value="checkedIds.includes(file.id)"
                      color="primary"
                      density="compact"
                      @click.stop="toggleFileSelection(file)"
                    />
                    <VBtn
                      icon="tabler-dots-vertical"
                      size="x-small"
                      variant="text"
                      aria-label="Thông tin file"
                      @click.stop="selectFile(file)"
                    />
                  </div>

                  <div class="media-asset-tile__preview">
                    <VImg
                      v-if="file.thumbnail"
                      :src="file.thumbnail"
                      contain
                      class="media-asset-tile__image"
                      alt=""
                    />
                    <VIcon
                      v-else
                      icon="tabler-file"
                      size="48"
                      color="primary"
                    />
                  </div>
                </VCard>
              </VCol>
            </VRow>

            <VList
              v-else-if="!loading && !error && !isTrashView && pagedFiles.length"
              class="mt-2"
              lines="two"
            >
              <VListItem
                v-for="file in pagedFiles"
                :key="file.id"
                :title="file.name"
                :subtitle="`${file.ext} • ${file.size}`"
                :active="selectedFile?.id === file.id"
                @click="selectFile(file)"
              >
                <template #prepend>
                  <VAvatar
                    rounded
                    color="primary"
                    variant="tonal"
                  >
                    <VImg
                      v-if="file.thumbnail"
                      :src="file.thumbnail"
                      :cover="file.thumbnailMode === 'cover'"
                      alt=""
                    />
                    <VIcon
                      v-else
                      icon="mdi-file-document-outline"
                    />
                  </VAvatar>
                </template>
                <template #append>
                  <VCheckboxBtn
                    :model-value="checkedIds.includes(file.id)"
                    color="primary"
                    @click.stop="toggleFileSelection(file)"
                  />
                </template>
              </VListItem>
            </VList>
          </PerfectScrollbar>

          <div class="d-flex justify-space-between align-center mt-6 flex-wrap gap-3">
            <div class="d-flex align-center text-caption text-medium-emphasis">
              Hiển thị
              <VSelect
                :model-value="itemsPerPage"
                :items="[12, 24, 48]"
                density="compact"
                variant="plain"
                class="mx-2 media-manager__items-per-page"
                hide-details
                @update:model-value="updateItemsPerPage"
              />
              tệp mỗi trang
            </div>
            <VPagination
              v-model="page"
              :length="pageCount"
              total-visible="5"
              density="compact"
              rounded="circle"
            />
          </div>
        </VCard>
      </VCol>

      <VCol
        v-if="selectedFile"
        cols="12"
        lg="3"
        class="media-manager__column"
      >
        <VCard
          rounded="lg"
          border
          class="pa-5 h-100 d-flex flex-column media-manager__panel media-details-panel rounded-s-0"
        >
          <div class="d-flex justify-space-between align-center mb-3 flex-shrink-0">
            <VCardTitle class="px-0 py-0 text-subtitle-2">
              Preview
            </VCardTitle>
            <div class="d-flex align-center">
              <VBtn
                icon="tabler-edit"
                size="x-small"
                variant="text"
                aria-label="Sửa metadata"
                :disabled="busy"
                @click="detailsVisible = true"
              />
              <VBtn
                v-if="selectedFile.file?.scan_status === 'error' || selectedFile.file?.conversion_status === 'failed'"
                icon="tabler-refresh"
                size="x-small"
                variant="text"
                aria-label="Xử lý lại tệp"
                :disabled="busy"
                @click="retry(selectedFile.id)"
              />
              <VBtn
                icon="tabler-x"
                size="x-small"
                variant="text"
                aria-label="Đóng thông tin tệp"
                @click="closeDetails"
              />
            </div>
          </div>

          <PerfectScrollbar
            class="media-details-scroll"
            :options="{ wheelPropagation: false, suppressScrollX: true }"
          >
            <VImg
              v-if="selectedPreviewImageUrl"
              :src="selectedPreviewImageUrl"
              height="160"
              contain
              rounded="lg"
              class="border mb-3 bg-grey-lighten-3"
              alt=""
            />
            <VSheet
              v-else
              height="160"
              rounded="lg"
              border
              class="d-flex align-center justify-center mb-3 bg-grey-lighten-3"
            >
              <VIcon
                :icon="selectedFile.type === 'video' ? 'tabler-video' : selectedFile.type === 'archive' ? 'tabler-file-zip' : 'tabler-file-text'"
                size="52"
                color="primary"
              />
            </VSheet>

            <div class="media-preview-heading mb-3">
              <div class="media-preview-title text-subtitle-1 font-weight-bold">
                {{ selectedFile.name }}
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ selectedFile.ext }} · {{ selectedFile.size }}
              </div>
            </div>

            <AppTextField
              label="Alt Text"
              :model-value="selectedFile.alt_text || '—'"
              readonly
              class="mb-3"
            />
            <AppTextField
              label="File URL"
              :model-value="selectedPreviewFileUrl"
              readonly
              class="mb-3"
            >
              <template #append-inner>
                <VBtn
                  icon="tabler-copy"
                  size="x-small"
                  variant="text"
                  color="primary"
                  aria-label="Copy file URL"
                  :disabled="selectedPreviewFileUrl === '—'"
                  @click="copySelectedFileUrl"
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
                    {{ selectedFile.type }}
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
                    {{ selectedFile.resolution }}
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
                    {{ selectedFile.size }}
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
                    {{ formatUploadDate(selectedFile.created_at) }}
                  </span>
                </template>
              </VListItem>
            </VList>
          </PerfectScrollbar>
          <div class="d-flex gap-2 mt-4 flex-shrink-0">
            <VBtn
              color="primary"
              class="flex-grow-1 text-none"
              prepend-icon="tabler-download"
              density="comfortable"
              :disabled="busy"
              @click="download(selected)"
            >
              Tải xuống
            </VBtn>
            <VBtn
              color="error"
              variant="tonal"
              class="flex-grow-1 text-none"
              prepend-icon="tabler-trash"
              density="comfortable"
              :disabled="busy"
              @click="handleDelete"
            >
              Xóa
            </VBtn>
          </div>
        </VCard>
      </VCol>
    </VRow>
  </div>

  <MediaAssetUploadDialog
    v-model="uploadVisible"
    :loading="busy"
    :progress="progress"
    :error="error"
    @submit="handleUpload"
  />
  <MediaAssetDetails
    v-model="detailsVisible"
    :asset="selected"
    :loading="busy"
    @update="handleUpdate"
  />
  <VSnackbar
    v-model="folderNoticeVisible"
    color="info"
    :timeout="3000"
  >
    API Media Asset hiện chưa hỗ trợ tạo thư mục tùy chỉnh.
  </VSnackbar>
</template>

<style lang="scss" scoped>
@use "@styles/variables/vuetify";
@use "@core-scss/base/mixins";

.media-manager {
  box-sizing: border-box;
  block-size: 100%;
  border-radius: vuetify.$card-border-radius;
}

.media-manager__columns {
  border-radius: vuetify.$card-border-radius;

  @include mixins.elevation(vuetify.$card-elevation);
}

@media (min-width: 960px) {
  .media-manager {
    overflow: hidden !important;
  }

  .media-manager__summary {
    block-size: 40px;
    flex: 0 0 40px;
  }
}

.media-manager__columns {
  min-block-size: 0;
}

.media-manager__files {
  min-block-size: 0;
}

.media-manager__sidebar-list {
  min-block-size: 0;
}

.media-manager {
  scrollbar-width: thin;
  scrollbar-color: rgba(var(--v-theme-on-surface), 0.28) transparent;
}

.media-manager::-webkit-scrollbar {
  inline-size: 4px;
  block-size: 4px;
}

.media-manager::-webkit-scrollbar-track {
  background: transparent;
}

.media-manager::-webkit-scrollbar-thumb {
  border-radius: 999px;
  background-color: rgba(var(--v-theme-on-surface), 0.28);
}

.media-manager__filters {
  position: static;
  flex: 0 0 auto;
}

.media-folder-item {
  position: relative;
  margin-block-end: 4px;
}

.media-folder-item--active::after {
  position: absolute;
  background: currentcolor;
  block-size: 100%;
  content: "";
  inline-size: 3px;
  inset-block-start: 0;
  inset-inline-start: 0;
}

.media-folder-item--active :deep(.v-list-item-title),
.media-folder-item--active :deep(.v-list-item__prepend > .v-icon) {
  color: rgb(var(--v-theme-primary)) !important;
  opacity: 1;
}

.media-manager__column {
  display: flex;
  flex-direction: column;
}

@media (max-width: 959px) {
  .media-manager {
    block-size: auto;
    min-block-size: 100%;
  }

  .media-manager__columns {
    flex: 0 0 auto !important;
    flex-grow: 0 !important;
  }

  .media-manager__files {
    flex: 0 0 auto;
    flex-grow: 0 !important;
    block-size: auto;
    min-block-size: auto;
  }

  .media-manager__sidebar-list {
    flex: 0 0 auto;
    flex-grow: 0 !important;
    block-size: auto;
    min-block-size: auto;
  }

  .media-details-scroll {
    flex: 0 0 auto;
    block-size: auto;
    min-block-size: auto;
  }

  .media-manager__panel {
    border-radius: 0 !important;
  }

  .media-manager__column + .media-manager__column .media-manager__panel {
    border-block-start-width: 0 !important;
  }
}

@media (min-width: 960px) {
  .media-manager__column + .media-manager__column .media-manager__panel {
    border-inline-start-width: 0 !important;
  }
}

.media-manager__storage {
  min-inline-size: 220px;
}

.media-manager__items-per-page {
  max-inline-size: 60px;

  :deep(.v-field__input) {
    min-block-size: 32px;
    padding-block: 0 !important;
  }

  :deep(.v-field__append-inner) {
    align-items: center !important;
    padding-block: 0 !important;
  }
}

.media-asset-tile {
  overflow: hidden;
  background: rgb(var(--v-theme-surface));
}

.media-asset-tile__preview {
  position: relative;
  display: flex;
  inline-size: 100%;
  aspect-ratio: 1 / 1;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  background: rgb(var(--v-theme-grey-100));
}

.media-asset-tile__preview :deep(.media-asset-tile__image) {
  inline-size: 100%;
  block-size: 100%;
}

.media-details-panel {
  min-block-size: 0;
  overflow: hidden;
  background: rgb(var(--v-theme-surface));
}

.media-details-scroll {
  flex: 1 1 auto;
  min-block-size: 0;
}

.media-preview-heading {
  min-inline-size: 0;
}

.media-preview-title {
  display: block;
  max-block-size: 3em;
  overflow: hidden;
  overflow-wrap: anywhere;
  line-height: 1.5;
  text-overflow: clip;
  white-space: normal;
  word-break: break-word;
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
  text-align: end;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.file-card {
  cursor: pointer;
  transition: border-color 0.2s, box-shadow 0.2s;
}

.file-card:hover {
  border-color: rgba(115, 103, 240, 0.6);
  box-shadow: 0 2px 8px rgba(115, 103, 240, 0.12);
}

.file-card__actions {
  z-index: 2;
}

.border-primary {
  border-color: #7367f0 !important;
}
</style>
