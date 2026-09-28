<!--
  =====================================================================
  CHỨC NĂNG FILE: Trang demo giao diện quản lý Media Library
  =====================================================================

  Trang demo sử dụng layout quản lý Media được chuẩn bị trong block view-moi:
  thư mục, bộ lọc, grid/list file và panel metadata. Dữ liệu chỉ là mẫu local
  để duyệt giao diện; không upload, xoá hoặc thay đổi MediaAsset thật.

  CÁC HÀM/COMPUTED TRONG FILE:
  - filteredFiles()/pagedFiles(): lọc, sắp xếp và phân trang file mẫu
  - selectCategory()/selectFile(): cập nhật ngữ cảnh thư mục và file đang xem
  - toggleFileSelection(): mô phỏng chọn nhiều file trong grid
  - resetDemoView(): đưa filter và layout về trạng thái ban đầu
  - showUploadNotice(): hiển thị feedback cho nút upload demo

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : thao tác filter, category, view mode, pagination và chọn file.
  - OUTPUT: giao diện demo Media Library tại /apps/media/demo.
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

definePage({
  meta: {
    navActiveLink: 'apps-media-demo',
    layoutWrapperClasses: 'layout-content-height-fixed',
  },
})

const selectedCategory = shallowRef('Tất cả tệp')
const search = shallowRef('')
const folderFilter = shallowRef('Tất cả thư mục')
const sortBy = shallowRef('Mới nhất')
const viewMode = shallowRef('grid')
const page = shallowRef(1)
const itemsPerPage = shallowRef(12)
const selectedFile = shallowRef(null)
const isUploadNoticeVisible = shallowRef(false)
const filesScrollbar = useTemplateRef('filesScrollbar')
const sidebarScrollbar = useTemplateRef('sidebarScrollbar')

const categories = [
  { name: 'Tất cả tệp', count: 248, icon: 'tabler-folder' },
  { name: 'Hình ảnh', count: 120, icon: 'tabler-photo' },
  { name: 'Video', count: 36, icon: 'tabler-video' },
  { name: 'Tài liệu', count: 68, icon: 'tabler-file-text' },
  { name: 'Thùng rác', count: 12, icon: 'tabler-trash' },
]

const demoFilePresets = [
  {
    ext: 'JPG',
    type: 'image',
    typeLabel: 'JPG (Hình ảnh)',
    resolution: '1600 x 1200 px',
    thumbnail: 'https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?w=600',
  },
  {
    ext: 'MP4',
    type: 'video',
    typeLabel: 'MP4 (Video)',
    resolution: '1920 x 1080 px',
  },
  {
    ext: 'PDF',
    type: 'document',
    typeLabel: 'PDF (Tài liệu)',
    resolution: 'A4 document',
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
      selected: false,
    }
  }),
])

selectedFile.value = files[0]

const folderTypeMap = {
  'Hình ảnh': 'image',
  Video: 'video',
  'Tài liệu': 'document',
}

const filteredFiles = computed(() => {
  const query = search.value.trim().toLowerCase()
  const categoryType = folderTypeMap[selectedCategory.value]
  const folderType = folderTypeMap[folderFilter.value]

  const nextFiles = files.filter(file => {
    const matchesSearch = !query || file.name.toLowerCase().includes(query)
    const matchesCategory = !categoryType || file.type === categoryType
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

const pageCount = computed(() => Math.max(1, Math.ceil(filteredFiles.value.length / itemsPerPage.value)))

const pagedFiles = computed(() => {
  const start = (page.value - 1) * itemsPerPage.value

  return filteredFiles.value.slice(start, start + itemsPerPage.value)
})

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
 * Chọn category từ sidebar và đưa pagination về trang đầu.
 *
 * Input: category name từ danh sách categories.
 * Output: cập nhật bộ lọc category và danh sách file hiển thị.
 */
const selectCategory = category => {
  selectedCategory.value = category
  page.value = 1
}

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
const resetDemoView = () => {
  selectedCategory.value = 'Tất cả tệp'
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
          Media Demo
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
              <VListItem
                v-for="item in categories"
                :key="item.name"
                :prepend-icon="item.icon"
                :aria-current="selectedCategory === item.name ? 'page' : undefined"
                class="media-folder-item"
                :class="{ 'media-folder-item--active text-primary': selectedCategory === item.name }"
                @click="selectCategory(item.name)"
              >
                <VListItemTitle class="text-body-1">
                  {{ item.name }}
                </VListItemTitle>
                <template #append>
                  <span class="text-caption text-medium-emphasis">{{ item.count }}</span>
                </template>
              </VListItem>
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
              <span class="text-subtitle-1 font-weight-bold">Tất cả tệp</span>
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
                density="compact"
                variant="outlined"
                placeholder="Tìm kiếm tệp..."
                prepend-inner-icon="mdi-magnify"
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
                :items="['Tất cả thư mục', 'Hình ảnh', 'Video', 'Tài liệu']"
                density="compact"
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
                density="compact"
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
                  icon="mdi-view-grid-outline"
                  value="grid"
                  size="small"
                  aria-label="Xem dạng lưới"
                />
                <VBtn
                  icon="mdi-view-list-outline"
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
                    cover
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
                      cover
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
            cover
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
