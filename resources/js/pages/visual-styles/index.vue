<!--
  =====================================================================
  CHỨC NĂNG FILE: Danh sách Visual Style (grid card + lifecycle + số recipes).
  =====================================================================
  Trang index của Visual Style Engine: tìm kiếm/lọc status, phân trang server,
  tạo style từ ảnh (điều hướng Prompt tạo ảnh hoặc dialog analyze → queue AI), xem/sửa
  (điều hướng trang Edit), nhân bản, lưu trữ và xóa. Reload khi AI task ready.
  Backend `/api/admin/ai/visual-styles` trả VisualStyleResource + meta.pagination.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - load(): gọi list() với search/status/page; bắt lỗi vào `error`.
  - openCreate(): điều hướng sang trang Prompt tạo ảnh để tạo style từ ảnh.
  - openAnalyze()/submitAnalyze(): queue phân tích ảnh → style queued.
  - openEdit()/submitEdit(): mở dialog sửa metadata (name/category/is_enabled) + PUT update.
  - confirmAction()/runAction(): confirm dialog rồi gọi archive/duplicate/remove.
  - watcher page/search/status: reload danh sách khi filter đổi.
  - onMounted/onScopeDispose: load lần đầu + lắng nghe AI_TASK_READY_EVENT.
  - props/emits: không có; page tự quản lý state + API qua service.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : thao tác tìm kiếm/lọc/phân trang, submit dialog tạo/analyze.
  - OUTPUT: grid card style, snackbar, confirm dialog, điều hướng Edit.
  - SIDE EFFECT: GET/POST/PUT/DELETE `/admin/ai/visual-styles`; listener task ready.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Query keys follow the Laravel Visual Style API contract. */
