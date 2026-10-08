<!--
  =====================================================================
  CHỨC NĂNG FILE: Dialog tạo role mới theo layout Roles & Permissions.
  =====================================================================
  Dialog giữ state form và permission draft; page Roles chịu trách nhiệm
  gọi API, hiển thị lỗi server và tải lại danh sách sau khi tạo thành công.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - allPermissionIds, allAssignablePermissionIds, allSelected, partiallySelected.
  - filteredGroups, roleSlug, totalSelectedPermissions: dữ liệu hiển thị dẫn xuất.
  - resetForm(), close(), toggleAll(), toggleGroup(), toggleGroupExpansion(), submit(): điều khiển form và dialog.
  - watcher modelValue/searchQuery: reset form và tự mở nhóm khớp tìm kiếm.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : modelValue, modules, groups, loading và errorMessage từ page Roles.
  - OUTPUT: update:modelValue khi đóng; submit với name/permission_ids hợp lệ.
  - SIDE EFFECT: Không gọi API trực tiếp; không ghi database.
  =====================================================================
-->
<script setup>
import { computed, shallowRef, watch } from 'vue'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import { VForm } from 'vuetify/components/VForm'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  modules: { type: Array, default: () => [] },
  groups: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  errorMessage: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'submit'])
const form = shallowRef()
const roleName = shallowRef('')
const permissionIds = shallowRef([])
const searchQuery = shallowRef('')
const expandedGroupIds = shallowRef([])

const nameRules = [
  value => Boolean(value?.trim()) || 'Vui lòng nhập tên role.',
  value => /^[a-z][a-z0-9_-]*$/.test(value?.trim() ?? '') || 'Dùng chữ thường, số, dấu gạch ngang hoặc gạch dưới.',
]

/**
 * Chuẩn hóa tên role về slug mà API cho phép.
 *
 * Input: tên role đã nhập trong form.
 * Output: chuỗi chữ thường dùng làm name khi submit.
 * Side effect: Không thay đổi state.
 */
const normalizeRoleName = value => value.trim().toLowerCase()

/**
 * Tính mã kỹ thuật hiển thị cho role từ tên hiện tại.
 *
 * Input: roleName state.
 * Output: slug preview; không gửi thêm field không có trong API.
 * Side effect: Không ghi state.
 */
const roleSlug = computed(() => normalizeRoleName(roleName.value))

const permissionGroups = computed(() => props.groups.length
  ? props.groups
  : [{
    id: 'all-modules',
    title: 'Quyền truy cập',
    desc: 'Các permission hiện có trong catalog.',
    icon: 'tabler-shield',
    modules: props.modules,
  }])

const allPermissionIds = computed(() => permissionGroups.value
  .flatMap(group => group.modules.flatMap(module => module.actions.map(action => action.id))))

const allAssignablePermissionIds = computed(() => permissionGroups.value
  .flatMap(group => group.modules.flatMap(module => module.actions))
  .filter(action => action.assignable !== false)
  .map(action => action.id))

const selectedAssignableCount = computed(() => permissionIds.value
  .filter(id => allAssignablePermissionIds.value.includes(id)).length)

const totalSelectedPermissions = computed(() => permissionIds.value.length)

const allSelected = computed(() => allAssignablePermissionIds.value.length > 0
  && allAssignablePermissionIds.value.every(id => permissionIds.value.includes(id)))

const partiallySelected = computed(() => selectedAssignableCount.value > 0 && !allSelected.value)

/**
 * Lọc nhóm, module và action theo từ khóa tìm kiếm.
 *
 * Input: permission groups từ catalog thật và searchQuery.
 * Output: nhóm có visibleModules/action DTO chỉ dùng để render, không mutate props.
 * Side effect: Không gọi API hoặc sửa permission draft.
 */
const filteredGroups = computed(() => {
  const query = searchQuery.value.trim().toLocaleLowerCase('vi')

  return permissionGroups.value.map(group => {
    const groupText = `${group.title} ${group.desc}`.toLocaleLowerCase('vi')
    const groupMatches = !query || groupText.includes(query)

    const visibleModules = group.modules.map(module => {
      const moduleText = `${module.title} ${module.desc}`.toLocaleLowerCase('vi')
      const moduleMatches = groupMatches || !query || moduleText.includes(query)
      const actions = module.actions.filter(action => moduleMatches || `${action.label} ${action.name}`.toLocaleLowerCase('vi').includes(query))

      return { ...module, actions }
    }).filter(module => module.actions.length > 0)

    return { ...group, visibleModules }
  }).filter(group => group.visibleModules.length > 0 || !query)
})

