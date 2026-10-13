<script setup>
const props = defineProps({
  users: {
    type: Array,
    default: () => [],
  },
  totalUsers: {
    type: Number,
    default: 0,
  },
  loading: {
    type: Boolean,
    default: false,
  },
  sortBy: {
    type: String,
    default: 'name',
  },
  orderBy: {
    type: String,
    default: 'asc',
  },
})

const emit = defineEmits(['update:options'])

const page = defineModel('page', { type: Number, default: 1 })
const itemsPerPage = defineModel('itemsPerPage', { type: Number, default: 10 })

const headers = [
  { title: 'User', key: 'name' },
  { title: 'Username', key: 'username' },
  { title: 'Roles', key: 'roles', sortable: false },
  { title: 'Status', key: 'status' },
  { title: 'Created', key: 'created_at' },
  { title: 'Actions', key: 'actions', sortable: false },
]

const statusVariant = status => ({
  active: 'success',
  suspended: 'error',
  inactive: 'secondary',
  pending: 'warning',
}[String(status ?? '').toLowerCase()] ?? 'default')

const roleTitle = role => String(role ?? '').replace(/[-_]/g, ' ')

const rolesTitle = roles => roles?.map(role => roleTitle(role.name)).join(', ') || '—'

const formatDate = value => {
  if (!value)
    return '—'

  return new Intl.DateTimeFormat('vi-VN', { dateStyle: 'medium' }).format(new Date(value))
}
</script>

<template>
  <VDataTableServer
    v-model:page="page"
    v-model:items-per-page="itemsPerPage"
    :items="props.users"
    :items-length="props.totalUsers"
    :headers="headers"
    :sort-by="[{ key: props.sortBy, order: props.orderBy }]"
    :items-per-page-options="[10, 25, 50, 100]"
    :loading="props.loading"
    loading-text="Loading users…"
    no-data-text="Không có người dùng phù hợp với bộ lọc."
    item-value="id"
    class="text-no-wrap user-data-table"
    @update:options="emit('update:options', $event)"
  >
    <template #item.name="{ item }">
      <div class="d-flex align-center gap-x-3 py-2">
        <VAvatar
          size="34"
          color="primary"
          variant="tonal"
        >
          <span>{{ avatarText(item.name) }}</span>
        </VAvatar>
        <div class="d-flex flex-column min-width-0">
          <RouterLink
            :to="{ name: 'apps-user-view-id', params: { id: item.id } }"
            class="text-link font-weight-medium d-inline-block text-truncate"
            style="line-height: 1.375rem;"
          >
            {{ item.name || 'Unnamed user' }}
          </RouterLink>
          <span class="text-body-2 text-medium-emphasis text-truncate">
            {{ item.email || '—' }}
          </span>
        </div>
      </div>
    </template>

    <template #item.username="{ item }">
      <span class="text-body-2">{{ item.username || '—' }}</span>
    </template>

    <template #item.roles="{ item }">
      <span class="text-body-1 text-capitalize">{{ rolesTitle(item.roles) }}</span>
    </template>

    <template #item.status="{ item }">
      <VChip
        :color="statusVariant(item.status)"
        label
        size="small"
        class="text-capitalize"
      >
        {{ item.status || 'Unknown' }}
      </VChip>
    </template>

    <template #item.created_at="{ item }">
      <span class="text-body-2">{{ formatDate(item.created_at) }}</span>
    </template>

    <template #item.actions="{ item }">
      <VBtn
        icon="tabler-eye"
        variant="text"
        size="small"
        :to="{ name: 'apps-user-view-id', params: { id: item.id } }"
        :title="`View ${item.name || 'user'}`"
      />
    </template>
  </VDataTableServer>
</template>

<style scoped>
.user-data-table :deep(.v-data-table__td) {
  vertical-align: middle;
}

.min-width-0 {
  min-inline-size: 0;
}
</style>
