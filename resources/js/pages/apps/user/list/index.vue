<script setup>
import { useUserList } from '@/composables/useUserList'
import UserDataTable from '@/views/apps/user/list/UserDataTable.vue'
import UserListFilters from '@/views/apps/user/list/UserListFilters.vue'

const {
  users,
  totalUsers,
  roleOptions,
  statusOptions,
  searchQuery,
  selectedStatus,
  selectedRoleId,
  itemsPerPage,
  page,
  sortBy,
  orderBy,
  isLoading,
  errorMessage,
  hasActiveFilters,
  fetchUsers,
  resetFilters,
  updateTableOptions,
} = useUserList()
</script>

<template>
  <section>
    <div class="d-flex flex-wrap justify-space-between gap-y-4 mb-6">
      <div>
        <h4 class="text-h4 font-weight-medium">
          Users
        </h4>
        <div class="text-body-1">
          Quản lý tài khoản admin, vai trò và trạng thái truy cập.
        </div>
      </div>

      <VBtn
        variant="tonal"
        color="secondary"
        prepend-icon="tabler-refresh"
        :loading="isLoading"
        @click="fetchUsers"
      >
        Refresh
      </VBtn>
    </div>

    <UserListFilters
      v-model:search="searchQuery"
      v-model:status="selectedStatus"
      v-model:role-id="selectedRoleId"
      :role-options="roleOptions"
      :status-options="statusOptions"
      :has-active-filters="hasActiveFilters"
      @reset="resetFilters"
    />

    <VCard>
      <VAlert
        v-if="errorMessage"
        color="error"
        variant="tonal"
        class="ma-4"
      >
        {{ errorMessage }}
        <template #append>
          <VBtn
            variant="text"
            color="error"
            :loading="isLoading"
            @click="fetchUsers"
          >
            Tải lại người dùng
          </VBtn>
        </template>
      </VAlert>

      <UserDataTable
        v-model:page="page"
        v-model:items-per-page="itemsPerPage"
        :users="users"
        :total-users="totalUsers"
        :loading="isLoading"
        :sort-by="sortBy"
        :order-by="orderBy"
        @update:options="updateTableOptions"
      />
    </VCard>
  </section>
</template>
