<!--
  =====================================================================
  CHỨC NĂNG FILE: Trình bày chi tiết vai trò và ma trận quyền theo theme.
  =====================================================================
  Panel giữ bố cục của chủ dự án, checkbox có label đầy đủ và quyền đi qua
  model permissionIds. Dữ liệu role/user và cờ thao tác lấy từ API thật.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - permissionGroups, allPermissionIds, selectedCount, allSelected: tổng quyền theo bảy nhóm.
  - visibleGroups, assignedUsers: lọc nhóm quyền và user theo role.
  - toggleAll(), toggleGroup(), toggleGroupExpansion(): chọn và mở/thu gọn nhóm.
  - watcher role.id/search: reset trạng thái nhóm và tìm kiếm.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : role, modules/groups bảy nhóm, users, isDirty, saving và model permissionIds từ page.
  - OUTPUT: tabs/ma trận/user list; emit save/reset, không gọi API trực tiếp.
  =====================================================================
-->
<script setup>
import { computed, shallowRef, watch } from 'vue'

const props = defineProps({
  role: { type: Object, required: true },
  modules: { type: Array, required: true },
  groups: { type: Array, default: () => [] },
  users: { type: Array, default: () => [] },
  isDirty: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
})

const emit = defineEmits(['save', 'reset'])
const permissionIds = defineModel('permissionIds', { type: Array, required: true })
const activeTab = shallowRef('permissions')
const search = shallowRef('')
const expandedGroupIds = shallowRef([])

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

const selectedCount = computed(() => permissionIds.value.length)

const allSelected = computed(() => allAssignablePermissionIds.value.length > 0
  && allAssignablePermissionIds.value.every(id => permissionIds.value.includes(id)))

const partiallySelected = computed(() => allAssignablePermissionIds.value.some(id => permissionIds.value.includes(id)) && !allSelected.value)

const assignedUsers = computed(() => props.users.filter(user => (user.roles ?? []).some(item => Number(item.id) === Number(props.role.id))))

const visibleGroups = computed(() => {
  const query = (search.value || '').trim().toLocaleLowerCase('vi')

  return permissionGroups.value.map(group => {
    const groupText = `${group.title} ${group.desc}`.toLocaleLowerCase('vi')
    const groupMatches = !query || groupText.includes(query)

    const visibleModules = group.modules.map(module => {
      const moduleText = `${module.title} ${module.desc}`.toLocaleLowerCase('vi')
      const moduleMatches = groupMatches || !query || moduleText.includes(query)

      const actions = module.actions.filter(action => moduleMatches
        || `${action.label} ${action.name}`.toLocaleLowerCase('vi').includes(query))

      return { ...module, actions }
    }).filter(module => module.actions.length > 0)

    return { ...group, visibleModules }
  }).filter(group => group.visibleModules.length > 0 || !query)
})

/**
 * Chọn hoặc bỏ toàn bộ quyền trong ma trận mẫu, kể cả nhóm đang bị lọc.
 * INPUT: selected là trạng thái checkbox chọn tất cả.
 * OUTPUT: cập nhật model permissionIds bằng mảng mới.
 * SIDE EFFECT: emit model về page; không ghi dữ liệu backend.
 */
const toggleAll = selected => { permissionIds.value = selected ? [...allAssignablePermissionIds.value] : [] }

/**
 * Lấy permission ID và trạng thái chọn của từng nhóm bảy nhóm quyền.
 * INPUT: group đầy đủ từ mapPermissionGroups().
 * OUTPUT: danh sách ID hoặc trạng thái checkbox nhóm.
 * SIDE EFFECT: Không gọi API hoặc ghi dữ liệu.
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
 * Mở hoặc thu gọn một nhóm quyền trên panel role.
 * INPUT: groupId của nhóm được bấm.
 * OUTPUT: danh sách nhóm đang mở.
 * SIDE EFFECT: Cập nhật state hiển thị nội bộ, không đổi permission draft.
 */
const toggleGroupExpansion = groupId => {
  expandedGroupIds.value = expandedGroupIds.value.includes(groupId)
    ? expandedGroupIds.value.filter(id => id !== groupId)
    : [...expandedGroupIds.value, groupId]
}

