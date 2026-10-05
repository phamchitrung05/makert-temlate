<!--
  =====================================================================
  CHỨC NĂNG FILE: Datatable server hiển thị profile và prompt văn phong đã lưu.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE:
  - defineModel(page/itemsPerPage): emit query phân trang cho component cha.
  - formatDate(value): trình bày ngày cập nhật hợp lệ theo tiếng Việt.
  - watcher items: đóng dòng mở rộng khi bộ dữ liệu của trang đổi.
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : items/totalItems/loading/emptyText từ AiPromptList.
  - OUTPUT: bảng và edit/toggle/remove để cha quản lý profile/version.
  - SIDE EFFECT: không gọi API hoặc sửa profile; nội dung prompt được escape.
  =====================================================================
-->
<script setup>
import { shallowRef, watch } from 'vue'

const props = defineProps({
  items: { type: Array, default: () => [] },
  totalItems: { type: Number, default: 0 },
  loading: { type: Boolean, default: false },
  emptyText: { type: String, default: 'Chưa có văn phong đã lưu.' },
  disabled: Boolean,
})

const emit = defineEmits(['edit', 'toggle', 'remove'])

const page = defineModel('page', { type: Number, default: 1 })
const itemsPerPage = defineModel('itemsPerPage', { type: Number, default: 15 })
const expanded = shallowRef([])

const headers = [
  { title: 'Tên văn phong', key: 'name', sortable: false },
  { title: 'Nguồn', key: 'origin', sortable: false },
  { title: 'Trạng thái', key: 'is_enabled', sortable: false },
  { title: 'Phiên bản', key: 'version', sortable: false },
  { title: 'Cập nhật', key: 'updated_at', sortable: false },
  { title: 'Prompt', key: 'data-table-expand', sortable: false },
  { title: 'Thao tác', key: 'actions', sortable: false },
]

const dateFormat = new Intl.DateTimeFormat('vi-VN', { dateStyle: 'short', timeStyle: 'short' })

/**
 * =====================================================================
 * CHỨC NĂNG: Hiển thị thời điểm cập nhật mà không tạo ngày giả cho giá trị rỗng.
 * Input: ISO timestamp từ resource. Output: ngày/giờ địa phương hoặc dấu —.
 * =====================================================================
 */
function formatDate(value) {
  if (!value) return '—'
  const date = new Date(value)

  return Number.isNaN(date.getTime()) ? '—' : dateFormat.format(date)
}

// =====================================================================
// Input: rows mới sau tải/phân trang. Output: đóng prompt của bộ rows trước.
// =====================================================================
watch(() => props.items, () => { expanded.value = [] })
</script>

<template>
  <VDataTableServer
    v-model:page="page"
    v-model:items-per-page="itemsPerPage"
    v-model:expanded="expanded"
    :headers="headers"
    :items="items"
    :items-length="totalItems"
    :loading="loading"
    :items-per-page-options="[15, 25, 50, 100]"
    :no-data-text="emptyText"
    loading-text="Đang tải mẫu văn phong…"
    items-per-page-text="Số dòng mỗi trang"
    item-value="id"
    show-expand
    class="ai-prompt-table"
  >
    <template #item.name="{ item }">
      <div class="ai-prompt-table__name py-3">
        <div class="font-weight-medium text-break">
          {{ item.name }}
        </div>
        <div
          v-if="item.description"
          class="text-body-2 text-medium-emphasis text-truncate"
        >
          {{ item.description }}
        </div>
      </div>
    </template>
    <template #item.origin="{ item }">
      <VChip
        :color="item.origin === 'reference' ? 'primary' : 'secondary'"
        variant="tonal"
        size="small"
      >
        {{ item.origin === 'reference' ? 'Phân tích AI' : 'Nhập thủ công' }}
      </VChip>
    </template>
    <template #item.is_enabled="{ item }">
      <VChip
        :color="item.is_enabled ? 'success' : 'secondary'"
        variant="tonal"
        size="small"
      >
        {{ item.is_enabled ? 'Đang bật' : 'Đã tắt' }}
      </VChip>
    </template>
    <template #item.version="{ item }">
      v{{ item.version }}
    </template>
    <template #item.updated_at="{ item }">
      <span class="text-no-wrap">{{ formatDate(item.updated_at) }}</span>
    </template>
    <template #item.actions="{ item }">
      <div class="d-flex text-no-wrap">
        <VBtn
          icon="tabler-edit"
          size="small"
          variant="text"
          :aria-label="`Sửa văn phong ${item.name}`"
          :disabled="props.disabled"
          @click="emit('edit', item)"
        />
        <VBtn
          :icon="item.is_enabled ? 'tabler-toggle-right' : 'tabler-toggle-left'"
          size="small"
          variant="text"
          :aria-label="`${item.is_enabled ? 'Tắt' : 'Bật'} văn phong ${item.name}`"
          :disabled="props.disabled"
          @click="emit('toggle', item)"
        />
        <VBtn
          icon="tabler-trash"
          color="error"
          size="small"
          variant="text"
          :aria-label="`Xóa văn phong ${item.name}`"
          :disabled="props.disabled"
          @click="emit('remove', item)"
        />
      </div>
    </template>
    <template #item.data-table-expand="{ item, internalItem, isExpanded, toggleExpand }">
      <VBtn
        :icon="isExpanded(internalItem) ? 'tabler-chevron-up' : 'tabler-file-text'"
        :aria-label="`${isExpanded(internalItem) ? 'Đóng' : 'Xem'} prompt ${item.name}`"
        :title="`${isExpanded(internalItem) ? 'Đóng' : 'Xem'} prompt`"
        :aria-expanded="isExpanded(internalItem)"
        variant="text"
        color="secondary"
        size="small"
        @click="toggleExpand(internalItem)"
      />
    </template>
    <template #expanded-row="{ columns, item }">
      <tr>
        <td :colspan="columns.length">
          <div class="pa-4">
            <div class="font-weight-medium mb-2">
              Prompt hướng dẫn · {{ item.name }}
            </div>
            <div
              v-if="item.description"
              class="text-body-2 text-medium-emphasis mb-3"
            >
              {{ item.description }}
            </div>
            <div
              class="ai-prompt-table__prompt text-body-2"
              tabindex="0"
              :aria-label="`Nội dung prompt ${item.name}`"
            >
              {{ item.style_instructions || 'Mẫu này chưa có hướng dẫn văn phong.' }}
            </div>
          </div>
        </td>
      </tr>
    </template>
  </VDataTableServer>
</template>

<style scoped>
.ai-prompt-table__name {
  min-inline-size: 180px;
  max-inline-size: 320px;
}

.ai-prompt-table__prompt {
  overflow: auto;
  max-block-size: 420px;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
</style>
