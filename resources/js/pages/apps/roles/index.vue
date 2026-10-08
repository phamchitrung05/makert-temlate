<!--
  =====================================================================
  CHỨC NĂNG FILE: Điều phối trang Roles & Permissions dùng API thật.
  =====================================================================
  Page giữ vai trò composition surface: tải role/catalog/user, giữ draft
  permission của role đang chọn và chuyển mutation cho service access.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - selectedRole, selectedPermissionIds, isDirty, statistics: state dẫn xuất.
  - loadAccessData(): tải lại role, catalog và user từ API.
  - selectRole(): chọn role và khởi tạo draft permission.
  - savePermissions(): PATCH tên/quyền role theo expected_version.
  - resetPermissions(): bỏ permission draft chưa lưu.
  - openCreateRole(), createRoleFromDialog(): mở dialog và POST role mới.
  - getApiError(): chuẩn hóa message lỗi để hiển thị.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : admin Bearer token qua API client và thao tác từ các panel.
  - OUTPUT: giao diện role/permission đồng bộ dữ liệu backend, event UI và dialog.
  - SIDE EFFECT: GET/PATCH/POST API access; không dùng dữ liệu preview tĩnh.
  =====================================================================
-->
<script setup>
import { computed, onMounted, ref, shallowRef } from 'vue'
import CreateRoleDialog from '@/views/apps/roles/dialog/CreateRoleDialog.vue'
import RolesOverview from '@/views/apps/roles/RolesOverview.vue'
import RoleListPanel from '@/views/apps/roles/RoleListPanel.vue'
import RolePermissionPanel from '@/views/apps/roles/RolePermissionPanel.vue'
import { createRole, getCatalog, listRoles, listUsers, updateRole } from '@/services/accessManagement'
import { getRolePermissionIds, mapPermissionCatalog, mapPermissionGroups, mapRole } from '@/views/apps/roles/roleData'

const roles = ref([])
const permissionModules = ref([])
const permissionGroups = ref([])
const users = ref([])
const permissionDrafts = ref({})
const selectedRoleId = shallowRef(null)
const rolesPagination = shallowRef({})
const canCreateRole = shallowRef(false)
const isLoading = shallowRef(false)
const isSaving = shallowRef(false)
const isCreatingRole = shallowRef(false)
const isCreateDialogVisible = shallowRef(false)
const errorMessage = shallowRef('')
const dialogError = shallowRef('')
const feedbackMessage = shallowRef('')
const isFeedbackVisible = shallowRef(false)

const selectedRole = computed(() => roles.value.find(role => Number(role.id) === Number(selectedRoleId.value)) ?? null)

const selectedPermissionIds = computed({
  get: () => selectedRole.value ? permissionDrafts.value[selectedRole.value.id] ?? [] : [],
  set: value => {
    if (selectedRole.value)
      permissionDrafts.value[selectedRole.value.id] = [...value]
  },
})

const isDirty = computed(() => selectedRole.value
  ? JSON.stringify([...selectedPermissionIds.value].sort()) !== JSON.stringify([...selectedRole.value.permissionIds].sort())
  : false)

const statistics = computed(() => [
  {
    title: 'Tổng số vai trò',
    value: rolesPagination.value.total ?? roles.value.length,
    description: `${roles.value.filter(role => role.active).length} role đang hoạt động`,
    icon: 'tabler-users-group',
    color: 'primary',
  },
  {
    title: 'Quyền truy cập',
    value: permissionModules.value.reduce((total, group) => total + group.actions.length, 0),
    description: `${permissionGroups.value.length} nhóm · ${permissionModules.value.length} module từ catalog`,
    icon: 'tabler-shield-lock',
    color: 'info',
  },
  {
    title: 'Lượt gán role',
    value: roles.value.reduce((total, role) => total + role.userCount, 0),
    description: 'Tổng lượt gán trên các role',
    icon: 'tabler-user-check',
    color: 'success',
  },
  {
    title: 'Role tùy chỉnh',
    value: roles.value.filter(role => !role.isSystem).length,
    description: `${roles.value.filter(role => role.isSystem).length} role hệ thống`,
    icon: 'tabler-shield-plus',
    color: 'warning',
  },
])

/**
 * Chuẩn hóa message lỗi HTTP/API để page không phụ thuộc cấu trúc ofetch.
 *
 * Input: error từ $api, có thể chứa data.message hoặc data.errors.
 * Output: message tiếng Việt ngắn gọn cho alert/snackbar.
 */
