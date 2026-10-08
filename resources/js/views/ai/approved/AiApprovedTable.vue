<!--
  =====================================================================
  CHỨC NĂNG FILE: Datatable server của các bài AI đã được duyệt dài hạn.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: formatDate(), statusLabel().
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : rows/pagination/loading/error từ composable archive.
  - OUTPUT: bảng đọc và event view; không mutation dữ liệu.
  =====================================================================
-->
<script setup>
const props = defineProps({
  items: { type: Array, default: () => [] },
  totalItems: { type: Number, default: 0 },
  loading: { type: Boolean, default: false },
  emptyText: { type: String, default: 'Chưa có bài AI nào được duyệt.' },
})

const emit = defineEmits(['view'])
const page = defineModel('page', { type: Number, default: 1 })
const itemsPerPage = defineModel('itemsPerPage', { type: Number, default: 15 })

const headers = [
  { title: 'Bài viết AI', key: 'title', sortable: false },
  { title: 'Mô hình', key: 'model', sortable: false },
  { title: 'Văn phong', key: 'writing_profile', sortable: false },
  { title: 'Model đích', key: 'target', sortable: false },
  { title: 'Ngày duyệt', key: 'approved_at', sortable: false },
  { title: 'Thao tác', key: 'actions', sortable: false, align: 'end' },
]

const dateFormat = new Intl.DateTimeFormat('vi-VN', { dateStyle: 'short', timeStyle: 'short' })

/** Input: ISO timestamp. Output: ngày/giờ địa phương hoặc dấu gạch ngang. */
function formatDate(value) {
  if (!value) return '—'
  const date = new Date(value)

  return Number.isNaN(date.getTime()) ? '—' : dateFormat.format(date)
}
</script>

<template>
  <VCard>
    <VDataTableServer
      v-model:page="page"
      v-model:items-per-page="itemsPerPage"
      :headers="headers"
      :items="props.items"
      :items-length="props.totalItems"
      :loading="props.loading"
      :items-per-page-options="[15, 25, 50, 100]"
      :no-data-text="props.emptyText"
      loading-text="Đang tải kho bài AI…"
      items-per-page-text="Số dòng mỗi trang"
      item-value="id"
    >
      <template #item.title="{ item }">
        <div class="py-3">
          <div class="font-weight-medium text-break">
            {{ item.title }}
          </div>
          <VChip
            size="x-small"
            color="success"
            variant="tonal"
            class="mt-1"
          >
            Đã duyệt · lưu dài hạn
          </VChip>
        </div>
      </template>
      <template #item.target="{ item }">
        <VChip
          color="secondary"
          variant="tonal"
          size="small"
        >
          {{ item.target_type }} #{{ item.target_id || '—' }}
        </VChip>
        <div
          v-if="item.target?.label"
          class="text-caption text-medium-emphasis mt-1 text-truncate"
        >
          {{ item.target.label }}
        </div>
      </template>
      <template #item.model="{ item }">
        <div class="text-body-2">
          {{ item.model || '—' }}
        </div>
        <div class="text-caption text-medium-emphasis">
          {{ item.provider || 'Provider không xác định' }}
        </div>
      </template>
      <template #item.writing_profile="{ item }">
        {{ item.writing_profile || 'Mặc định' }}
      </template>
      <template #item.approved_at="{ item }">
        <span class="text-no-wrap">{{ formatDate(item.approved_at) }}</span>
      </template>
      <template #item.actions="{ item }">
        <VBtn
          icon="tabler-eye"
          size="small"
          variant="text"
          color="primary"
          :aria-label="`Xem bài AI ${item.title}`"
          @click="emit('view', item)"
        />
      </template>
    </VDataTableServer>
  </VCard>
</template>
