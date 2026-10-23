<!--
  =====================================================================
  CHỨC NĂNG FILE: Danh sách Visual Style (grid card + lifecycle + số recipes).
  =====================================================================
  Trang index của Visual Style Engine: tìm kiếm/lọc status, phân trang server,
  tạo style (dialog create 2 cột / dialog analyze → queue AI), xem/sửa
  (điều hướng trang Edit), nhân bản, lưu trữ và xóa. Reload khi AI task ready.
  Backend `/api/admin/ai/visual-styles` trả VisualStyleResource + meta.pagination.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - load(): gọi list() với search/status/page; bắt lỗi vào `error`.
  - submitCreate(): queue phân tích từ VisualStyleCreateDialog → giữ dialog
  mở để dialog tự poll kết quả; reload danh sách.
  - openAnalyze()/submitAnalyze(): queue phân tích ảnh → style queued.
  - openEdit()/submitEdit(): mở dialog sửa metadata (name/category/is_enabled) + PUT update.
  - confirmAction()/runAction(): confirm dialog rồi gọi remove.
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
import { storeToRefs } from 'pinia'
import { useVisualStyleStore } from '@/stores/visualStyle'
import { apiErrorMessage, apiMessage } from '@/config/messages'
import { useSnackbar } from '@/composables/useSnackbar'
import { AI_TASK_READY_EVENT } from '@/composables/useAiTaskQueue'
import VisualStyleCard from '@/views/visual-styles/VisualStyleCard.vue'
import StyleAnalyzeDialog from '@/views/visual-styles/StyleAnalyzeDialog.vue'
import VisualStyleCreateDialog from '@/views/visual-styles/VisualStyleCreateDialog.vue'
import VisualStyleEditDialog from '@/views/visual-styles/VisualStyleEditDialog.vue'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import AppSnackbar from '@/components/AppSnackbar.vue'

definePage({ meta: { action: 'manage', subject: 'ai_settings' } })

const store = useVisualStyleStore()
const { items, pagination, isLoading: loading, error: storeError, isMutating: saving } = storeToRefs(store)

// State filter cục bộ — store giữ query, page giữ UI filter
const page = ref(1)
const perPage = ref(12)
const search = ref('')
const status = ref('')
const error = ref('')

// Snackbar + dialog state
const { snackbar, success: notifySuccess, error: notifyError } = useSnackbar()
const createOpen = ref(false)
const createRef = ref(null)
const createError = ref('')
const analyzeOpen = ref(false)
const confirmOpen = ref(false)
const confirmText = ref('')
const pendingAction = ref(null)

// Dialog chỉnh sửa style — dialog tự load + lưu qua store, page chỉ mở + reload
const editOpen = ref(false)
const editStyleId = ref(null)

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

// =====================================================================
// Input: filter hiện tại. Output: store cập nhật items + pagination.
// Side effect: GET list qua store; lỗi đọc từ storeError.
// =====================================================================
async function load() {
  error.value = ''

  const result = await store.fetchStyles({
    search: search.value || undefined,
    status: status.value || undefined,
    page: page.value,
    per_page: perPage.value,
  })

  if (result === null)
    error.value = apiErrorMessage(storeError.value)
}

// =====================================================================
// Input: payload {name, model_id, image} từ VisualStyleCreateDialog.
// Output: style queued; giữ dialog mở để dialog tự poll kết quả.
// =====================================================================
async function submitCreate(payload) {
  createError.value = ''
  try {
    const result = await store.analyzeStyle(payload)

    createRef.value?.setStyleId(result?.data?.id ?? null)
    notifySuccess(apiMessage(result, 'Đã xếp hàng phân tích phong cách.'))
    await load()
  }
  catch (reason) {
    createError.value = apiErrorMessage(reason, 'Phân tích thất bại.')
  }
}

// =====================================================================
// Input: payload multipart từ StyleAnalyzeDialog. Output: style queued.
// =====================================================================
const analyzeError = ref('')

async function submitAnalyze(payload) {
  analyzeError.value = ''

  try {
    const style = await store.analyzeStyle(payload)

    analyzeOpen.value = false
    notifySuccess(apiMessage(style, 'Đã xếp hàng phân tích phong cách.'))
    await load()
  }
  catch (reason) {
    analyzeError.value = apiErrorMessage(reason, 'Phân tích thất bại.')
  }
}

// =====================================================================
// Input: style. Output: mở dialog chỉnh sửa — dialog tự fetch detail qua store.
// =====================================================================
function openEdit(style) {
  editStyleId.value = style.id
  editOpen.value = true
}

// =====================================================================
// Input: kind + style. Output: confirm text + action pending.
// =====================================================================
function confirmAction(kind, style) {
  const labels = {
    remove: `Xóa “${style.name}”? Shot recipes liên quan cũng bị xóa.`,
  }

  confirmText.value = labels[kind] ?? ''
  pendingAction.value = { kind, style }
  confirmOpen.value = true
}

// =====================================================================
// Input: action pending. Output: gọi store; reload + snackbar.
// =====================================================================
async function runAction() {
  const action = pendingAction.value
  if (!action) return
  try {
    if (action.kind === 'remove')
      await store.removeStyle(action.style.id)
    notifySuccess('Thao tác thành công.')
    await load()
  }
  catch (reason) {
    notifyError(apiErrorMessage(reason))
  }
  finally {
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
          @click="createOpen = true"
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
          @click="createOpen = true"
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
        xl="2"
      >
        <VisualStyleCard
          :style="style"
          :busy="saving"
          @edit="openEdit"
          @remove="confirmAction('remove', $event)"
        />
      </VCol>
    </VRow>

    <!-- Pagination -->
    <div
      v-if="pagination && (pagination.last_page ?? 1) > 1"
      class="d-flex justify-center mt-6"
    >
      <VPagination
        v-model="page"
        :length="pagination.last_page"
        :disabled="loading"
      />
    </div>

    <!-- Dialog tạo style (UI Prompt tạo ảnh cũ) -->
    <VisualStyleCreateDialog
      ref="createRef"
      v-model="createOpen"
      :saving="saving"
      :error-message="createError"
      @submit="submitCreate"
      @after-leave="createError = ''"
    />

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
      :style-id="editStyleId"
      @saved="() => {}"
      @after-leave="editStyleId = null"
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
    <AppSnackbar v-model="snackbar" />
  </div>
</template>

<style scoped>
.visual-styles-page {
  min-inline-size: 0;
}
</style>