/**
 * Lấy permission ID thuộc một nhóm và trạng thái checkbox của nhóm.
 *
 * Input: group DTO chứa toàn bộ module/action, không phải bản đã lọc.
 * Output: danh sách ID hoặc số lượng/trạng thái chọn hiện tại.
 * Side effect: Không thay đổi permission draft.
 */
const groupPermissionIds = group => group.modules.flatMap(module => module.actions.map(action => action.id))

const groupAssignablePermissionIds = group => group.modules
  .flatMap(module => module.actions)
  .filter(action => action.assignable !== false)
  .map(action => action.id)

const groupSelectedCount = group => groupPermissionIds(group)
  .filter(id => permissionIds.value.includes(id)).length

const groupAssignableSelectedCount = group => groupAssignablePermissionIds(group)
  .filter(id => permissionIds.value.includes(id)).length

const groupAllSelected = group => groupAssignablePermissionIds(group).length > 0
  && groupAssignablePermissionIds(group).every(id => permissionIds.value.includes(id))

const groupPartiallySelected = group => groupAssignableSelectedCount(group) > 0 && !groupAllSelected(group)
const isGroupExpanded = groupId => expandedGroupIds.value.includes(groupId)

/**
 * Mở hoặc thu gọn nội dung một nhóm permission.
 *
 * Input: groupId của nhóm được bấm.
 * Output: danh sách groupId đang mở.
 * Side effect: Cập nhật state hiển thị nội bộ dialog.
 */
const toggleGroupExpansion = groupId => {
  expandedGroupIds.value = expandedGroupIds.value.includes(groupId)
    ? expandedGroupIds.value.filter(id => id !== groupId)
    : [...expandedGroupIds.value, groupId]
}

/**
 * Đưa form và bộ lọc về trạng thái tạo role mới.
 *
 * Input: không có.
 * Output: form rỗng, permission draft rỗng và validation được reset.
 * Side effect: Thay đổi state nội bộ dialog.
 */
const resetForm = () => {
  roleName.value = ''
  permissionIds.value = []
  searchQuery.value = ''
  expandedGroupIds.value = []
  form.value?.resetValidation()
}

/**
 * Đóng dialog khi không còn request tạo role đang chạy.
 *
 * Input: thao tác đóng từ dialog hoặc footer.
 * Output: emit update:modelValue=false về page cha.
 * Side effect: Không gọi API.
 */
const close = () => {
  if (!props.loading)
    emit('update:modelValue', false)
}

/**
 * Chọn hoặc bỏ toàn bộ permission mà actor hiện tại được phép cấp.
 *
 * Input: selected từ checkbox tổng.
 * Output: permissionIds chỉ chứa ID assignable hợp lệ.
 * Side effect: Cập nhật draft cục bộ; API được gọi ở page sau submit.
 */
const toggleAll = selected => {
  permissionIds.value = selected ? [...allAssignablePermissionIds.value] : []
}

/**
 * Chọn hoặc bỏ toàn bộ permission trong một nhóm.
 *
 * Input: group đầy đủ và trạng thái checkbox mới.
 * Output: permissionIds chứa toàn bộ ID assignable của nhóm khi bật.
 * Side effect: Cập nhật draft cục bộ; không gọi API.
 */
const toggleGroup = (group, selected) => {
  const nextIds = new Set(permissionIds.value)

  groupAssignablePermissionIds(group).forEach(id => {
    if (selected)
      nextIds.add(id)
    else
      nextIds.delete(id)
  })

  permissionIds.value = [...nextIds]
}

/**
 * Validate và chuyển form thành payload tạo role cho page cha.
 *
 * Input: roleName và permissionIds hiện tại.
 * Output: event submit với name và permission_ids.
 * Side effect: Gọi validation của VForm; không tự gọi API.
 */
const submit = async () => {
  const validation = await form.value?.validate()

  if (!validation?.valid)
    return

  emit('submit', {
    name: normalizeRoleName(roleName.value),
    'permission_ids': [...permissionIds.value],
  })
}