const getApiError = error => {
  const payload = error?.data ?? error?.response?._data ?? {}
  const firstFieldError = Object.values(payload.errors ?? {})[0]

  return Array.isArray(firstFieldError) ? firstFieldError[0] : firstFieldError ?? payload.message ?? 'Không thể hoàn tất thao tác access management.'
}

/**
 * Khởi tạo draft permission từ danh sách role backend.
 *
 * Input: roles đã map về model hiển thị.
 * Output: object draft theo role ID.
 * Side effect: thay permissionDrafts và chọn role đầu tiên nếu cần.
 */
const initializeRoleState = roleItems => {
  permissionDrafts.value = Object.fromEntries(roleItems.map(role => [role.id, [...role.permissionIds]]))

  if (!roleItems.some(role => Number(role.id) === Number(selectedRoleId.value)))
    selectedRoleId.value = roleItems[0]?.id ?? null
}

/**
 * Tải toàn bộ dữ liệu cần cho một trang Roles & Permissions.
 *
 * Input: không có; API lấy Bearer token từ sessionStorage.
 * Output: roles/catalog/users được cập nhật trong state page.
 * Side effect: thực hiện ba GET song song và hiển thị lỗi nếu một request thất bại.
 */
const loadAccessData = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const [rolesResponse, catalogResponse, usersResponse] = await Promise.all([
      listRoles(),
      getCatalog(),
      listUsers(),
    ])

    const roleItems = (rolesResponse?.items ?? []).map(mapRole)

    roles.value = roleItems
    rolesPagination.value = rolesResponse?.pagination ?? {}
    permissionModules.value = mapPermissionCatalog(catalogResponse)
    permissionGroups.value = mapPermissionGroups(permissionModules.value)
    users.value = usersResponse?.items ?? []
    canCreateRole.value = Boolean(catalogResponse?.can_create_role ?? catalogResponse?.can_manage)
    initializeRoleState(roleItems)
  }
  catch (error) {
    errorMessage.value = getApiError(error)
  }
  finally {
    isLoading.value = false
  }
}

/**
 * Chọn role từ danh sách và bảo đảm draft đã tồn tại.
 *
 * Input: roleId từ RoleListPanel.
 * Output: selectedRoleId được cập nhật.
 * Side effect: khởi tạo draft rỗng cho role mới nếu cần.
 */
const selectRole = roleId => {
  selectedRoleId.value = roleId

  if (permissionDrafts.value[roleId] === undefined) {
    const role = roles.value.find(item => Number(item.id) === Number(roleId))

    permissionDrafts.value[roleId] = role ? getRolePermissionIds(role) : []
  }
}

/**
 * Lưu permission role đang chọn theo version API mới nhất.
 *
 * Input: selectedRole và selectedPermissionIds draft.
 * Output: role state/version mới từ backend, snackbar thành công.
 * Side effect: PATCH role; lỗi conflict giữ nguyên draft để người dùng tải lại.
 */
const savePermissions = async () => {
  if (!selectedRole.value?.canEdit || isSaving.value)
    return

  isSaving.value = true
  errorMessage.value = ''

  try {
    const response = await updateRole(selectedRole.value.id, {
      name: selectedRole.value.name,
      'permission_ids': [...selectedPermissionIds.value],
      'expected_version': selectedRole.value.version,
    })

    const updatedRole = mapRole(response)

    roles.value = roles.value.map(role => role.id === updatedRole.id ? updatedRole : role)
    permissionDrafts.value[updatedRole.id] = [...updatedRole.permissionIds]
    feedbackMessage.value = 'Đã đồng bộ permission với máy chủ.'
    isFeedbackVisible.value = true
  }
  catch (error) {
    errorMessage.value = getApiError(error)
  }
  finally {
    isSaving.value = false
  }
}

/**
 * Bỏ draft permission chưa gửi lên backend.
 *
 * Input: role đang chọn.
 * Output: draft trở về permissionIds đã lưu.
 * Side effect: thay state cục bộ, không gọi API.
 */
const resetPermissions = () => {
  if (selectedRole.value)
    permissionDrafts.value[selectedRole.value.id] = [...selectedRole.value.permissionIds]
}

/**
 * Mở dialog tạo role và xóa lỗi cũ.
 *
 * Input: click nút tạo role.
 * Output: dialog visible=true.
 * Side effect: reset dialog error state.
 */
const openCreateRole = () => {
  dialogError.value = ''
  isCreateDialogVisible.value = true
}

