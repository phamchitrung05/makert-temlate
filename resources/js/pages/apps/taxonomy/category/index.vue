<!--
  =====================================================================
  CHỨC NĂNG FILE: Trang quản lý Category trong Taxonomy
  =====================================================================

  Page giữ vai trò composition surface cho KPI, form Category và cây danh mục.
  Dữ liệu được tải từ Category API; service riêng chuẩn hóa envelope và payload.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - statCards(): tính các số liệu hiển thị ở nhóm KPI.
  - parentOptions(): tạo lựa chọn Category cha từ cây hiện tại.
  - useSlug()/generateSlug(): preview slug từ tên Category qua API dùng chung.
  - loadCategories(): tải danh sách Category và dựng cây từ parent_id.
  - resetForm()/saveCategory(): reset và gọi create/update API.
  - editCategory()/deleteCategory(): tải chi tiết, cập nhật hoặc xóa Category.
  - toggleExpand(): đóng/mở một nhánh Category.
  - filteredTree()/flattenedTree(): lọc, sắp xếp và chuyển cây thành hàng bảng.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : Thao tác tìm kiếm, sắp xếp, form và Bearer token admin.
  - OUTPUT: Giao diện Category tại route apps-taxonomy-category.
  =====================================================================
-->
<script setup>
import { computed, onMounted, reactive, ref, shallowRef } from 'vue'
import { useSlug } from '@/composables/useSlug'
import { categoryService } from '@/services/category'
import MediaAssetField from '@/views/apps/media/field/MediaAssetField.vue'

const searchQuery = shallowRef('')
const sortOrder = shallowRef('Sắp xếp: Thứ tự tăng dần')
const categoryNotice = shallowRef('')
const categoryNoticeColor = shallowRef('info')
const isNoticeVisible = shallowRef(false)
const categoryError = shallowRef('')
const isLoading = shallowRef(false)
const isSaving = shallowRef(false)
const loadingCategoryId = shallowRef(null)
const deletingCategoryId = shallowRef(null)
const editingCategoryId = shallowRef(null)
const publicOrigin = computed(() => String(import.meta.env.VITE_PUBLIC_URL || window.location.origin).replace(/\/+$/, ''))
const slugPrefix = computed(() => `${publicOrigin.value}/`)

const defaultForm = () => ({
  name: '',
  parent: null,
  description: '',
  thumbnail: null,
  thumbnailDirty: false,
  showOnMenu: true,
  isActive: true,
})

const form = reactive(defaultForm())
const isEditing = computed(() => editingCategoryId.value !== null)
const formTitle = computed(() => isEditing.value ? 'Sửa Category' : 'Thêm Category')

const formSubtitle = computed(() => isEditing.value
  ? 'Cập nhật thông tin và cấu trúc danh mục.'
  : 'Tạo danh mục để tổ chức nội dung bài viết.')

const formActionLabel = computed(() => isEditing.value ? 'Lưu thay đổi' : 'Tạo Category')
const formActionIcon = computed(() => isEditing.value ? 'tabler-device-floppy' : 'tabler-plus')

const categoriesTree = ref([])
const categoryColors = ['#7367F0', '#0284c7', '#28c76f', '#ff9f43', '#ea5455']

const sortOptions = [
  'Sắp xếp: Thứ tự tăng dần',
  'Sắp xếp: Thứ tự giảm dần',
  'Sắp xếp: Bài viết nhiều nhất',
]

const normalizeText = value => String(value ?? '')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .toLowerCase()

const collectNodes = nodes => nodes.flatMap(node => [node, ...collectNodes(node.children ?? [])])

const buildTree = items => {
  const nodes = items.map((item, index) => ({
    ...item,
    color: categoryColors[index % categoryColors.length],
    children: [],
    expanded: true,
  }))

  const byId = new Map(nodes.map(node => [node.id, node]))
  const roots = []

  nodes.forEach(node => {
    const parent = byId.get(node.parentId)
    if (parent && parent.id !== node.id)
      parent.children.push(node)
    else
      roots.push(node)
  })

  return roots
}

const allCategories = computed(() => collectNodes(categoriesTree.value))

const statCards = computed(() => [
  {
    title: 'Tổng số category',
    value: allCategories.value.length,
    caption: 'Tất cả danh mục trong hệ thống',
    icon: 'tabler-category',
    color: 'primary',
  },
  {
    title: 'Danh mục gốc',
    value: categoriesTree.value.length,
    caption: 'Danh mục cấp đầu tiên',
    icon: 'tabler-folders',
    color: 'info',
  },
  {
    title: 'Danh mục con',
    value: allCategories.value.length - categoriesTree.value.length,
    caption: 'Danh mục nằm dưới cấp gốc',
    icon: 'tabler-git-branch',
    color: 'success',
  },
  {
    title: 'Danh mục ẩn',
    value: allCategories.value.filter(item => item.status === 'inactive' || !item.showOnMenu).length,
    caption: 'Không hiển thị trên menu',
    icon: 'tabler-eye-off',
    color: 'warning',
  },
])