watch(() => props.modelValue, visible => {
  if (visible)
    resetForm()
})

watch(searchQuery, query => {
  expandedGroupIds.value = query.trim()
    ? filteredGroups.value.map(group => group.id)
    : []
})
</script>

<template>
  <div id="view-moi">
    <VDialog
      :model-value="props.modelValue"
      :width="$vuetify.display.smAndDown ? 'calc(100% - 24px)' : 1180"
      max-width="1180"
      scrollable
      :persistent="props.loading"
      @update:model-value="close"
    >
      <AppDialogLayout
        close-label="Đóng dialog tạo role"
        :close-disabled="props.loading"
        @close="close"
      >
        <template #header>
          <VCardItem class="px-5 px-sm-6 py-4">
            <div class="d-flex flex-wrap align-center justify-space-between gap-4 w-100">
              <div class="d-flex align-center min-w-0">
                <VAvatar
                  color="primary"
                  variant="tonal"
                  rounded="lg"
                  size="44"
                  class="me-3"
                >
                  <VIcon
                    icon="tabler-user-plus"
                    size="24"
                  />
                </VAvatar>
                <div class="min-w-0">
                  <VCardTitle class="px-0 py-0">
                    Tạo vai trò mới
                  </VCardTitle>
                  <VCardSubtitle class="px-0 py-1 text-wrap">
                    Thiết lập thông tin và quyền truy cập cho vai trò mới.
                  </VCardSubtitle>
                </div>
              </div>
              <div class="d-flex align-center flex-wrap gap-2">
                <VChip
                  size="small"
                  color="primary"
                  variant="tonal"
                  prepend-icon="tabler-shield-plus"
                >
                  Vai trò tùy chỉnh
                </VChip>
                <VChip
                  size="small"
                  color="success"
                  variant="tonal"
                >
                  Mới
                </VChip>
              </div>
            </div>
          </VCardItem>
        </template>

        <VCardText class="create-role-dialog__body px-4 px-sm-6 py-5">
          <VAlert
            v-if="props.errorMessage"
            color="error"
            variant="tonal"
            class="mb-5"
            closable
          >
            {{ props.errorMessage }}
          </VAlert>

          <VForm
            ref="form"
            validate-on="submit"
            @submit.prevent="submit"
          >
            <VRow
              dense
              class="mb-5"
            >
              <VCol
                v-for="stat in [
                  { label: 'Quyền được chọn', value: totalSelectedPermissions, icon: 'tabler-shield-check', color: 'primary' },
                  { label: 'Nhóm quyền', value: permissionGroups.length, icon: 'tabler-box', color: 'info' },
                  { label: 'Người dùng áp dụng', value: 0, icon: 'tabler-user-check', color: 'success' },
                ]"
                :key="stat.label"
                cols="12"
                sm="4"
              >
                <VCard
                  variant="outlined"
                  class="create-role-dialog__stat h-100"
                >
                  <VCardText class="d-flex align-center pa-4">
                    <VAvatar
                      :color="stat.color"
                      variant="tonal"
                      rounded="lg"
                      size="42"
                      class="me-3"
                    >
                      <VIcon
                        :icon="stat.icon"
                        size="22"
                      />
                    </VAvatar>
                    <div>
                      <div class="text-body-2 text-medium-emphasis">
                        {{ stat.label }}
                      </div>
                      <div class="text-h6 font-weight-medium">
                        {{ stat.value }}
                      </div>
                    </div>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>

            <VRow
              dense
              class="align-start"
            >
              <VCol
                cols="12"
                sm="4"
              >
                <div class="mb-5">
                  <h6 class="text-h6 mb-1">
                    Thông tin vai trò
                  </h6>
                  <p class="text-body-2 text-medium-emphasis mb-0">
                    Tên role được lưu dưới dạng slug và dùng trực tiếp trong API.
                  </p>
                </div>

                <AppTextField
                  v-model="roleName"
                  label="Tên vai trò"
                  placeholder="content-editor"
                  hint="Bắt đầu bằng chữ thường; dùng chữ, số, - hoặc _."
                  persistent-hint
                  :rules="nameRules"
                  :disabled="props.loading"
                  autofocus
                  class="mb-5"
                />

                <AppTextField
                  :model-value="roleSlug || '—'"
                  label="Mã kỹ thuật"
                  hint="Tự động chuẩn hóa từ tên vai trò."
                  persistent-hint
                  readonly
                  :disabled="props.loading"
                  class="mb-5"
                />

                <VAlert
                  color="primary"
                  variant="tonal"
                  icon="tabler-info-circle"
                  density="comfortable"
                >
                  Role mới chưa được gán người dùng. Sau khi tạo, bạn có thể chỉnh permission trong panel chi tiết.
                </VAlert>
              </VCol>

              <VCol
                cols="12"
                sm="8"
              >
                <div class="d-flex flex-wrap align-start justify-space-between gap-3 mb-4">
                  <div>
                    <h6 class="text-h6 mb-1">
                      Phân quyền nhanh
                    </h6>
                    <p class="text-body-2 text-medium-emphasis mb-0">
                      {{ totalSelectedPermissions }} / {{ allAssignablePermissionIds.length }} quyền được chọn
                    </p>
                  </div>
                  <VCheckbox
                    :model-value="allSelected"
                    :indeterminate="partiallySelected"
                    label="Chọn tất cả"
                    hide-details
                    :disabled="props.loading || !allAssignablePermissionIds.length"
                    @update:model-value="toggleAll"
                  />
                </div>

                <VAlert
                  v-if="allAssignablePermissionIds.length < allPermissionIds.length"
                  color="warning"
                  variant="tonal"
                  density="compact"
                  icon="tabler-lock"
                  class="mb-4"
                >
                  Một số quyền đang bị khóa vì nằm ngoài phạm vi cấp quyền của tài khoản hiện tại.
                </VAlert>

                <div class="d-flex flex-wrap align-center gap-3 mb-4">
                  <AppTextField
                    v-model="searchQuery"
                    placeholder="Tìm quyền hoặc module..."
                    aria-label="Tìm quyền hoặc module"
                    prepend-inner-icon="tabler-search"
                    clearable
                    hide-details
                    class="flex-grow-1"
                  />
                </div>

                <VAlert
                  v-if="!props.modules.length"
                  color="secondary"
                  variant="tonal"
                  icon="tabler-info-circle"
                >
                  Catalog permission chưa sẵn sàng. Bạn có thể đóng dialog và thử lại sau.
                </VAlert>
                <VAlert
                  v-else-if="!filteredGroups.length"
                  color="secondary"
                  variant="tonal"
                  icon="tabler-search-off"
                >
                  Không tìm thấy permission phù hợp với bộ lọc hiện tại.
                </VAlert>
                <div
                  v-else
                  class="create-role-dialog__permissions"
                >
                  <VCard
                    v-for="group in filteredGroups"
                    :key="group.id"
                    variant="outlined"
                    class="create-role-dialog__group"
                  >
                    <VCardText class="pa-3">
                      <div class="create-role-dialog__group-header">
                        <div class="d-flex align-start gap-3 min-w-0">
                          <VAvatar
                            color="primary"
                            variant="tonal"
                            rounded="lg"
                            size="36"
                          >
                            <VIcon
                              :icon="group.icon"
                              size="20"
                            />
                          </VAvatar>
                          <div class="min-w-0">
                            <div class="text-body-1 font-weight-medium">
                              {{ group.title }}
                            </div>
                            <div class="text-body-2 text-medium-emphasis">
                              {{ group.desc }}
                            </div>
                          </div>
                        </div>
                        <div class="create-role-dialog__group-controls">
                          <VChip
                            size="x-small"
                            color="primary"
                            variant="tonal"
                          >
                            {{ groupSelectedCount(group) }} / {{ groupPermissionIds(group).length }}
                          </VChip>
                          <VCheckbox
                            :model-value="groupAllSelected(group)"
                            :indeterminate="groupPartiallySelected(group)"
                            label="Chọn nhóm"
                            hide-details
                            density="compact"
                            :disabled="props.loading || !groupAssignablePermissionIds(group).length"
                            @update:model-value="toggleGroup(group, $event)"
                          />
                          <VBtn
                            variant="text"
                            size="small"
                            icon
                            :aria-label="isGroupExpanded(group.id) ? `Thu gọn nhóm ${group.title}` : `Mở nhóm ${group.title}`"
                            @click="toggleGroupExpansion(group.id)"
                          >
                            <VIcon
                              :icon="isGroupExpanded(group.id) ? 'tabler-chevron-up' : 'tabler-chevron-down'"
                              size="20"
                            />
                          </VBtn>
                        </div>
                      </div>

                      <VExpandTransition>
                        <div
                          v-show="isGroupExpanded(group.id)"
                          class="create-role-dialog__group-content"
                        >
                          <div
                            v-for="module in group.visibleModules"
                            :key="module.id"
                            class="create-role-dialog__module-row"
                          >
                            <div class="create-role-dialog__module-meta">
                              <div class="text-body-2 font-weight-medium">
                                {{ module.title }}
                              </div>
                              <div class="text-caption text-medium-emphasis">
                                {{ module.desc }}
                              </div>
                            </div>
                            <div class="create-role-dialog__module-actions">
                              <div
                                v-for="action in module.actions"
                                :key="action.id"
                                class="create-role-dialog__permission"
                              >
                                <VCheckbox
                                  v-model="permissionIds"
                                  :value="action.id"
                                  :label="action.label"
                                  :disabled="props.loading || action.assignable === false"
                                  :title="action.assignable === false ? 'Permission ngoài phạm vi cấp của tài khoản hiện tại' : undefined"
                                  density="compact"
                                  hide-details
                                />
                              </div>
                            </div>
                          </div>
                        </div>
                      </VExpandTransition>
                    </VCardText>
                  </VCard>
                </div>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>

        <template #footer>
          <VCardActions class="justify-end">
            <VBtn
              variant="tonal"
              color="secondary"
              :disabled="props.loading"
              @click="close"
            >
              Hủy
            </VBtn>
            <VBtn
              type="submit"
              color="primary"
              prepend-icon="tabler-shield-plus"
              :loading="props.loading"
              :disabled="props.loading || !props.modules.length"
              @click="submit"
            >
              Tạo vai trò
            </VBtn>
          </VCardActions>
        </template>
      </AppDialogLayout>
    </VDialog>
  </div>
