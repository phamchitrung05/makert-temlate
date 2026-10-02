<!--
  =====================================================================
  CHỨC NĂNG FILE: Danh sách bản nháp AI với bộ lọc và phân trang cục bộ.
  =====================================================================
  Dùng AppTextField, AppSelect, VDataTable và TablePagination.

  CÁC HÀM/METHOD TRONG FILE:
  - filteredItems/statusTabs: lọc bài và đếm trạng thái từ dữ liệu hiện có.
  - watcher search/status/itemsPerPage: đưa phân trang về trang đầu.

  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : items, loading và lỗi đọc danh sách từ page.
  - OUTPUT: emit reload; bộ lọc chỉ thuộc component, không thay đổi form tạo mới.
  - SIDE EFFECT: không gọi API hoặc sửa items đầu vào.
  =====================================================================
-->
<script setup>
import { computed, shallowRef, watch } from 'vue'

const props = defineProps({
  items: { type: Array, required: true },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
})

const emit = defineEmits(['reload'])
const search = shallowRef('')
const status = shallowRef('all')
const page = shallowRef(1)
const itemsPerPage = shallowRef(8)

const statuses = [
  { value: 'all', title: 'Tất cả' },
  { value: 'generating', title: 'Đang tạo', color: 'primary' },
  { value: 'review', title: 'Chờ duyệt', color: 'warning' },
  { value: 'applied', title: 'Đã đưa vào Post', color: 'success' },
  { value: 'failed', title: 'Lỗi', color: 'error' },
  { value: 'cancelled', title: 'Đã hủy', color: 'secondary' },
  { value: 'expired', title: 'Hết hạn', color: 'secondary' },
]

const headers = [
  { title: 'Nội dung', key: 'title', sortable: false },
]

const statusOptions = Object.fromEntries(statuses.map(item => [item.value, item]))

const statusTabs = computed(() => statuses.map(item => ({
  ...item,
  count: props.items.filter(draft => item.value === 'all' || draft.status === item.value).length,
})))

const filteredItems = computed(() => {
  const query = (search.value || '').trim().toLocaleLowerCase('vi')

  return props.items.filter(item => (status.value === 'all' || item.status === status.value)
    && (!query || `${item.title} ${item.source}`.toLocaleLowerCase('vi').includes(query)))
})

watch([search, status, itemsPerPage], () => { page.value = 1 })
watch(() => filteredItems.value.length, total => {
  page.value = Math.min(page.value, Math.max(1, Math.ceil(total / itemsPerPage.value)))
})
</script>

<template>
  <VCard class="ai-content-list">
    <VCardItem
      title="Danh sách content AI"
      subtitle="Các tác vụ và bài viết AI đã được lưu."
    >
      <template #append>
        <VBtn
          size="small"
          variant="text"
          prepend-icon="tabler-refresh"
          :loading="props.loading"
          @click="emit('reload')"
        >
          Tải lại
        </VBtn>
      </template>
    </VCardItem>
    <VCardText>
      <VAlert
        v-if="props.error"
        type="error"
        variant="tonal"
        class="mb-4"
      >
        {{ props.error }}
      </VAlert>
      <AppTextField
        v-model="search"
        placeholder="Tìm theo tiêu đề hoặc nguồn..."
        aria-label="Tìm content AI"
        prepend-inner-icon="tabler-search"
        clearable
      />
    </VCardText>
    <VTabs
      v-model="status"
      aria-label="Lọc trạng thái nội dung AI"
    >
      <VTab
        v-for="tab in statusTabs"
        :key="tab.value"
        :value="tab.value"
      >
        {{ tab.title }}
        <VChip
          class="ms-2"
          size="x-small"
          :color="status === tab.value ? 'primary' : 'secondary'"
          variant="tonal"
        >
          {{ tab.count }}
        </VChip>
      </VTab>
    </VTabs>
    <VDivider />
    <VDataTable
      v-model:page="page"
      v-model:items-per-page="itemsPerPage"
      :items="filteredItems"
      :headers="headers"
      :loading="props.loading"
      hide-default-footer
      class="text-no-wrap"
    >
      <template #item.title="{ item }">
        <div class="d-flex align-center gap-3 py-3 ai-content-list__title">
          <VAvatar
            rounded
            size="40"
            color="primary"
            variant="tonal"
          >
            <VImg
              v-if="item.thumbnail?.file?.url"
              :src="item.thumbnail.file.url"
              alt=""
              cover
            />
            <VIcon
              v-else
              icon="tabler-file-text"
            />
          </VAvatar>
          <div class="ai-content-list__text">
            <div class="text-body-2 font-weight-medium text-high-emphasis text-wrap">
              {{ item.title || 'Chưa có tiêu đề' }}
            </div>
            <div class="text-caption text-medium-emphasis text-truncate">
              {{ item.source }}
            </div>
            <div class="d-flex flex-wrap align-center gap-2 mt-1">
              <VChip
                size="x-small"
                :color="statusOptions[item.status]?.color"
              >
                {{ statusOptions[item.status]?.title }}
              </VChip>
              <span class="text-caption text-disabled">
                {{ item.date }} · {{ item.time }}
              </span>
            </div>
          </div>
        </div>
      </template>
      <template #no-data>
        <div class="text-medium-emphasis py-6">
          Không có nội dung phù hợp với bộ lọc.
        </div>
      </template>
      <template #bottom>
        <div class="d-flex align-center flex-wrap gap-3 px-6 py-4">
          <span class="text-body-2 text-medium-emphasis">
            Số dòng mỗi trang
          </span>
          <VSpacer />
          <AppSelect
            v-model="itemsPerPage"
            :items="[8, 16, 24]"
            aria-label="Số dòng mỗi trang"
            class="ai-content-list__page-size"
          />
        </div>
        <TablePagination
          v-model:page="page"
          :items-per-page="itemsPerPage"
          :total-items="filteredItems.length"
        />
      </template>
    </VDataTable>
  </VCard>
</template>

<style scoped>
.ai-content-list__title {
  min-inline-size: 190px;
}

.ai-content-list__text {
  min-inline-size: 0;
}

.ai-content-list__page-size {
  flex: 0 0 90px;
}
</style>