const parentOptions = computed(() => {
  const currentId = editingCategoryId.value
  const blocked = new Set(currentId ? [currentId] : [])

  if (currentId) {
    allCategories.value.forEach(category => {
      let parentId = category.parentId
      const visited = new Set()

      while (parentId && !visited.has(parentId)) {
        if (parentId === currentId) {
          blocked.add(category.id)
          break
        }
        visited.add(parentId)
        parentId = allCategories.value.find(item => item.id === parentId)?.parentId
      }
    })
  }

  return [
    { title: 'Không có (Danh mục gốc)', value: null },
    ...allCategories.value
      .filter(category => !blocked.has(category.id))
      .map(category => ({ title: category.name, value: category.id })),
  ]
})

const {
  slug: categorySlug,
  loading: slugLoading,
  error: slugError,
  reset: resetSlug,
  generate: generateSlug,
} = useSlug({
  title: () => form.name,
  modelType: 'category',
  modelId: () => editingCategoryId.value,
  modelLabel: 'danh mục',
})

/**
 * Chuẩn hóa lỗi API thành thông báo có thể hiển thị trong page.
 *
 * Input: lỗi ofetch/Laravel.
 * Output: message ưu tiên lỗi validation, sau đó tới message chung.
 */
const apiErrorMessage = error => {
  const payload = error?.data ?? error?.response?._data ?? error?.response?.data ?? {}
  const validationMessage = Object.values(payload.errors ?? {}).flat()[0]

  return validationMessage || payload.message || error?.message || 'Không thể hoàn tất thao tác Category.'
}

/**
 * Hiển thị thông báo thao tác ở góc phải màn hình.
 *
 * Input: message và màu Vuetify.
 * Output: cập nhật snackbar; không gọi API.
 */
const notify = (message, color = 'info') => {
  categoryNotice.value = message
  categoryNoticeColor.value = color
  isNoticeVisible.value = true
}

/**
 * Tải danh sách Category rồi dựng cây theo parent_id từ API.
 *
 * Input: query phân trang mặc định 100 bản ghi.
 * Output: categoriesTree và lỗi tải nếu request thất bại.
 * Side effect: gọi GET /admin/categories.
 */
const loadCategories = async () => {
  isLoading.value = true
  categoryError.value = ''

  try {
    const response = await categoryService.list({ perPage: 100 })

    categoriesTree.value = buildTree(response.items)
  }
  catch (error) {
    categoryError.value = apiErrorMessage(error)
  }
  finally {
    isLoading.value = false
  }
}

/**
 * Đưa form Category về trạng thái tạo mới.
 *
 * Input: không có.
 * Output: form, slug preview và trạng thái edit được reset.
 */
const resetForm = () => {
  Object.assign(form, defaultForm())
  editingCategoryId.value = null
  resetSlug()
}

/**
 * Tải chi tiết một Category và đổ vào form chỉnh sửa.
 *
 * Input: node đang chọn trong cây.
 * Output: form edit và slug preview từ API detail.
 * Side effect: gọi GET /admin/categories/{id}.
 */
const editCategory = async node => {
  if (isSaving.value || loadingCategoryId.value !== null)
    return

  loadingCategoryId.value = node.id

  try {
    const category = await categoryService.show(node.id)

    editingCategoryId.value = category.id
    Object.assign(form, {
      name: category.name,
      parent: category.parentId,
      description: category.description,
      thumbnail: category.thumbnail,
      thumbnailDirty: false,
      showOnMenu: category.showOnMenu,
      isActive: category.status === 'active',
    })
    resetSlug(category)
  }
  catch (error) {
    notify(apiErrorMessage(error), 'error')
  }
  finally {
    loadingCategoryId.value = null
  }
}

/**
 * Tạo mới hoặc cập nhật Category qua service CRUD.
 *
 * Input: form Category hiện tại.
 * Output: tải lại cây và thông báo thành công; lỗi validation hiển thị snackbar.
 * Side effect: POST hoặc PUT /admin/categories.
 */