</template>

<style scoped>
#view-moi {
  min-inline-size: 0;
}

.create-role-dialog__body {
  min-inline-size: 0;
}

.create-role-dialog__stat {
  border-color: rgba(var(--v-border-color), var(--v-border-opacity));
}

.create-role-dialog__permissions {
  display: flex;
  flex-direction: column;
  gap: 12px;
  max-block-size: 430px;
  overflow: auto;
  padding-inline-end: 4px;
}

.create-role-dialog__group {
  flex: 0 0 auto;
  border-color: rgba(var(--v-border-color), var(--v-border-opacity));
  transition: border-color 0.2s ease, background-color 0.2s ease;
}

.create-role-dialog__group:hover {
  border-color: rgba(var(--v-theme-primary), 0.45);
  background-color: rgba(var(--v-theme-primary), 0.03);
}

.create-role-dialog__group-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.create-role-dialog__group-controls {
  display: flex;
  flex: 0 0 auto;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
}

.create-role-dialog__group-content {
  margin-block-start: 12px;
  border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  padding-block-start: 8px;
}

.create-role-dialog__module-row {
  display: grid;
  grid-template-columns: minmax(145px, 0.72fr) minmax(0, 1.8fr);
  align-items: center;
  gap: 12px;
  padding-block: 8px;
  border-block-end: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.create-role-dialog__module-row:last-child {
  border-block-end: 0;
}

.create-role-dialog__module-meta,
.create-role-dialog__module-actions {
  min-inline-size: 0;
}

.create-role-dialog__module-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  column-gap: 12px;
  row-gap: 2px;
}

.create-role-dialog__permission {
  flex: 0 0 auto;
  min-inline-size: 0;
}

@media (max-width: 959px) {
  .create-role-dialog__permissions {
    max-block-size: none;
  }
}

@media (max-width: 599px) {
  .create-role-dialog__group-header {
    align-items: flex-start;
    flex-direction: column;
  }

  .create-role-dialog__group-controls {
    inline-size: 100%;
    justify-content: space-between;
  }

  .create-role-dialog__module-row {
    grid-template-columns: 1fr;
  }
}
</style>
