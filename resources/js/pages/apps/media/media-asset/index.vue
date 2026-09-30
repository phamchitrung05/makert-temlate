<!--
  =====================================================================
  CHỨC NĂNG FILE: Trang Media Asset giao diện quản lý Media Library
  =====================================================================

  Khôi phục bố cục ba cột nguyên bản và dùng dữ liệu thật từ Media Asset API.
  CÁC HÀM/COMPUTED TRONG FILE:
  - displayFile(): map API sang card; selectFile(): tải detail.
  - handleUpload/handleUpdate/handleDelete(): mutation API.
  - toggleFileSelection(): checkbox; resetDemoView(): refresh API.
  - fileTags()/formatUploadDate(): định dạng metadata cho panel cũ.
  - syncStateFromRoute/syncRouteQuery(): đồng bộ filter URL.
  - updateItemsPerPage/updateMediaScrollbars(): pagination và scrollbar.
  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : thao tác filter, category, view mode, pagination và chọn file.
  - OUTPUT: giao diện Media Asset tại /apps/media/media-asset.
  =====================================================================
-->
<script setup>
import {
  computed,
  nextTick,
  onMounted,
  shallowRef,
  useTemplateRef,
  watch,
} from 'vue'
import { useMediaAssetManager } from '@/composables/useMediaAssetManager'
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

const routeQueryValue = key => {
  const value = route.query[key]

  return Array.isArray(value) ? value[0] : value
}

const parsePositiveInteger = (value, fallback) => {
  const parsed = Number.parseInt(value, 10)

  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback
}

const search = shallowRef(routeQueryValue('q') ?? '')
const folderFilter = shallowRef(folderFilterByValue[routeQueryValue('type')] ?? folderFilterOptions[0].label)
const sortBy = shallowRef(sortByValue[routeQueryValue('sort')] ?? sortByValue.newest)
const viewMode = shallowRef(['grid', 'list'].includes(routeQueryValue('view')) ? routeQueryValue('view') : 'grid')
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
  page: page.value,
  'per_page': itemsPerPage.value,
}))

const manager = useMediaAssetManager(apiQuery)
const { items, total, selected, loading, busy, progress, error } = manager

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

const filteredFiles = computed(() => {
  if (isTrashView.value)
    return []

  const files = items.value.map(displayFile)

  return sortBy.value === 'Kích thước'
    ? [...files].sort((first, second) => Number(second.file?.size || 0) - Number(first.file?.size || 0))
    : files
})

const checkedIds = shallowRef([])
const closeDetails = () => manager.select(null)

/**
 * Tạo danh sách chip metadata thật theo hình thức khu vực "Thẻ" của giao diện cũ.
 *
 * Input: selectedFile lấy từ API.
 * Output: mảng nhãn usage; nếu chưa được sử dụng thì hiển thị visibility và kind.
 */
const fileTags = computed(() => {
  const usages = selectedFile.value?.usages?.map(usage => usage.field).filter(Boolean) ?? []

  if (usages.length)
    return usages

  return [selectedFile.value?.visibility, selectedFile.value?.kind].filter(Boolean)
})

/**
 * Định dạng ngày tải lên theo locale tiếng Việt cho panel thông tin.
 *
 * Input: chuỗi ISO hoặc giá trị ngày hợp lệ.
 * Output: ngày tiếng Việt; trả dấu gạch khi API không có ngày.
 */
const formatUploadDate = value => value
  ? new Intl.DateTimeFormat('vi-VN', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(value))
  : '—'

const handleUpload = async payload => {
  if (await manager.upload({ ...payload, file: Array.isArray(payload.file) ? payload.file[0] : payload.file }))
    uploadVisible.value = false
}

const handleUpdate = async payload => {
  if (await manager.update(payload.id, payload.data))
    detailsVisible.value = false
}

const handleDelete = async () => {
  if (selected.value && window.confirm(`Xóa "${selected.value.title}"?`))
    await manager.remove(selected.value.id)
}

const routeQueryKeys = ['q', 'type', 'sort', 'page', 'perPage', 'view']

const normalizedQueryValue = value => {
  if (Array.isArray(value))
    return value.join(',')

  return value == null ? undefined : String(value)
}

const queriesEqual = (firstQuery, secondQuery) => {
  const keys = new Set([...Object.keys(firstQuery), ...Object.keys(secondQuery)])

  return [...keys].every(key => normalizedQueryValue(firstQuery[key]) === normalizedQueryValue(secondQuery[key]))
}

const syncStateFromRoute = () => {
  const nextSearch = routeQueryValue('q') ?? ''
  const nextFolderFilter = folderFilterByValue[routeQueryValue('type')] ?? folderFilterOptions[0].label
  const nextSortBy = sortByValue[routeQueryValue('sort')] ?? sortByValue.newest
  const nextViewMode = ['grid', 'list'].includes(routeQueryValue('view')) ? routeQueryValue('view') : 'grid'
  const nextPage = parsePositiveInteger(routeQueryValue('page'), 1)
  const nextItemsPerPageValue = parsePositiveInteger(routeQueryValue('perPage'), 12)

  search.value = nextSearch
  folderFilter.value = nextFolderFilter
  sortBy.value = nextSortBy
  viewMode.value = nextViewMode
  page.value = nextPage
  itemsPerPage.value = [12, 24, 48].includes(nextItemsPerPageValue) ? nextItemsPerPageValue : 12
}

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

  if (queriesEqual(query, route.query))
    return

  void router.replace({
    name: route.name,
    params: route.params,
    query,
  })
}