/**
 * Chọn hoặc bỏ toàn bộ permission có thể cấp trong một nhóm.
 * INPUT: group và trạng thái checkbox nhóm.
 * OUTPUT: permissionIds draft sau khi đồng bộ nhóm.
 * SIDE EFFECT: Cập nhật model permissionIds, không gọi API.
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

// INPUT: ID vai trò đổi. OUTPUT: tab quyền và từ khóa rỗng; không sửa quyền.
watch(() => props.role.id, () => {
  activeTab.value = 'permissions'
  search.value = ''
  expandedGroupIds.value = []
})

watch(search, query => {
  expandedGroupIds.value = query.trim()
    ? visibleGroups.value.map(group => group.id)
    : []
})
</script>

<template>
  <VCard>
    <VCardText class="d-flex flex-wrap align-start justify-space-between gap-4">
      <div class="d-flex align-center gap-3 role-permissions__identity">
        <VAvatar
          :color="role.color"
          variant="tonal"
          rounded
          size="48"
        >
          <VIcon
            :icon="role.icon"
            size="28"
          />
        </VAvatar>
        <div>
          <div class="d-flex align-center flex-wrap gap-2">
            <h5 class="text-h5">
              {{ role.label || role.name }}
            </h5>
            <VChip
              :color="role.active ? 'success' : 'secondary'"
              size="small"
            >
              {{ role.status }}
            </VChip>
          </div>
          <p class="text-body-2 text-medium-emphasis mb-0 mt-1">
            {{ role.fullDesc }}
          </p>
        </div>
      </div>
      <div class="d-flex flex-wrap align-center gap-2">
        <VBtn
          variant="tonal"
          color="secondary"
          size="small"
          prepend-icon="tabler-user-plus"
          disabled
          title="Tính năng gán role sẽ nối ở luồng quản lý user"
        >
          Gán người dùng
        </VBtn>
        <VBtn
          variant="tonal"
          color="secondary"
          size="small"
          prepend-icon="tabler-copy"
          class="role-permissions__duplicate"
          aria-label="Nhân bản vai trò"
          disabled
          title="Tính năng nhân bản sẽ bổ sung sau"
        >
          <span class="d-none d-sm-inline">Nhân bản</span>
        </VBtn>
        <VBtn
          variant="tonal"
          color="error"
          size="small"
          icon="tabler-trash"
          disabled
          aria-label="Xóa vai trò"
          title="Luồng xóa vai trò sẽ bổ sung sau"
        />
      </div>
    </VCardText>
    <VTabs
      v-model="activeTab"
      class="px-6"
      show-arrows
    >
      <VTab
        value="permissions"
        prepend-icon="tabler-shield-lock"
      >
        Quyền truy cập
      </VTab>
      <VTab
        value="users"
        prepend-icon="tabler-users"
      >
        Người dùng ({{ role.userCount }})
      </VTab>
      <VTab
        value="settings"
        prepend-icon="tabler-settings"
      >
        Cài đặt
      </VTab>
    </VTabs>
    <VDivider />
    <VWindow v-model="activeTab">
      <VWindowItem value="permissions">
        <VCardText>
          <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
            <div>
              <h6 class="text-h6">
                Phân quyền theo module
              </h6>
              <span class="text-body-2 text-medium-emphasis">{{ selectedCount }} / {{ allPermissionIds.length }} quyền được chọn</span>
            </div>
            <VCheckbox
              :model-value="allSelected"
              :indeterminate="partiallySelected"
              label="Chọn tất cả"
              hide-details
              :disabled="!role.canEdit"
              @update:model-value="toggleAll"
            />
          </div>
          <AppTextField
            v-model="search"
            placeholder="Tìm kiếm nhóm, module hoặc quyền..."
            aria-label="Tìm kiếm nhóm, module hoặc quyền"
            prepend-inner-icon="tabler-search"
            clearable
            hide-details
            class="mb-5"
          />
          <div class="d-flex flex-column gap-3">
            <section
              v-for="group in visibleGroups"
              :key="group.id"
              :aria-labelledby="`role-module-${group.id}`"
              class="role-permissions__module"
            >
              <div class="role-permissions__group-header">
                <button
                  type="button"
                  class="role-permissions__group-trigger"
                  :aria-expanded="isGroupExpanded(group.id)"
                  :aria-controls="`role-group-${group.id}`"
                  @click="toggleGroupExpansion(group.id)"
                >
                  <VAvatar
                    color="primary"
                    variant="tonal"
                    rounded
                    size="36"
                  >
                    <VIcon
                      :icon="group.icon"
                      size="21"
                    />
                  </VAvatar>
                  <span class="role-permissions__group-heading">
                    <span
                      :id="`role-module-${group.id}`"
                      class="text-body-1 font-weight-medium"
                    >
                      {{ group.title }}
                    </span>
                    <span class="text-body-2 text-medium-emphasis">{{ group.desc }}</span>
                  </span>
                  <span class="role-permissions__group-count text-body-2 text-medium-emphasis">
                    {{ groupSelectedCount(group) }} / {{ groupPermissionIds(group).length }} quyền
                  </span>
                  <VIcon
                    :icon="isGroupExpanded(group.id) ? 'tabler-chevron-up' : 'tabler-chevron-down'"
                    size="20"
                    class="text-medium-emphasis"
                  />
                </button>
                <VCheckbox
                  :model-value="groupAllSelected(group)"
                  :indeterminate="groupPartiallySelected(group)"
                  :aria-label="`Chọn toàn bộ quyền nhóm ${group.title}`"
                  hide-details
                  :disabled="!role.canEdit"
                  @click.stop
                  @update:model-value="toggleGroup(group, $event)"
                />
              </div>
              <VExpandTransition>
                <div
                  v-show="isGroupExpanded(group.id)"
                  :id="`role-group-${group.id}`"
                  class="role-permissions__group-body"
                >
                  <div
                    v-for="module in group.visibleModules"
                    :key="module.id"
                    class="role-permissions__module-row"
                  >
                    <div class="role-permissions__module-heading">
                      <span class="text-body-2 font-weight-medium">{{ module.title }}</span>
                      <span class="text-caption text-medium-emphasis">{{ module.desc }}</span>
                    </div>
                    <div class="role-permissions__actions">
                      <VCheckbox
                        v-for="action in module.actions"
                        :key="action.id"
                        v-model="permissionIds"
                        :value="action.id"
                        :label="action.label"
                        :aria-label="`${module.title}: ${action.label}`"
                        :disabled="!role.canEdit || action.assignable === false"
                        density="compact"
                        hide-details
                      />
                    </div>
                  </div>
                </div>
              </VExpandTransition>
            </section>
            <div
              v-if="!visibleGroups.length"
              class="text-center py-8"
            >
              <VIcon
                icon="tabler-search-off"
                size="32"
                class="text-disabled mb-3"
              />
              <div class="text-body-1">
                Không tìm thấy nhóm quyền
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
          </div>
        </VCardText>
        <VDivider />
        <VCardText class="d-flex align-center justify-space-between flex-wrap gap-3">
          <span
            class="text-body-2"
            :class="isDirty ? 'text-warning' : 'text-medium-emphasis'"
            aria-live="polite"
          >{{ isDirty ? 'Có thay đổi chưa lưu' : (role.canEdit ? 'Quyền đã đồng bộ với máy chủ.' : 'Vai trò này chỉ được xem.') }}</span>
          <div class="d-flex flex-wrap gap-3">
            <VBtn
              variant="tonal"
              color="secondary"
              :disabled="!isDirty || !role.canEdit || saving"
              @click="emit('reset')"
            >
              Hủy bỏ
            </VBtn>
            <VBtn
              prepend-icon="tabler-device-floppy"
              :disabled="!isDirty || !role.canEdit || saving"
              :loading="saving"
              @click="emit('save')"
            >
              Lưu thay đổi
            </VBtn>
          </div>
        </VCardText>
      </VWindowItem>
      <VWindowItem value="users">
        <VCardText>
          <div class="d-flex align-center justify-space-between gap-3 mb-4">
            <div>
              <h6 class="text-h6">
                Người dùng đang sử dụng role
              </h6>
              <p class="text-body-2 text-medium-emphasis mb-0">
                {{ assignedUsers.length }} tài khoản được tải từ hệ thống.
              </p>
            </div>
            <VChip
              color="primary"
              variant="tonal"
              size="small"
            >
              {{ role.userCount }} tổng cộng
            </VChip>
          </div>
          <VList
            v-if="assignedUsers.length"
            class="pa-0"
            lines="two"
          >
            <VListItem
              v-for="user in assignedUsers"
              :key="user.id"
              :title="user.name"
              :subtitle="user.email"
              class="px-0"
            >
              <template #prepend>
                <VAvatar
                  color="primary"
                  variant="tonal"
                  size="36"
                >
                  {{ (user.name || user.email || '?').slice(0, 1).toUpperCase() }}
                </VAvatar>
              </template>
              <template #append>
                <VChip
                  :color="user.status === 'active' ? 'success' : 'secondary'"
                  size="x-small"
                >
                  {{ user.status === 'active' ? 'Hoạt động' : user.status }}
                </VChip>
              </template>
            </VListItem>
          </VList>
          <VAlert
            v-else
            color="secondary"
            variant="tonal"
            icon="tabler-users-off"
          >
            Chưa có tài khoản nào trong dữ liệu đã tải cho role này.
          </VAlert>
        </VCardText>
      </VWindowItem>
      <VWindowItem value="settings">
        <VCardText>
          <VRow>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                :model-value="role.name"
                label="Tên vai trò"
                readonly
                hide-details
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                :model-value="role.status"
                label="Trạng thái"
                readonly
                hide-details
              />
            </VCol>
            <VCol cols="12">
              <AppTextarea
                :model-value="role.fullDesc"
                label="Mô tả vai trò"
                rows="3"
                auto-grow
                readonly
                hide-details
              />
            </VCol>
          </VRow>
        </VCardText>
      </VWindowItem>
    </VWindow>
  </VCard>
</template>

<style scoped>
.role-permissions__identity {
  flex: 1 1 300px;
  min-inline-size: 0;
}

.role-permissions__module {
  display: flex;
  flex-direction: column;
  gap: 0;
  padding: 0;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
  background: rgb(var(--v-theme-surface));
}

.role-permissions__group-header {
  display: flex;
  align-items: center;
  gap: 10px;
  min-block-size: 68px;
  padding: 12px 16px;
}

.role-permissions__group-trigger {
  display: flex;
  align-items: center;
  gap: 12px;
  flex: 1 1 auto;
  min-inline-size: 0;
  padding: 0;
  text-align: start;
}

.role-permissions__group-trigger:focus-visible {
  border-radius: 4px;
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 3px;
}

.role-permissions__group-heading {
  display: flex;
  flex-direction: column;
  min-inline-size: 0;
  gap: 2px;
}

.role-permissions__group-heading > span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.role-permissions__group-count {
  flex: 0 0 auto;
  white-space: nowrap;
}

.role-permissions__group-body {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 0 16px 16px;
}

.role-permissions__module-row {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  padding: 12px;
  border-radius: 4px;
  background: rgba(var(--v-theme-on-surface), 0.03);
}

.role-permissions__module-heading {
  display: flex;
  flex: 0 0 170px;
  flex-direction: column;
  min-inline-size: 0;
  gap: 2px;
}

.role-permissions__module-heading span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.role-permissions__actions {
  display: flex;
  flex: 1 1 auto;
  flex-wrap: wrap;
  gap: 4px 20px;
}

.role-permissions__actions :deep(.v-label) {
  font-size: 0.875rem;
}

@media (max-width: 599px) {
  .role-permissions__duplicate {
    min-inline-size: 32px;
    padding-inline: 8px;
  }

  .role-permissions__duplicate :deep(.v-btn__prepend) {
    margin-inline: 0;
  }

  .role-permissions__actions {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 4px 8px;
  }

  .role-permissions__group-header {
    padding-inline: 12px;
  }

  .role-permissions__group-body {
    padding-inline: 12px;
  }

  .role-permissions__module-row {
    flex-direction: column;
    gap: 8px;
    padding: 10px;
  }

  .role-permissions__module-heading {
    flex-basis: auto;
  }
}
</style>