import { onMounted, onScopeDispose, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { visualStylesService } from '@/services/visualStyles'
import { AI_TASK_READY_EVENT } from '@/composables/useAiTaskQueue'
import VisualStyleCard from '@/views/visual-styles/VisualStyleCard.vue'
import StyleAnalyzeDialog from '@/views/visual-styles/StyleAnalyzeDialog.vue'
import VisualStyleEditDialog from '@/views/visual-styles/VisualStyleEditDialog.vue'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'

definePage({ meta: { action: 'manage', subject: 'ai_settings' } })

const router = useRouter()

// State list + filter
const items = ref([])
const pagination = ref(null)
const page = ref(1)
const perPage = ref(12)
const search = ref('')
const status = ref('')
const loading = ref(false)
const error = ref('')

// Snackbar + dialog state
const snackbar = ref({ show: false, text: '', color: 'success' })
const analyzeOpen = ref(false)
const confirmOpen = ref(false)
const confirmText = ref('')
const pendingAction = ref(null)
const saving = ref(false)

// Dialog chỉnh sửa style
const editOpen = ref(false)
const editStyle = ref(null)
const editError = ref('')

const STATUS_OPTIONS = [
  { title: 'Tất cả', value: '' },
  { title: 'Nháp', value: 'draft' },
  { title: 'Chờ phân tích', value: 'queued' },
  { title: 'Đang phân tích', value: 'analyzing' },
  { title: 'Đã phân tích', value: 'analyzed' },
  { title: 'Hoạt động', value: 'active' },
  { title: 'Đã lưu trữ', value: 'archived' },
  { title: 'Lỗi', value: 'failed' },
]

function showSnackbar(text, color = 'success') {
  snackbar.value = { show: true, text, color }
}

// =====================================================================
// Input: filter hiện tại. Output: items + pagination từ API.
// Side effect: GET list; error ghi vào `error`.
// =====================================================================
async function load() {
  loading.value = true
  error.value = ''
  try {
    const result = await visualStylesService.list({
      search: search.value || undefined,
      status: status.value || undefined,
      page: page.value,
      per_page: perPage.value,
    })

    items.value = result.items ?? []
    pagination.value = result.pagination ?? null
  }
  catch (reason) {
    error.value = reason?.data?.message ?? 'Không tải được danh sách style.'
  }
  finally {
    loading.value = false
  }
}

// =====================================================================
// Input: không có. Output: điều hướng sang trang Prompt tạo ảnh để tạo từ ảnh.
// =====================================================================
function openCreate() {
  router.push({ name: 'ai-image-prompt' })
}

// =====================================================================
// Input: payload multipart từ StyleAnalyzeDialog. Output: style queued; reload.
// Side effect: POST analyze (multipart); lỗi đưa vào analyzeError trong dialog.
// =====================================================================
const analyzeError = ref('')

async function submitAnalyze(payload) {
  saving.value = true
  analyzeError.value = ''
  try {
    await visualStylesService.analyze(payload)
    analyzeOpen.value = false
    showSnackbar('Đã xếp hàng phân tích phong cách. Theo dõi trong trung tâm tác vụ AI.')
    await load()
  }
  catch (reason) {
    analyzeError.value = reason?.data?.message ?? 'Phân tích thất bại.'
  }
  finally {
    saving.value = false
  }
}

// =====================================================================
// Input: style. Output: mở dialog chỉnh sửa metadata.
// =====================================================================
function openEdit(style) {
  editStyle.value = style
  editError.value = ''
  editOpen.value = true
}

// =====================================================================
// Input: payload {id,name,category,is_enabled}. Output: style đã lưu + reload.
// Side effect: PUT update; lỗi → editError trong dialog.
// =====================================================================
async function submitEdit(payload) {
  saving.value = true
  editError.value = ''
  try {
    await visualStylesService.update(payload.id, {
      name: payload.name,
      category: payload.category,
      is_enabled: payload.is_enabled,
    })
    editOpen.value = false
    showSnackbar('Đã lưu style.')
    await load()
  }
  catch (reason) {
    editError.value = reason?.data?.message ?? 'Lưu style thất bại.'
  }
  finally {
    saving.value = false
  }
}

// =====================================================================
// Input: kind + style. Output: confirm text + action pending.
// =====================================================================
function confirmAction(kind, style) {
  const labels = {
    archive: `Lưu trữ “${style.name}”? Style sẽ không dùng cho generation.`,
    duplicate: `Nhân bản “${style.name}” sang bản nháp mới?`,
    remove: `Xóa “${style.name}”? Shot recipes liên quan cũng bị xóa.`,
  }

  confirmText.value = labels[kind] ?? ''
  pendingAction.value = { kind, style }
  confirmOpen.value = true
}

// =====================================================================
// Input: action pending. Output: gọi API tương ứng; reload + snackbar.
// Side effect: POST archive/duplicate hoặc DELETE; lỗi → snackbar error.
// =====================================================================
async function runAction() {
  const action = pendingAction.value
  if (!action) return
  saving.value = true
  try {
    if (action.kind === 'archive')
      await visualStylesService.archive(action.style.id)
    else if (action.kind === 'duplicate')
      await visualStylesService.duplicate(action.style.id)
    else if (action.kind === 'remove')
      await visualStylesService.remove(action.style.id)
    showSnackbar('Thao tác thành công.')
    await load()
  }
  catch (reason) {
    showSnackbar(reason?.data?.message ?? 'Thao tác thất bại.', 'error')
  }
  finally {
    saving.value = false
    pendingAction.value = null
  }
}

// Đổi search/status reset về trang 1; watcher page lo load — tránh load kép
watch([search, status], () => {
  page.value = 1
})

watch(page, () => load())

function handleTaskReady() {
  void load()
}

onMounted(() => {
  void load()
  window.addEventListener(AI_TASK_READY_EVENT, handleTaskReady)
})

onScopeDispose(() => {
  if (typeof window !== 'undefined')
    window.removeEventListener(AI_TASK_READY_EVENT, handleTaskReady)
})
</script>

<template>
  <div class="visual-styles-page">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-space-between gap-y-4 gap-x-6 mb-6">
      <div>
        <h4 class="text-h4 font-weight-medium">
          Visual Styles
        </h4>
        <div class="text-body-1">
          Thư viện phong cách ảnh: Style DNA + Shot Recipes dùng lại cho generation.
        </div>
      </div>
      <div class="d-flex flex-wrap align-center gap-3">
        <VBtn
          color="secondary"
          variant="tonal"
          prepend-icon="tabler-refresh"
          :loading="loading"
          :disabled="loading"
          @click="load"
        >
          Tải lại
        </VBtn>
        <VBtn
          color="secondary"
          variant="tonal"
          prepend-icon="tabler-photo-plus"
          @click="analyzeOpen = true"
        >
          Tạo từ ảnh
        </VBtn>
        <VBtn
          color="primary"
          prepend-icon="tabler-plus"
          @click="openCreate"
        >
          Tạo style mới
        </VBtn>
      </div>
    </div>

    <!-- Filter -->
    <VCard class="mb-6">
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            sm="6"
            md="4"
          >
            <AppTextField
              v-model="search"
              label="Tìm kiếm"
              placeholder="Tên hoặc nhóm style..."
              prepend-inner-icon="tabler-search"
              clearable
            />
          </VCol>
          <VCol
            cols="12"
            sm="6"
            md="3"
          >
            <AppSelect
              v-model="status"
              :items="STATUS_OPTIONS"
              label="Trạng thái"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Error -->
    <VAlert
      v-if="error"
      type="error"
      variant="tonal"
      class="mb-6"
    >
      {{ error }}
      <VBtn
        size="small"
        variant="text"
        class="ms-2"
        @click="load"
      >
        Thử lại
      </VBtn>
    </VAlert>

    <!-- Loading -->
    <VRow v-if="loading && !items.length">
      <VCol
        v-for="n in 6"
        :key="n"
        cols="12"
        sm="6"
        md="4"
      >
        <VSkeletonLoader type="card" />
      </VCol>
    </VRow>

    <!-- Empty -->
    <VCard
      v-else-if="!items.length && !error"
      class="pa-10 text-center"
    >
      <VIcon
        size="56"
        icon="tabler-photo-off"
        class="text-medium-emphasis mb-3"
      />
      <div class="text-subtitle-1 font-weight-medium mb-1">
        {{ search || status ? 'Không tìm thấy style phù hợp.' : 'Chưa có visual style nào.' }}
      </div>
      <div class="text-body-2 text-medium-emphasis mb-4">
        Tạo style thủ công hoặc tải ảnh mẫu để AI phân tích thành Style DNA.
      </div>
      <div class="d-flex justify-center gap-3">
        <VBtn
          color="secondary"
          variant="tonal"
          @click="analyzeOpen = true"
        >
          Tạo từ ảnh
        </VBtn>
        <VBtn
          color="primary"
          @click="openCreate"
        >
          Tạo style mới
        </VBtn>
      </div>
    </VCard>

    <!-- Grid -->
    <VRow v-else>
      <VCol
        v-for="style in items"
        :key="style.id"
        cols="12"
        sm="6"
        md="4"
      >
        <VisualStyleCard
          :style="style"
          :busy="saving"
          @edit="openEdit"
          @duplicate="confirmAction('duplicate', $event)"
          @archive="confirmAction('archive', $event)"
          @remove="confirmAction('remove', $event)"
        />
      </VCol>
    </VRow>

    <!-- Pagination -->
    <div
      v-if="pagination && pagination.last_page > 1"
      class="d-flex justify-center mt-6"
    >
      <VPagination
        v-model="page"
        :length="pagination.last_page"
        :disabled="loading"
      />
    </div>

    <!-- Dialog phân tích ảnh -->
    <StyleAnalyzeDialog
      v-model="analyzeOpen"
      :saving="saving"
      :error-message="analyzeError"
      @submit="submitAnalyze"
      @after-leave="() => {}"
    />

    <!-- Dialog chỉnh sửa style -->
    <VisualStyleEditDialog
      v-model="editOpen"
      :style="editStyle"
      :saving="saving"
      :error-message="editError"
      @save="submitEdit"
      @after-leave="editStyle = null"
    />

    <!-- Confirm action -->
    <VDialog
      v-model="confirmOpen"
      max-width="440"
      scrollable
      :persistent="saving"
    >
      <AppDialogLayout
        title="Xác nhận"
        :close-disabled="saving"
        @close="confirmOpen = false"
      >
        <VCardText>
          <div class="text-body-1">
            {{ confirmText }}
          </div>
        </VCardText>
        <template #footer>
          <VCardActions class="justify-end">
            <VBtn
              color="secondary"
              variant="tonal"
              :disabled="saving"
              @click="confirmOpen = false"
            >
              Hủy
            </VBtn>
            <VBtn
              color="primary"
              :loading="saving"
              :disabled="saving"
              @click="runAction(); confirmOpen = false"
            >
              Xác nhận
            </VBtn>
          </VCardActions>
        </template>
      </AppDialogLayout>
    </VDialog>

    <!-- Snackbar -->
    <VSnackbar
      v-model="snackbar.show"
      :color="snackbar.color"
      location="bottom end"
      :timeout="3200"
    >
      {{ snackbar.text }}
    </VSnackbar>
  </div>
</template>

<style scoped>
.visual-styles-page {
  min-inline-size: 0;
}
</style>
