<!--
  =====================================================================
  CHỨC NĂNG FILE: Render bảng resource server-side cho admin Ecommerce
  =====================================================================

  Component chỉ chịu trách nhiệm trình bày các dòng resource và phát sự kiện
  pagination/sort lên page cha. Việc gọi API, filter và giữ query state nằm ở
  page list để data flow vẫn một chiều.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - resolveStatus(): ánh xạ status sang nhãn và màu chip
  - resolveType(): tạo nhãn hiển thị cho type
  - formatDate(): định dạng timestamp hiển thị trong bảng

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : resources, pagination state, totalItems và loading từ page cha
  - OUTPUT: UI VDataTableServer; emit update:itemsPerPage, update:page và update:options
  =====================================================================
-->
<script setup>
const props = defineProps({
  resources: {
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
  'publish',
  'archive',
  'delete',
])

const headers = [
  { title: 'Resource', key: 'title' },
  { title: 'Type', key: 'type' },
  { title: 'Status', key: 'status' },
  { title: 'Visibility', key: 'visibility' },
  { title: 'Downloads', key: 'downloads' },
  { title: 'Updated', key: 'updated_at' },
  { title: 'Actions', key: 'actions', sortable: false },
]

/**
 * Ánh xạ trạng thái backend sang nhãn và màu chip của Vuetify.
 *
 * Input: status string từ resource summary.
 * Output: object `{ text, color }` dùng cho VChip.
 */
const resolveStatus = status => {
  const statuses = {
    published: { text: 'Published', color: 'success' },
    'pending_review': { text: 'Pending review', color: 'warning' },
    draft: { text: 'Draft', color: 'secondary' },
    suspended: { text: 'Suspended', color: 'error' },
    archived: { text: 'Archived', color: 'default' },
  }

  return statuses[status] ?? { text: status, color: 'default' }
}

/**
 * Đổi resource type dạng enum thành nhãn thân thiện với người dùng.
 *
 * Input: type string từ fake API hoặc Laravel resource.
 * Output: nhãn hiển thị trong cột Type.
 */
const resolveType = type => {
  const labels = {
    'ui_kit': 'UI kit',
    'icon_pack': 'Icon pack',
    'pending_review': 'Pending review',
  }

  return labels[type] ?? type?.replaceAll('_', ' ')
}

/**
 * Định dạng ISO timestamp theo locale hiện tại của trình duyệt.
 *
 * Input: ISO date string hoặc null.
 * Output: chuỗi ngày giờ ngắn, hoặc dấu gạch ngang khi chưa có dữ liệu.
 */
const formatDate = value => {
  if (!value)
    return '—'

  return new Intl.DateTimeFormat('vi-VN', {
    dateStyle: 'medium',
  }).format(new Date(value))
}
</script>

<template>
  <VDataTableServer
    :headers="headers"
    :items="props.resources"
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
      <div class="d-flex flex-column py-2">
        <span class="text-body-1 font-weight-medium text-high-emphasis">
          {{ item.title }}
        </span>
        <span class="text-body-2">{{ item.code }}</span>
      </div>
    </template>

    <template #item.type="{ item }">
      <VChip
        label
        size="small"
        variant="tonal"
      >
        {{ resolveType(item.type) }}
      </VChip>
    </template>

    <template #item.status="{ item }">
      <VChip
        :color="resolveStatus(item.status).color"
        label
        size="small"
      >
        {{ resolveStatus(item.status).text }}
      </VChip>
    </template>

    <template #item.visibility="{ item }">
      <span class="text-body-2 text-capitalize">{{ item.visibility }}</span>
    </template>

    <template #item.downloads="{ item }">
      <span class="text-body-1 text-high-emphasis">
        {{ item.counters?.downloads?.toLocaleString('vi-VN') ?? 0 }}
      </span>
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
              value="view"
              :to="{ name: 'apps-ecommerce-resource-list', query: { resource: item.id } }"
            >
              View
            </VListItem>
            <VListItem
              value="edit"
              :to="{ name: 'apps-ecommerce-resource-add', query: { resource: item.id } }"
            >
              Edit
            </VListItem>
            <VListItem
              v-if="!['published', 'archived'].includes(item.status)"
              value="publish"
              @click="emit('publish', item)"
            >
              Publish
            </VListItem>
            <VListItem
              v-if="item.status !== 'archived'"
              value="archive"
              @click="emit('archive', item)"
            >
              Archive
            </VListItem>
            <VListItem
              value="delete"
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
