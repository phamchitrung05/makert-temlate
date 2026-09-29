<!--
  =====================================================================
  CHỨC NĂNG FILE: Trang Media Asset giao diện quản lý Media Library
  =====================================================================

  Trang demo sử dụng layout quản lý Media được chuẩn bị trong block view-moi:
  thư mục, bộ lọc, grid/list file và panel metadata. Dữ liệu chỉ là mẫu local
  để duyệt giao diện; không upload, xoá hoặc thay đổi MediaAsset thật.

  CÁC HÀM/COMPUTED TRONG FILE:
  - filteredFiles()/pagedFiles(): lọc, sắp xếp và phân trang file mẫu
  - categoryRoute()/selectFile(): điều hướng thư mục và cập nhật file đang xem
  - toggleFileSelection(): mô phỏng chọn nhiều file trong grid
  - syncRouteQuery()/resetDemoView(): đồng bộ filter với URL và đưa layout về mặc định
  - showUploadNotice(): hiển thị feedback cho nút upload demo

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
  reactive,
  shallowRef,
  useTemplateRef,
  watch,
} from 'vue'
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
const selectedFile = shallowRef(null)
const isUploadNoticeVisible = shallowRef(false)
const filesScrollbar = useTemplateRef('filesScrollbar')
const sidebarScrollbar = useTemplateRef('sidebarScrollbar')