/**
 * Gửi payload từ dialog, sau đó tải lại state từ API để đồng bộ role/user/version.
 *
 * Input: name và permission_ids đã validate ở dialog.
 * Output: danh sách role mới và role vừa tạo được chọn.
 * Side effect: POST role, gọi lại các GET access và hiển thị feedback.
 */
const createRoleFromDialog = async payload => {
  isCreatingRole.value = true
  dialogError.value = ''

  try {
    const created = await createRole(payload)
    const createdId = created?.id

    isCreateDialogVisible.value = false
    await loadAccessData()
    if (createdId)
      selectedRoleId.value = createdId
    feedbackMessage.value = 'Đã tạo vai trò mới.'
    isFeedbackVisible.value = true
  }
  catch (error) {
    dialogError.value = getApiError(error)
  }
  finally {
    isCreatingRole.value = false
  }
}

onMounted(loadAccessData)
</script>

<template>
  <section aria-labelledby="roles-page-title">
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-6">
      <div>
        <h4
          id="roles-page-title"
          class="text-h4 font-weight-medium mb-1"
        >
          Vai trò &amp; Phân quyền
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          Quản lý role và quyền truy cập từ cùng một màn hình.
        </p>
      </div>
      <VChip
        :color="isLoading ? 'info' : 'success'"
        size="small"
        :prepend-icon="isLoading ? 'tabler-refresh' : 'tabler-cloud-check'"
      >
        {{ isLoading ? 'Đang đồng bộ' : 'Dữ liệu đã đồng bộ' }}
      </VChip>
    </div>

    <VAlert
      v-if="errorMessage"
      color="error"
      variant="tonal"
      class="mb-6"
      closable
      @click:close="errorMessage = ''"
    >
      {{ errorMessage }}
      <template #append>
        <VBtn
          variant="text"
          size="small"
          @click="loadAccessData"
        >
          Thử lại
        </VBtn>
      </template>
    </VAlert>

    <div
      v-if="isLoading && !roles.length"
      class="d-flex flex-column align-center justify-center py-16"
    >
      <VProgressCircular
        indeterminate
        color="primary"
        size="42"
      />
      <p class="text-body-1 text-medium-emphasis mt-4 mb-0">
        Đang tải cấu hình truy cập...
      </p>
    </div>

    <template v-else>
      <RolesOverview
        :items="statistics"
        class="mb-6"
      />
      <VRow
        v-if="roles.length"
        class="align-start"
      >
        <VCol
          cols="12"
          lg="5"
          xl="4"
        >
          <RoleListPanel
            :roles="roles"
            :selected-role-id="selectedRoleId"
            :loading="isLoading"
            :can-create="canCreateRole"
            @select="selectRole"
            @create="openCreateRole"
          />
        </VCol>
        <VCol
          cols="12"
          lg="7"
          xl="8"
        >
          <RolePermissionPanel
            v-if="selectedRole"
            v-model:permission-ids="selectedPermissionIds"
            :role="selectedRole"
            :modules="permissionModules"
            :groups="permissionGroups"
            :users="users"
            :is-dirty="isDirty"
            :saving="isSaving"
            @save="savePermissions"
            @reset="resetPermissions"
          />
        </VCol>
      </VRow>
      <VCard
        v-else
        class="text-center"
      >
        <VCardText class="py-16">
          <VAvatar
            color="primary"
            variant="tonal"
            size="56"
            class="mb-4"
          >
            <VIcon
              icon="tabler-shield-plus"
              size="30"
            />
          </VAvatar>
          <h5 class="text-h5 mb-2">
            Chưa có vai trò
          </h5>
          <p class="text-body-2 text-medium-emphasis mb-5">
            Tạo vai trò đầu tiên để bắt đầu phân quyền.
          </p>
          <VBtn
            :disabled="!canCreateRole"
            prepend-icon="tabler-plus"
            @click="openCreateRole"
          >
            Tạo vai trò
          </VBtn>
        </VCardText>
      </VCard>
    </template>

    <CreateRoleDialog
      v-model="isCreateDialogVisible"
      :modules="permissionModules"
      :groups="permissionGroups"
      :loading="isCreatingRole"
      :error-message="dialogError"
      @submit="createRoleFromDialog"
    />

    <VSnackbar
      v-model="isFeedbackVisible"
      color="success"
      location="bottom end"
      :timeout="3500"
      role="status"
    >
      {{ feedbackMessage }}
      <template #actions>
        <VBtn
          variant="text"
          color="on-success"
          aria-label="Đóng thông báo"
          icon="tabler-x"
          @click="isFeedbackVisible = false"
        />
      </template>
    </VSnackbar>
  </section>
</template>
