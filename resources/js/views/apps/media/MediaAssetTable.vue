<!--
  =====================================================================
  CHỨC NĂNG FILE: Render bảng file trong Media Library admin
  =====================================================================

  Component chỉ trình bày các asset đã được page tải qua Pinia store. Bảng dùng
  VDataTableServer để filter, sort và pagination được xử lý ở backend; các thao
  tác mutation được phát lên page cha.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - fileOf(): lấy metadata file an toàn từ asset
  - resolveKind()/resolveStatus(): tạo nhãn và màu trạng thái
  - formatBytes()/formatDate(): định dạng dữ liệu hiển thị

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : assets, pagination state và loading từ page Media Library
  - OUTPUT: bảng server-side và event select/retry/download/delete
  =====================================================================
-->
<script setup>
const props = defineProps({
  assets: {
    type: Array,
    default: () => [],
  },
  itemsPerPage: {
    type: Number,
    required: true,
  },
  page: {
    type: Number,
    required: true,
  },
  totalItems: {
    type: Number,
    default: 0,
  },
  loading: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits([
  'update:itemsPerPage',
  'update:page',
  'update:options',
  'select',
  'retry',
  'download',
  'delete',
])

const headers = [
  { title: 'File', key: 'title' },
  { title: 'Kind', key: 'kind' },
  { title: 'Visibility', key: 'visibility' },
  { title: 'Scan', key: 'scan_status', sortable: false },
  { title: 'Conversion', key: 'conversion_status', sortable: false },
  { title: 'Size', key: 'file.size', sortable: false },
  { title: 'Updated', key: 'updated_at' },
  { title: 'Actions', key: 'actions', sortable: false },
]

/**
 * Lấy metadata file từ asset mà không làm template phụ thuộc null-check lặp lại.
 *
 * Input: MediaAsset payload từ Laravel hoặc MSW.
 * Output: object file metadata hoặc object rỗng khi asset chưa có file.
 */
const fileOf = asset => asset?.file ?? {}

/**
 * Ánh xạ kind domain sang nhãn hiển thị trong bảng.
 *
 * Input: kind string từ MediaAssetResource.
 * Output: object `{ text, color }` dùng cho VChip.
 */
const resolveKind = kind => {
  const kinds = {
    image: { text: 'Image', color: 'primary' },
    document: { text: 'Document', color: 'info' },
    archive: { text: 'Archive', color: 'warning' },
    video: { text: 'Video', color: 'secondary' },
  }

  return kinds[kind] ?? { text: kind || 'Unknown', color: 'default' }
}

/**
 * Ánh xạ trạng thái xử lý file sang nhãn và màu Vuetify.
 *
 * Input: pending/clean/rejected/error hoặc pending/processing/ready/failed.
 * Output: object `{ text, color }` cho chip trạng thái.
 */
const resolveStatus = status => {
  const statuses = {
    pending: { text: 'Pending', color: 'warning' },
    processing: { text: 'Processing', color: 'warning' },
    clean: { text: 'Clean', color: 'success' },
    ready: { text: 'Ready', color: 'success' },
    rejected: { text: 'Rejected', color: 'error' },
    error: { text: 'Error', color: 'error' },
    failed: { text: 'Failed', color: 'error' },
  }

  return statuses[status] ?? { text: status || '—', color: 'default' }
}

/**
 * Định dạng byte thành đơn vị dễ đọc trên màn hình admin.
 *
 * Input: kích thước byte hoặc null.
 * Output: chuỗi KB/MB/GB hoặc dấu gạch ngang.
 */
const formatBytes = bytes => {
  if (!Number.isFinite(Number(bytes)) || Number(bytes) <= 0)
    return '—'

  const units = ['B', 'KB', 'MB', 'GB']
  const exponent = Math.min(Math.floor(Math.log(Number(bytes)) / Math.log(1024)), units.length - 1)

  return `${(Number(bytes) / 1024 ** exponent).toFixed(exponent === 0 ? 0 : 1)} ${units[exponent]}`
}

/**
 * Định dạng timestamp ISO theo locale của admin.
 *
 * Input: ISO date string hoặc null.
 * Output: ngày ngắn hoặc dấu gạch ngang.
 */
const formatDate = value => {
  if (!value)
    return '—'

  return new Intl.DateTimeFormat('vi-VN', { dateStyle: 'medium' }).format(new Date(value))
}

/**
 * Xác định asset có thể retry từ trạng thái scan/conversion hiện tại.
 *
 * Input: asset Media Library.
 * Output: true khi backend có thể nhận action retry.
 */
const canRetry = asset => ['error', 'failed'].includes(fileOf(asset).scan_status)
  || ['error', 'failed'].includes(fileOf(asset).conversion_status)
</script>

<template>
  <VDataTableServer
    :headers="headers"
    :items="props.assets"
    :items-length="props.totalItems"
    :items-per-page="props.itemsPerPage"
    :page="props.page"
    :loading="props.loading"
    item-value="id"
    class="text-no-wrap"
    @update:items-per-page="emit('update:itemsPerPage', $event)"
    @update:page="emit('update:page', $event)"
    @update:options="emit('update:options', $event)"
  >
    <template #item.title="{ item }">
      <div class="d-flex align-center gap-x-3 py-2">
        <VAvatar
          rounded
          size="38"
          color="primary"
          variant="tonal"
        >
          <VIcon :icon="item.kind === 'image' ? 'tabler-photo' : 'tabler-file'" />
        </VAvatar>
        <div class="d-flex flex-column min-width-0">
          <button
            type="button"
            class="text-body-1 font-weight-medium text-high-emphasis text-start text-truncate"
            @click="emit('select', item)"
          >
            {{ item.title }}
          </button>
          <span class="text-body-2 text-medium-emphasis text-truncate">
            {{ fileOf(item).original_name || fileOf(item).file_name || 'No file metadata' }}
          </span>
        </div>
      </div>
    </template>

    <template #item.kind="{ item }">
      <VChip
        :color="resolveKind(item.kind).color"
        label
        size="small"
        variant="tonal"
      >
        {{ resolveKind(item.kind).text }}
      </VChip>
    </template>

    <template #item.visibility="{ item }">
      <VChip
        :color="item.visibility === 'public' ? 'success' : 'secondary'"
        label
        size="small"
        variant="tonal"
      >
        {{ item.visibility === 'public' ? 'Public' : 'Private' }}
      </VChip>
    </template>

    <template #item.scan_status="{ item }">
      <VChip
        :color="resolveStatus(fileOf(item).scan_status).color"
        label
        size="small"
      >
        {{ resolveStatus(fileOf(item).scan_status).text }}
      </VChip>
    </template>

    <template #item.conversion_status="{ item }">
      <VChip
        :color="resolveStatus(fileOf(item).conversion_status).color"
        label
        size="small"
      >
        {{ resolveStatus(fileOf(item).conversion_status).text }}
      </VChip>
    </template>

    <template #item.file.size="{ item }">
      <span class="text-body-2">{{ formatBytes(fileOf(item).size) }}</span>
    </template>

    <template #item.updated_at="{ item }">
      <span class="text-body-2">{{ formatDate(item.updated_at) }}</span>
    </template>

    <template #item.actions="{ item }">
      <IconBtn>
        <VIcon icon="tabler-dots-vertical" />
        <VMenu activator="parent">
          <VList>
            <VListItem
              value="details"
              prepend-icon="tabler-eye"
              @click="emit('select', item)"
            >
              Details
            </VListItem>
            <VListItem
              value="download"
              prepend-icon="tabler-download"
              @click="emit('download', item)"
            >
              Download
            </VListItem>
            <VListItem
              v-if="canRetry(item)"
              value="retry"
              prepend-icon="tabler-refresh"
              @click="emit('retry', item)"
            >
              Retry processing
            </VListItem>
            <VListItem
              value="delete"
              prepend-icon="tabler-trash"
              @click="emit('delete', item)"
            >
              Delete
            </VListItem>
          </VList>
        </VMenu>
      </IconBtn>
    </template>

    <template #bottom>
      <TablePagination
        :page="props.page"
        :items-per-page="props.itemsPerPage"
        :total-items="props.totalItems"
        @update:page="emit('update:page', $event)"
      />
    </template>
  </VDataTableServer>
</template>