const categories = [
  { slug: 'all', name: 'Tất cả tệp', count: 248, icon: 'tabler-folder' },
  { slug: 'images', name: 'Hình ảnh', count: 120, icon: 'tabler-photo', type: 'image' },
  { slug: 'videos', name: 'Video', count: 36, icon: 'tabler-video', type: 'video' },
  { slug: 'documents', name: 'Tài liệu', count: 68, icon: 'tabler-file-text', type: 'document' },
  { slug: 'trash', name: 'Thùng rác', count: 12, icon: 'tabler-trash', isTrash: true },
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

const demoFilePresets = [
  {
    ext: 'JPG',
    type: 'image',
    typeLabel: 'JPG (Hình ảnh)',
    resolution: '1600 x 1200 px',
    thumbnail: 'https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?w=600',
    thumbnailMode: 'cover',
  },
  {
    ext: 'PNG',
    type: 'image',
    typeLabel: 'PNG (Hình ảnh)',
    resolution: '1600 x 1200 px',
    thumbnail: 'https://images.unsplash.com/photo-1559056199-641a0ac8b55e?w=600',
    thumbnailMode: 'cover',
  },
  {
    ext: 'MP4',
    type: 'video',
    typeLabel: 'MP4 (Video)',
    resolution: '1920 x 1080 px',
    thumbnail: fileTypeThumbnails.mp4,
    thumbnailMode: 'contain',
  },
  {
    ext: 'PDF',
    type: 'document',
    typeLabel: 'PDF (Tài liệu)',
    resolution: 'A4 document',
    thumbnail: fileTypeThumbnails.pdf,
    thumbnailMode: 'contain',
  },
  {
    ext: 'ZIP',
    type: 'document',
    typeLabel: 'ZIP (Tệp nén)',
    resolution: 'Compressed archive',
    thumbnail: fileTypeThumbnails.zip,
    thumbnailMode: 'contain',
  },
  {
    ext: 'SVG',
    type: 'document',
    typeLabel: 'SVG (Đồ họa vector)',
    resolution: 'Vector graphic',
    thumbnail: fileTypeThumbnails.svg,
    thumbnailMode: 'contain',
  },
  {
    ext: 'DOCX',
    type: 'document',
    typeLabel: 'DOCX (Tài liệu)',
    resolution: 'Office document',
    thumbnail: fileTypeThumbnails.docx,
    thumbnailMode: 'contain',
  },
  {
    ext: 'XLSX',
    type: 'document',
    typeLabel: 'XLSX (Bảng tính)',
    resolution: 'Office spreadsheet',
    thumbnail: fileTypeThumbnails.xlsx,
    thumbnailMode: 'contain',
  },
  {
    ext: 'PPTX',
    type: 'document',
    typeLabel: 'PPTX (Bản trình chiếu)',
    resolution: 'Office presentation',
    thumbnail: fileTypeThumbnails.pptx,
    thumbnailMode: 'contain',
  },
  {
    ext: 'MP3',
    type: 'document',
    typeLabel: 'MP3 (Âm thanh)',
    resolution: 'Audio file',
    thumbnail: fileTypeThumbnails.mp3,
    thumbnailMode: 'contain',
  },
  {
    ext: 'TXT',
    type: 'document',
    typeLabel: 'TXT (Văn bản)',
    resolution: 'Plain text file',
    thumbnail: fileTypeThumbnails.txt,
    thumbnailMode: 'contain',
  },
  {
    ext: 'CSV',
    type: 'document',
    typeLabel: 'CSV (Dữ liệu)',
    resolution: 'Comma-separated values',
    thumbnail: fileTypeThumbnails.csv,
    thumbnailMode: 'contain',
  },
]

const files = reactive([
  {
    id: 1,
    name: 'coffee-shop-interior.jpg',
    ext: 'JPG',
    size: '2.4 MB',
    type: 'image',
    typeLabel: 'JPG (Hình ảnh)',
    resolution: '1920 x 1280 px',
    thumbnail: 'https://images.unsplash.com/photo-1554118811-1e0d58224f24?w=600',
    thumbnailMode: 'cover',
    isDeleted: false,
    selected: true,
  },
  {
    id: 2,
    name: 'coffee-packaging.png',
    ext: 'PNG',
    size: '1.8 MB',
    type: 'image',
    typeLabel: 'PNG (Hình ảnh)',
    resolution: '1600 x 1200 px',
    thumbnail: 'https://images.unsplash.com/photo-1559056199-641a0ac8b55e?w=600',
    thumbnailMode: 'cover',
    isDeleted: false,
    selected: false,
  },
  {
    id: 3,
    name: 'breakfast-coffee.jpg',
    ext: 'JPG',
    size: '3.1 MB',
    type: 'image',
    typeLabel: 'JPG (Hình ảnh)',
    resolution: '2048 x 1365 px',
    thumbnail: 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=600',
    thumbnailMode: 'cover',
    isDeleted: false,
    selected: false,
  },
  {
    id: 4,
    name: 'brand-guideline.pdf',
    ext: 'PDF',
    size: '6.8 MB',
    type: 'document',
    typeLabel: 'PDF (Tài liệu)',
    resolution: 'A4 document',
    thumbnail: fileTypeThumbnails.pdf,
    thumbnailMode: 'contain',
    isDeleted: true,
    selected: false,
  },
  {
    id: 5,
    name: 'contract.docx',
    ext: 'DOCX',
    size: '1.4 MB',
    type: 'document',
    typeLabel: 'DOCX (Tài liệu)',
    resolution: 'Office document',
    thumbnail: fileTypeThumbnails.docx,
    thumbnailMode: 'contain',
    isDeleted: true,
    selected: false,
  },
  {
    id: 6,
    name: 'product-demo.mp4',
    ext: 'MP4',
    size: '12.6 MB',
    type: 'video',
    typeLabel: 'MP4 (Video)',
    resolution: '1920 x 1080 px',
    thumbnail: fileTypeThumbnails.mp4,
    thumbnailMode: 'contain',
    isDeleted: false,
    selected: false,
  },
  ...Array.from({ length: 50 }, (_, index) => {
    const preset = demoFilePresets[index % demoFilePresets.length]
    const sequence = String(index + 1).padStart(2, '0')

    return {
      id: index + 7,
      name: `demo-media-${sequence}.${preset.ext.toLowerCase()}`,
      size: `${(1.2 + (index % 9) * 0.7).toFixed(1)} MB`,
      ...preset,
      isDeleted: index % 5 === 0,
      selected: false,
    }
  }),
])

selectedFile.value = files[0]

const folderTypeMap = {
  ...folderFilterByLabel,
  'Tất cả thư mục': null,
}

const filteredFiles = computed(() => {
  const query = search.value.trim().toLowerCase()
  const categoryType = activeCategory.value.type
  const folderType = folderTypeMap[folderFilter.value]

  const nextFiles = files.filter(file => {
    const matchesSearch = !query || file.name.toLowerCase().includes(query)

    const matchesCategory = activeCategory.value.isTrash
      ? file.isDeleted
      : !file.isDeleted && (!categoryType || file.type === categoryType)

    const matchesFolder = !folderType || file.type === folderType

    return matchesSearch && matchesCategory && matchesFolder
  })

  return [...nextFiles].sort((first, second) => {
    if (sortBy.value === 'Tên A-Z')
      return first.name.localeCompare(second.name)

    if (sortBy.value === 'Kích thước')
      return Number.parseFloat(second.size) - Number.parseFloat(first.size)

    if (sortBy.value === 'Cũ nhất')
      return first.id - second.id

    return second.id - first.id
  })
})

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

  if (search.value.trim())
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

const pageCount = computed(() => Math.max(1, Math.ceil(filteredFiles.value.length / itemsPerPage.value)))

const pagedFiles = computed(() => {
  const start = (page.value - 1) * itemsPerPage.value

  return filteredFiles.value.slice(start, start + itemsPerPage.value)
})

watch(filteredFiles, () => {
  if (page.value > pageCount.value)
    page.value = pageCount.value

  if (!selectedFile.value || !filteredFiles.value.some(file => file.id === selectedFile.value.id))
    selectedFile.value = filteredFiles.value[0] ?? null
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
  if (!filteredFiles.value.length)
    return '0 - 0 của 0 tệp'

  const start = (page.value - 1) * itemsPerPage.value + 1
  const end = Math.min(page.value * itemsPerPage.value, filteredFiles.value.length)

  return `${start} - ${end} của ${filteredFiles.value.length} tệp mẫu`
})

/**
 * Chọn file để hiển thị panel thông tin bên phải.
 *
 * Input: file mẫu từ grid hoặc list.
 * Output: selectedFile trỏ tới file được chọn.
 */
const selectFile = file => {
  selectedFile.value = file
}

/**
 * Bật/tắt trạng thái checkbox của file mà không rời panel chi tiết.
 *
 * Input: file mẫu từ card.
 * Output: cập nhật thuộc tính selected trong dữ liệu local.
 */
const toggleFileSelection = file => {
  file.selected = !file.selected
  selectedFile.value = file
}

/**
 * Đưa filter, chế độ xem, pagination và file đang chọn về mặc định.
 *
 * Input: Không có.
 * Output: giao diện demo trở về trạng thái ban đầu giống lúc mở page.
 */
const resetDemoView = async () => {
  await router.replace({ name: 'apps-media-media-asset' })
  search.value = ''
  folderFilter.value = 'Tất cả thư mục'
  sortBy.value = 'Mới nhất'
  viewMode.value = 'grid'
  page.value = 1
  itemsPerPage.value = 12
  selectedFile.value = files[0]
}

/**
 * Hiển thị thông báo cho nút upload của giao diện demo.
 *
 * Input: Không có.
 * Output: mở snackbar; không gửi file tới backend.
 */
const showUploadNotice = () => {
  isUploadNoticeVisible.value = true
}

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
              aria-label="Thêm thư mục demo"
              @click="showUploadNotice"
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
                  <template #append>
                    <span class="text-caption text-medium-emphasis">{{ item.count }}</span>
                  </template>
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
                :items="['Mới nhất', 'Cũ nhất', 'Tên A-Z', 'Kích thước']"
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
            <VRow
              v-if="viewMode === 'grid'"
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
                      v-model="file.selected"
                      color="primary"
                      density="compact"
                      @click.stop="toggleFileSelection(file)"
                    />
                    <VBtn
                      icon="mdi-dots-vertical"
                      size="x-small"
                      variant="text"
                      aria-label="Tùy chọn file"
                      @click.stop
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
              v-else
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
                    v-model="file.selected"
                    color="primary"
                    @click.stop
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
            <VBtn
              icon="mdi-close"
              size="x-small"
              variant="text"
              aria-label="Đóng thông tin tệp"
              @click="selectedFile = null"
            />
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
              <span>14 Thg 4, 2024</span>
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
              size="x-small"
              color="primary"
              variant="tonal"
              class="me-1"
            >
              coffee
            </VChip>
            <VChip
              size="x-small"
              color="primary"
              variant="tonal"
              class="me-1"
            >
              nội thất
            </VChip>
            <VChip
              size="x-small"
              color="primary"
              variant="tonal"
            >
              cửa hàng
            </VChip>
          </div>

          <div class="d-flex gap-2">
            <VBtn
              color="primary"
              class="flex-grow-1 text-none"
              prepend-icon="tabler-download"
              density="comfortable"
              @click="showUploadNotice"
            >
              Tải xuống
            </VBtn>
            <VBtn
              color="error"
              variant="tonal"
              class="flex-grow-1 text-none ms-2"
              prepend-icon="tabler-trash"
              density="comfortable"
              @click="showUploadNotice"
            >
              Xóa
            </VBtn>
          </div>
        </VCard>
      </VCol>
    </VRow>
  </div>

  <VSnackbar
    v-model="isUploadNoticeVisible"
    color="info"
    :timeout="3000"
  >
    Đây là demo giao diện; thao tác này chưa kết nối backend.
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