const saveCategory = async () => {
  if (isSaving.value || !form.name.trim()) {
    notify('Vui lòng nhập tên Category.', 'warning')

    return
  }

  isSaving.value = true

  try {
    const payload = {
      name: form.name,
      parent: form.parent,
      description: form.description,
      thumbnail: form.thumbnail,
      thumbnailDirty: form.thumbnailDirty,
      showOnMenu: form.showOnMenu,
      isActive: form.isActive,
    }

    if (isEditing.value)
      await categoryService.update(editingCategoryId.value, payload)
    else
      await categoryService.create(payload)

    await loadCategories()
    notify(isEditing.value ? 'Đã cập nhật Category.' : 'Đã tạo Category.', 'success')
    resetForm()
  }
  catch (error) {
    notify(apiErrorMessage(error), 'error')
  }
  finally {
    isSaving.value = false
  }
}

/**
 * Xóa mềm một Category sau khi người dùng xác nhận.
 *
 * Input: node Category trong cây.
 * Output: cây tải lại sau khi xóa thành công.
 * Side effect: gọi DELETE /admin/categories/{id}; backend từ chối node còn con.
 */
const deleteCategory = async node => {
  if (isSaving.value || deletingCategoryId.value !== null)
    return

  if (typeof window !== 'undefined' && !window.confirm(`Xóa Category “${node.name}”?`))
    return

  deletingCategoryId.value = node.id

  try {
    await categoryService.remove(node.id)
    if (editingCategoryId.value === node.id)
      resetForm()
    await loadCategories()
    notify('Đã xóa Category.', 'success')
  }
  catch (error) {
    notify(apiErrorMessage(error), 'error')
  }
  finally {
    deletingCategoryId.value = null
  }
}

/**
 * Đóng hoặc mở một nhánh cây Category.
 *
 * Input: ID node cần thay đổi.
 * Output: cập nhật expanded của node trong categoriesTree.
 */
const toggleExpand = id => {
  const findAndToggle = nodes => {
    for (const node of nodes) {
      if (node.id === id) {
        node.expanded = !node.expanded

        return true
      }
      if (findAndToggle(node.children ?? [])) return true
    }

    return false
  }

  findAndToggle(categoriesTree.value)
}

const sortNodes = nodes => [...nodes]
  .sort((first, second) => {
    if (sortOrder.value === 'Sắp xếp: Bài viết nhiều nhất') return second.postCount - first.postCount
    const direction = sortOrder.value === 'Sắp xếp: Thứ tự giảm dần' ? -1 : 1

    return direction * ((first.sortOrder - second.sortOrder) || first.name.localeCompare(second.name, 'vi'))
  })
  .map(node => ({ ...node, children: sortNodes(node.children ?? []) }))

const filterNodes = (nodes, query) => nodes.flatMap(node => {
  const children = filterNodes(node.children ?? [], query)
  const matches = !query || normalizeText(node.name).includes(query)

  if (!matches && children.length === 0) return []

  return [{
    ...node,
    children,
    expanded: query ? children.length > 0 || matches : node.expanded,
  }]
})

const filteredTree = computed(() => filterNodes(sortNodes(categoriesTree.value), normalizeText(searchQuery.value)))

const flattenedTree = computed(() => {
  const result = []

  const flatten = (nodes, level = 0) => {
    for (const node of nodes) {
      result.push({ ...node, level })
      if (node.expanded && node.children?.length) flatten(node.children, level + 1)
    }
  }

  flatten(filteredTree.value)

  return result
})

onMounted(loadCategories)
</script>

