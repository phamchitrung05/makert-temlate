<!--
  =====================================================================
  CHỨC NĂNG FILE: Trình bày danh sách và chọn vai trò trong trang Roles.
  =====================================================================
  Giữ bố cục bảng của chủ dự án, dùng form/icon/theme của project và phân
  trang dữ liệu role thật tại client. Tạo vai trò mở dialog do page điều phối.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - filteredRoles, visibleRoles, pageCount, rangeLabel: tìm kiếm/phân trang.
  - watcher search/itemsPerPage: trở về trang đầu khi bộ lọc đổi.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : props roles/selectedRoleId; model search và query phân trang.
  - OUTPUT: bảng và empty state; emit select với ID vai trò được chọn.
  =====================================================================
-->
<script setup>
import { computed, shallowRef, watch } from 'vue'

const props = defineProps({
  roles: { type: Array, required: true },
  selectedRoleId: { type: [Number, String], default: null },
  loading: { type: Boolean, default: false },
  canCreate: { type: Boolean, default: false },
})

const emit = defineEmits(['select', 'create'])
const search = defineModel('search', { type: String, default: '' })
const page = shallowRef(1)
const itemsPerPage = shallowRef(6)

const filteredRoles = computed(() => {
  const query = (search.value || '').trim().toLocaleLowerCase('vi')

  return props.roles.filter(role => `${role.name} ${role.desc}`.toLocaleLowerCase('vi').includes(query))
})

const visibleRoles = computed(() => filteredRoles.value.slice((page.value - 1) * itemsPerPage.value, page.value * itemsPerPage.value))
const pageCount = computed(() => Math.max(1, Math.ceil(filteredRoles.value.length / itemsPerPage.value)))

const rangeLabel = computed(() => {
  const total = filteredRoles.value.length

  return total ? `${(page.value - 1) * itemsPerPage.value + 1}–${Math.min(page.value * itemsPerPage.value, total)} / ${total} vai trò` : '0 vai trò'
})

// INPUT: tìm kiếm/cỡ trang đổi. OUTPUT: trang đầu, không gọi API.
watch([search, itemsPerPage], () => { page.value = 1 })
</script>

<template>
  <VCard>
    <VCardText class="d-flex align-center justify-space-between flex-wrap gap-3">
      <div>
        <h5 class="text-h5">
          Danh sách vai trò
        </h5>
        <div class="text-body-2 text-medium-emphasis mt-1">
          Chọn vai trò để xem và thiết lập quyền.
        </div>
      </div>
      <VBtn
        size="small"
        prepend-icon="tabler-plus"
        :disabled="!canCreate || loading"
        :loading="loading"
        title="Tạo vai trò mới"
        @click="emit('create')"
      >
        Tạo vai trò
      </VBtn>
    </VCardText>
    <VCardText class="pt-0">
      <AppTextField
        v-model="search"
        placeholder="Tìm kiếm vai trò..."
        aria-label="Tìm kiếm vai trò"
        prepend-inner-icon="tabler-search"
        clearable
        hide-details
      />
    </VCardText>
    <VProgressLinear
      v-if="loading"
      indeterminate
      color="primary"
      height="2"
    />
    <VTable class="role-list-table">
      <thead>
        <tr>
          <th scope="col">
            VAI TRÒ
          </th>
          <th
            scope="col"
            class="text-end"
          >
            NGƯỜI DÙNG
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="role in visibleRoles"
          :key="role.id"
          :class="{ 'role-list-table__selected': selectedRoleId === role.id }"
        >
          <td>
            <button
              type="button"
              class="role-list-table__trigger"
              :aria-pressed="selectedRoleId === role.id"
              :aria-label="`Chọn vai trò ${role.name}`"
              @click="emit('select', role.id)"
            >
              <VAvatar
                :color="role.color"
                variant="tonal"
                rounded
                size="38"
              >
                <VIcon
                  :icon="role.icon"
                  size="22"
                />
              </VAvatar>
              <span class="role-list-table__label">
                <span class="text-body-1 font-weight-medium">{{ role.label || role.name }}</span>
                <span
                  class="text-body-2 text-medium-emphasis text-truncate"
                  :title="role.desc"
                >{{ role.desc }}</span>
                <VChip
                  :color="role.active ? 'success' : 'secondary'"
                  size="x-small"
                  class="mt-1 align-self-start"
                >
                  {{ role.status }}
                </VChip>
              </span>
            </button>
          </td>
          <td class="text-end text-body-1">
            <div class="d-flex align-center justify-end gap-1">
              <VIcon
                icon="tabler-user"
                size="16"
                class="text-medium-emphasis"
              />
              {{ role.userCount }}
            </div>
          </td>
        </tr>
        <tr v-if="!visibleRoles.length">
          <td colspan="2">
            <div class="text-center py-8">
              <VIcon
                icon="tabler-search-off"
                size="32"
                class="text-disabled mb-3"
              />
              <div class="text-body-1">
                Không tìm thấy vai trò
              </div>
              <VBtn
                variant="text"
                size="small"
                class="mt-2"
                @click="search = ''"
              >
                Xóa tìm kiếm
              </VBtn>
            </div>
          </td>
        </tr>
      </tbody>
    </VTable>
    <VDivider />
    <VCardText class="d-flex flex-wrap justify-space-between align-center gap-3 py-4">
      <span class="text-body-2 text-medium-emphasis">{{ rangeLabel }}</span>
      <div class="d-flex align-center gap-2">
        <AppSelect
          v-model="itemsPerPage"
          :items="[3, 6, 12]"
          aria-label="Số vai trò mỗi trang"
          class="role-list-table__page-size"
          hide-details
        />
        <VPagination
          v-if="pageCount > 1"
          v-model="page"
          :length="pageCount"
          :total-visible="2"
          size="small"
        />
      </div>
    </VCardText>
  </VCard>
</template>

<style scoped>
.role-list-table :deep(table) {
  inline-size: 100%;
  table-layout: fixed;
}

.role-list-table :deep(th:first-child),
.role-list-table :deep(td:first-child) {
  padding-inline-end: 8px;
}

.role-list-table :deep(th:last-child),
.role-list-table :deep(td:last-child) {
  inline-size: 132px;
  padding-inline-start: 8px;
  white-space: nowrap;
}

.role-list-table__trigger {
  display: flex;
  align-items: center;
  gap: 12px;
  inline-size: 100%;
  min-inline-size: 0;
  padding-block: 14px;
  text-align: start;
}

.role-list-table__trigger:focus-visible {
  border-radius: 4px;
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 3px;
}

.role-list-table__label {
  display: flex;
  flex-direction: column;
  min-inline-size: 0;
  max-inline-size: 220px;
}

.role-list-table__selected :deep(td) {
  background: rgba(var(--v-theme-primary), 0.08);
}

.role-list-table__selected :deep(td:first-child) {
  box-shadow: inset 3px 0 rgb(var(--v-theme-primary));
}

.role-list-table__page-size {
  inline-size: 78px;
}

@media (max-width: 599px) {
  .role-list-table__label {
    max-inline-size: 165px;
  }
}
</style>