watch(
  () => [route.query.q, route.query.type, route.query.sort, route.query.page, route.query.perPage, route.query.view],
  () => {
    syncStateFromRoute()
    syncRouteQuery()
  },
  { immediate: true },
)

watch(
  [search, folderFilter, sortBy, viewMode, page, itemsPerPage],
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
    void manager.select(files[0].id, files[0])
}, { immediate: true })

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

const displayedRange = computed(() => {
  if (!visibleTotal.value) return '0 - 0 của 0 tệp'
  const start = (page.value - 1) * itemsPerPage.value + 1

  return `${start} - ${Math.min(start + items.value.length - 1, visibleTotal.value)} của ${visibleTotal.value} tệp`
})

/** Input: asset card. Output: chi tiết tải từ API. */
const selectFile = file => manager.select(file.id, file)


/** Input: file. Output: chọn checkbox local, không tự attach usage. */
const toggleFileSelection = file => {
  checkedIds.value = checkedIds.value.includes(file.id)
    ? checkedIds.value.filter(id => id !== file.id)
    : [...checkedIds.value, file.id]
}


/** Input: không có. Output: refresh dữ liệu mà giữ filter hiện tại. */
const resetDemoView = () => manager.load()

/** Input: không có. Output: mở dialog upload thật. */
const showUploadNotice = () => { uploadVisible.value = true }

/** Input: không có. Output: báo rõ API hiện chưa có nghiệp vụ tạo thư mục. */
const showFolderNotice = () => { folderNoticeVisible.value = true }

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
          elevation="0"
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
          elevation="0"
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
              cols="12"
              sm="2"
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
                  class="file-card position-relative"
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
                      icon="mdi-dots-vertical"
                      size="x-small"
                      variant="text"
                      aria-label="Thông tin file"
                      @click.stop="selectFile(file)"
                    />
                  </div>

                  <VImg
                    v-if="file.thumbnail"
                    :src="file.thumbnail"
                    height="120"
                    :cover="file.thumbnailMode === 'cover'"
                    class="bg-grey-lighten-2"
                    alt=""
                  />
                  <div
                    v-else
                    class="d-flex align-center justify-center bg-grey-lighten-3"
                    style="height: 120px;"
                  >
                    <VIcon
                      size="40"
                      :color="file.type === 'document' ? 'red' : 'blue'"
                    >
                      {{ file.type === 'document' ? 'mdi-file-pdf-box' : 'mdi-file-document-outline' }}
                    </VIcon>
                  </div>

                  <VCardText class="pa-2">
                    <div class="text-truncate text-caption font-weight-medium">
                      {{ file.name }}
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      {{ file.ext }} • {{ file.size }}
                    </div>
                  </VCardText>
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
          elevation="0"
          border
          class="pa-4 h-100 media-manager__panel rounded-s-0"
        >
          <div class="d-flex justify-space-between align-center mb-3">
            <span class="font-weight-bold text-subtitle-1">Thông tin tệp</span>
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
                @click="manager.retry(selectedFile.id)"
              />
              <VBtn
                icon="mdi-close"
                size="x-small"
                variant="text"
                aria-label="Đóng thông tin tệp"
                @click="closeDetails"
              />
            </div>
          </div>

          <VImg
            v-if="selectedFile.thumbnail"
            :src="selectedFile.thumbnail"
            height="180"
            rounded="lg"
            :cover="selectedFile.thumbnailMode === 'cover'"
            class="mb-3"
            alt=""
          />

          <div class="text-subtitle-2 font-weight-bold text-truncate">
            {{ selectedFile.name }}
          </div>
          <div class="text-caption text-medium-emphasis mb-4">
            {{ selectedFile.ext }} • {{ selectedFile.size }}
          </div>

          <div class="text-caption mb-3">
            <div class="d-flex justify-space-between py-1 border-b">
              <span class="text-medium-emphasis">Loại tệp:</span>
              <span>{{ selectedFile.typeLabel }}</span>
            </div>
            <div class="d-flex justify-space-between py-1 border-b">
              <span class="text-medium-emphasis">Kích thước:</span>
              <span>{{ selectedFile.resolution }}</span>
            </div>
            <div class="d-flex justify-space-between py-1 border-b">
              <span class="text-medium-emphasis">Dung lượng:</span>
              <span>{{ selectedFile.size }}</span>
            </div>
            <div class="d-flex justify-space-between py-1 border-b">
              <span class="text-medium-emphasis">Ngày tải lên:</span>
              <span>{{ formatUploadDate(selectedFile.created_at) }}</span>
            </div>
            <div class="d-flex justify-space-between py-1">
              <span class="text-medium-emphasis">Thư mục:</span>
              <span>{{ selectedFile.typeLabel?.replace(/ \(.*/, '') }}</span>
            </div>
          </div>

          <div class="text-caption font-weight-bold mb-2">
            Thẻ
          </div>
          <div class="d-flex flex-wrap gap-1 mb-4">
            <VChip
              v-for="tag in fileTags"
              :key="tag"
              size="x-small"
              color="primary"
              variant="tonal"
              class="me-1"
            >
              {{ tag }}
            </VChip>
          </div>
          <div class="d-flex gap-2">
            <VBtn
              color="primary"
              class="flex-grow-1 text-none"
              prepend-icon="tabler-download"
              density="comfortable"
              :disabled="busy"
              @click="manager.download(selected)"
            >
              Tải xuống
            </VBtn>
            <VBtn
              color="error"
              variant="tonal"
              class="flex-grow-1 text-none ms-2"
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