<template>
  <div class="category-page">
    <div class="d-flex flex-wrap justify-space-between align-end gap-4 mb-6">
      <div>
        <div class="d-flex align-center gap-2 mb-1">
          <VIcon
            icon="tabler-category"
            color="primary"
            size="28"
          />
          <h1 class="text-h4 font-weight-bold">
            Category
          </h1>
        </div>
        <p class="text-body-1 text-medium-emphasis mb-0">
          Quản lý danh mục, cấu trúc phân cấp và nội dung liên quan.
        </p>
      </div>
    </div>

    <VAlert
      v-if="categoryError"
      type="error"
      variant="tonal"
      class="mb-6"
      closable
      @click:close="categoryError = ''"
    >
      <div class="d-flex flex-wrap align-center justify-space-between gap-3">
        <span>{{ categoryError }}</span>
        <VBtn
          size="small"
          variant="tonal"
          @click="loadCategories"
        >
          Thử lại
        </VBtn>
      </div>
    </VAlert>

    <VRow class="match-height mb-6">
      <VCol
        v-for="stat in statCards"
        :key="stat.title"
        cols="12"
        sm="6"
        lg="3"
      >
        <VCard class="h-100 d-flex flex-column">
          <VCardText class="d-flex align-center gap-4">
            <VAvatar
              :color="stat.color"
              variant="tonal"
              rounded
              size="46"
            >
              <VIcon
                :icon="stat.icon"
                size="24"
              />
            </VAvatar>
            <div class="min-width-0">
              <div class="text-body-2 text-medium-emphasis text-truncate">
                {{ stat.title }}
              </div>
              <div class="text-h5 font-weight-bold">
                {{ stat.value }}
              </div>
              <div class="text-caption text-disabled text-truncate">
                {{ stat.caption }}
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="category-layout">
      <VCol
        cols="12"
        lg="5"
      >
        <VCard class="category-form-card h-100 d-flex flex-column">
          <VCardItem>
            <template #prepend>
              <VAvatar
                color="primary"
                variant="tonal"
                rounded
              >
                <VIcon icon="tabler-plus" />
              </VAvatar>
            </template>
            <VCardTitle>{{ formTitle }}</VCardTitle>
            <VCardSubtitle>{{ formSubtitle }}</VCardSubtitle>
          </VCardItem>

          <VDivider />

          <VForm
            class="category-form d-flex flex-column flex-grow-1"
            @submit.prevent="saveCategory"
          >
            <VCardText class="flex-grow-1">
              <VRow>
                <VCol cols="12">
                  <AppTextField
                    v-model="form.name"
                    label="Tên Category"
                    placeholder="Nhập tên Category"
                    maxlength="100"
                    counter
                    required
                    :disabled="isSaving"
                    @blur="generateSlug"
                  />
                </VCol>
                <VCol cols="12">
                  <AppTextField
                    :model-value="categorySlug"
                    label="Slug"
                    :prefix="slugPrefix"
                    persistent-placeholder
                    placeholder="Slug sẽ được tạo từ tên Category"
                    :loading="slugLoading"
                    hint="Slug được trả về sau khi rời ô Tên Category. Backend sẽ kiểm tra lại khi lưu."
                    persistent-hint
                    readonly
                    :disabled="isSaving"
                  />
                  <VAlert
                    v-if="slugError"
                    type="warning"
                    variant="tonal"
                    class="mt-2"
                    role="alert"
                  >
                    {{ slugError }}
                  </VAlert>
                </VCol>
                <VCol cols="12">
                  <VSelect
                    v-model="form.parent"
                    :items="parentOptions"
                    label="Danh mục cha"
                    clearable
                    :disabled="isSaving"
                  />
                </VCol>
                <VCol cols="12">
                  <VTextarea
                    v-model="form.description"
                    label="Mô tả ngắn"
                    placeholder="Nhập mô tả ngắn về Category"
                    rows="3"
                    maxlength="255"
                    counter
                    :disabled="isSaving"
                  />
                </VCol>
                <VCol cols="12">
                  <MediaAssetField
                    v-model="form.thumbnail"
                    field="category.thumbnail"
                    kind="image"
                    visibility="public"
                    label="Ảnh đại diện"
                    :disabled="isSaving"
                    @update:model-value="form.thumbnailDirty = true"
                  />
                </VCol>
                <VCol cols="12">
                  <div class="d-flex flex-wrap justify-space-between gap-4">
                    <VSwitch
                      v-model="form.showOnMenu"
                      label="Hiển thị trên menu"
                      color="primary"
                      hide-details
                      :disabled="isSaving"
                    />
                    <VSwitch
                      v-model="form.isActive"
                      label="Đang hoạt động"
                      color="primary"
                      hide-details
                      :disabled="isSaving"
                    />
                  </div>
                </VCol>
              </VRow>
            </VCardText>

            <VDivider />

            <VCardActions class="justify-end gap-3 px-6 pt-4 pb-6 mt-auto">
              <VBtn
                variant="tonal"
                color="secondary"
                prepend-icon="tabler-refresh"
                :disabled="isSaving"
                @click="resetForm"
              >
                Làm mới
              </VBtn>
              <VBtn
                type="submit"
                color="primary"
                :prepend-icon="formActionIcon"
                :loading="isSaving"
                :disabled="isSaving"
              >
                {{ formActionLabel }}
              </VBtn>
            </VCardActions>
          </VForm>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        lg="7"
      >
        <VCard class="category-tree-card h-100 d-flex flex-column">
          <VCardItem>
            <template #prepend>
              <VAvatar
                color="primary"
                variant="tonal"
                rounded
              >
                <VIcon icon="tabler-list-tree" />
              </VAvatar>
            </template>
            <VCardTitle>Cây Category</VCardTitle>
            <VCardSubtitle>Xem, tìm kiếm và quản lý cấu trúc danh mục.</VCardSubtitle>
          </VCardItem>

          <VDivider />

          <VCardText class="pb-0">
            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="searchQuery"
                  prepend-inner-icon="tabler-search"
                  placeholder="Tìm kiếm Category"
                  clearable
                  hide-details
                />
              </VCol>
              <VCol
                cols="12"
                md="6"
              >
                <AppSelect
                  v-model="sortOrder"
                  :items="sortOptions"
                  hide-details
                />
              </VCol>
            </VRow>
            <VProgressLinear
              v-if="isLoading"
              indeterminate
              color="primary"
              class="mt-4"
            />
            <VRow>
              <div class="category-tree-scroll mt-4">
                <VTable class="category-tree-table text-no-wrap">
                  <thead>
                    <tr>
                      <th>Tên Category</th>
                      <th class="text-center">
                        Bài viết
                      </th>
                      <th class="text-center">
                        Trạng thái
                      </th>
                      <th class="text-end">
                        Thao tác
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <template v-if="flattenedTree.length">
                      <tr
                        v-for="item in flattenedTree"
                        :key="item.id"
                        class="category-tree-row"
                      >
                        <td>
                          <div
                            class="d-flex align-center py-2"
                            :style="{ paddingInlineStart: `${item.level * 24}px` }"
                          >
                            <IconBtn
                              v-if="item.children?.length"
                              size="small"
                              @click.stop="toggleExpand(item.id)"
                            >
                              <VIcon
                                :icon="item.expanded ? 'tabler-chevron-down' : 'tabler-chevron-right'"
                                size="18"
                              />
                            </IconBtn>
                            <span
                              v-else
                              class="tree-toggle-spacer"
                            />
                            <VIcon
                              :icon="item.children?.length ? 'tabler-folder' : 'tabler-folder-open'"
                              :color="item.color || 'primary'"
                              size="20"
                              class="me-2"
                            />
                            <span class="text-body-2 font-weight-medium">
                              {{ item.name }}
                            </span>
                          </div>
                        </td>
                        <td class="text-center text-body-2">
                          {{ item.postCount.toLocaleString() }}
                        </td>
                        <td class="text-center">
                          <VChip
                            :color="item.status === 'active' && item.showOnMenu ? 'success' : 'warning'"
                            size="small"
                            variant="tonal"
                          >
                            {{ item.status === 'active' && item.showOnMenu ? 'Hiển thị' : 'Ẩn' }}
                          </VChip>
                        </td>
                        <td class="text-end">
                          <IconBtn
                            :disabled="isSaving || loadingCategoryId === item.id || deletingCategoryId !== null"
                            @click="editCategory(item)"
                          >
                            <VIcon
                              icon="tabler-pencil"
                              size="20"
                            />
                          </IconBtn>
                          <IconBtn
                            color="error"
                            :loading="deletingCategoryId === item.id"
                            :disabled="isSaving || deletingCategoryId !== null || loadingCategoryId !== null"
                            @click="deleteCategory(item)"
                          >
                            <VIcon
                              icon="tabler-trash"
                              size="20"
                            />
                          </IconBtn>
                        </td>
                      </tr>
                    </template>
                    <tr v-else>
                      <td
                        colspan="4"
                        class="pa-6"
                      >
                        <VAlert
                          color="info"
                          variant="tonal"
                        >
                          Không tìm thấy Category phù hợp.
                        </VAlert>
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </div>
            </VRow>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VSnackbar
      v-model="isNoticeVisible"
      :color="categoryNoticeColor"
      location="top end"
    >
      {{ categoryNotice }}
    </VSnackbar>
  </div>
</template>

<style scoped lang="scss">
.category-page {
  min-block-size: 100%;
}

.min-width-0 {
  min-inline-size: 0;
}

.category-layout {
  align-items: stretch;

  > :deep(.v-col) {
    min-block-size: 0;
  }
}

.category-form-card,
.category-tree-card {
  min-block-size: 0;
}

.category-tree-card {
  overflow: hidden;
}

.category-tree-scroll {
  min-block-size: 0;
  flex: 1 1 0;
  overflow: auto;
}

.category-tree-table {
  display: block;
  flex: none;

  :deep(.v-table__wrapper) {
    overflow: visible;
  }

  th {
    color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    white-space: nowrap;
  }
}

.category-tree-row {
  transition: background-color 0.15s ease;

  &:hover {
    background-color: rgba(var(--v-theme-primary), 0.04);
  }
}

.tree-toggle-spacer {
  display: inline-block;
  block-size: 32px;
  inline-size: 32px;
}
</style>
