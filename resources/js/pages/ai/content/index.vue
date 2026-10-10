<!--
  =====================================================================
  CHỨC NĂNG FILE: Điều phối danh sách và form tạo bài AI mới độc lập.
  =====================================================================
  Page dùng header giống Post, catalog AI Settings và API session hiện có.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - useAiContentWorkspace()/useAiContentCatalog(): nguồn và catalog/list thật.
  - useAiContentGeneration(): gửi nguồn vào queue và khóa form chỉ trong lúc POST.
  - useAiContentActions(): dialog biên tập và action riêng từng candidate.
  - useAiContentReview(): so sánh nguồn/lịch sử và duyệt hoặc từ chối.
  - editReviewedContent()/clearNotice(): mở editor hiện có và đóng thông báo.
  - handleQueued(): reset nguồn sau xác nhận queue và hiển thị thông báo.
  - createNew(): reset nguồn khi form không đang gửi request.
  - openVersionHistory()/openVersion()/closeVersionHistory(): điều phối dialog lịch sử phiên bản.
  - openTaskFromQuery(): mở kết quả task mà popup đã điều hướng tới.
  - historyItem/visibleNotice/contentBusyId: chọn dữ liệu dialog, notice và item bận.
  - onRunFeedback(): chuyển lifecycle run thành feedback an toàn.
  - watcher items: khôi phục polling active run sau tải list.
  - watcher route.query.session: mở candidate từ popup khi route đổi.
  - onMounted(): tải list và catalog độc lập.
  - props/emits: không có; page điều phối component con qua events.

  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : thao tác tìm kiếm và tạo bài viết mới.
  - OUTPUT: giao diện hai cột theo component/theme của project.
  - SIDE EFFECT: GET catalog/list, POST create và GET polling qua Admin API.
  =====================================================================
-->
<script setup>
import { computed, onMounted, shallowRef, watch } from 'vue'
import { useRoute } from 'vue-router'
import AiContentList from '@/views/ai/content/AiContentList.vue'
import AiContentCreateForm from '@/views/ai/content/AiContentCreateForm.vue'
import { useAiContentWorkspace } from '@/composables/useAiContentWorkspace'
import { useAiContentCatalog } from '@/composables/useAiContentCatalog'
import { useAiContentGeneration } from '@/composables/useAiContentGeneration'
import { useAiContentActions } from '@/composables/useAiContentActions'
import { useAiContentReview } from '@/composables/useAiContentReview'
import AiContentEditorDialog from '@/views/ai/content/AiContentEditorDialog.vue'
import AiContentRunActionDialog from '@/views/ai/content/AiContentRunActionDialog.vue'
import AiContentReviewDialog from '@/views/ai/content/dialog/AiContentReviewDialog.vue'
import AiContentReviewDecisionDialog from '@/views/ai/content/dialog/AiContentReviewDecisionDialog.vue'
import AiContentVersionHistoryDialog from '@/views/ai/content/AiContentVersionHistoryDialog.vue'
import { useAiRunFeedback } from '@/composables/useAiRunFeedback'
import { getAlertColor } from '@/config/alertColors'

const { snackbar, observeRun, showSnackbar, setSnackbarVisible } = useAiRunFeedback()
const route = useRoute()
const versionHistory = shallowRef(null)

const { items, source, listLoading, listError, loadItems, updateSession, removeItem, resetSource } = useAiContentWorkspace()
const historyItem = computed(() => items.value.find(item => item.sessionId === versionHistory.value?.sessionId) ?? versionHistory.value)
const { catalog, loadCatalog, resetContentDefaults } = useAiContentCatalog(source)

const onRunFeedback = (run, fallback, options = catalog.value.outputOptions) => observeRun(run, fallback, options)

/**
 * =====================================================================
 * CHỨC NĂNG: Reset nguồn sau khi server nhận task để nhập nguồn tiếp theo.
 * =====================================================================
 * INPUT: callback accepted từ generation composable.
 * OUTPUT: form nguồn mới và snackbar thành công.
 * SIDE EFFECT: reset source/default state; không gọi API hoặc mở popup.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
function handleQueued() {
  resetSource(catalog.value.contentDefaults)
  resetContentDefaults()
  showSnackbar('Đã nhận tác vụ. Theo dõi trong hàng đợi và tiếp tục nhập nguồn mới.', 'success')
}

const { generation, generate, retryRun, reset } = useAiContentGeneration(source, catalog, updateSession, onRunFeedback, handleQueued)

const { editor, editorLoading, editorSaving, editorError, action, actionBusy, actionError, notice, busyId,
  openEditor, closeEditor, saveEditor, requestAction, closeAction, confirmAction, resumeRun, trackRuns, thumbnailAction } = useAiContentActions({
  updateSession, removeItem, onFeedback: onRunFeedback, getOutputOptions: () => catalog.value.outputOptions,
})

/**
 * =====================================================================
 * CHỨC NĂNG: Khôi phục polling cho mọi run/thumbnail còn chạy trong list.
 * =====================================================================
 * INPUT: items computed sau mỗi lần list/live update.
 * OUTPUT: không trả giá trị; action composable đăng ký GET polling.
 * SIDE EFFECT: đặt timer polling; không ảnh hưởng form tạo nguồn.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
watch(items, () => trackRuns(items.value))

const { state: reviewState, notice: reviewNotice, openReview, loadReview, loadHistory, rescoreQuality, requestDecision,
  closeDecision, confirmDecision, closeReview, finishClose } = useAiContentReview(updateSession, notice)

const visibleNotice = computed(() => reviewNotice.value ?? notice.value)
const contentBusyId = computed(() => reviewState.value.busy ? reviewState.value.detail?.job_id : busyId.value)

/**
 * =====================================================================
 * CHỨC NĂNG: Mở dialog lịch sử các phiên bản của một content session.
 * =====================================================================
 * INPUT: item đã gom theo session, có versions/history.
 * OUTPUT: trạng thái dialog được chọn; không gọi API.
 * SIDE EFFECT: chỉ thay đổi state dialog cục bộ.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
function openVersionHistory(item) {
  versionHistory.value = item
}

/**
 * =====================================================================
 * CHỨC NĂNG: Mở đúng candidate phiên bản được chọn từ lịch sử.
 * =====================================================================
 * INPUT: version record đã chuẩn hóa.
 * OUTPUT: review dialog cho Post hoặc editor cho target khác.
 * SIDE EFFECT: GET detail qua composable tương ứng; không tạo run mới.
 * EXCEPTION/TRANSACTION: lỗi đọc do dialog/composable xử lý.
 * =====================================================================
 */
function openVersion(version) {
  versionHistory.value = null
  if (version.targetType === 'post') {
    void openReview({ id: version.id, runId: version.id, targetType: version.targetType, status: version.status })
  } else {
    void openEditor({ id: version.id, runId: version.id, targetType: version.targetType, status: version.status })
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Đóng dialog lịch sử phiên bản.
 * =====================================================================
 * INPUT: không có.
 * OUTPUT: xóa item đang mở khỏi state dialog; lịch sử server không đổi.
 * SIDE EFFECT: chỉ thay đổi state UI.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
function closeVersionHistory() {
  versionHistory.value = null
}

/**
 * =====================================================================
 * CHỨC NĂNG: Chuyển candidate đang review sang editor hiện có.
 * =====================================================================
 * INPUT: detail Post đã GET và đủ quyền can_edit.
 * OUTPUT: review đóng, editor mở đúng job_id.
 * SIDE EFFECT: GET detail qua editor composable; quyết định sau cần GET mới.
 * EXCEPTION/TRANSACTION: lỗi đọc do editor xử lý, không mở transaction.
 * =====================================================================
 */
function editReviewedContent() {
  const detail = reviewState.value.detail
  if (!detail?.can_edit || reviewState.value.busy || reviewState.value.loading) return
  closeReview()
  void openEditor({ id: detail.job_id, targetType: 'post', status: 'review' })
}

/**
 * =====================================================================
 * CHỨC NĂNG: Đóng notice đang hiển thị từ action/review workflow.
 * =====================================================================
 * INPUT: thao tác đóng alert.
 * OUTPUT: các notice refs trở về null.
 * SIDE EFFECT: chỉ thay đổi UI state, không gọi API.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
function clearNotice() {
  notice.value = null
  reviewNotice.value = null
}

/**
 * =====================================================================
 * CHỨC NĂNG: Mở candidate từ deep link do popup task gửi tới.
 * =====================================================================
 * INPUT: query session chứa UUID AiImport. OUTPUT: editor load đúng session.
 * SIDE EFFECT: GET detail qua openEditor; không tạo lại hoặc dispatch worker.
 * EXCEPTION/TRANSACTION: lỗi đọc do editor composable xử lý.
 * =====================================================================
 */
async function openTaskFromQuery() {
  const sessionId = typeof route.query.session === 'string' ? route.query.session : null
  if (!sessionId) return

  const item = items.value.flatMap(candidate => candidate.versions).find(candidate => candidate.id === sessionId)
    ?? items.value.find(candidate => candidate.id === sessionId)
  if (item && item.status !== 'generating') await openEditor(item)
}

watch(() => route.query.session, openTaskFromQuery)

onMounted(loadCatalog)
onMounted(async () => {
  await loadItems()
  await openTaskFromQuery()
})

/**
 * =====================================================================
 * CHỨC NĂNG: Reset nguồn thủ công để tạo content AI mới.
 * =====================================================================
 * INPUT: thao tác nút Tạo content AI mới.
 * OUTPUT: nguồn trống và content defaults hiện tại.
 * SIDE EFFECT: reset state form; task accepted vẫn nằm trong queue.
 * EXCEPTION/TRANSACTION: không reset khi POST còn đang chạy.
 * =====================================================================
 */
function createNew() {
  if (reset()) {
    resetSource(catalog.value.contentDefaults)
    resetContentDefaults()
  }
}
</script>

<template>
  <div class="ai-content-page">
    <div class="d-flex flex-wrap justify-space-between gap-y-4 gap-x-6 mb-6">
      <div>
        <h4 class="text-h4 font-weight-medium">
          Ai Content
        </h4>
        <div class="text-body-1">
          Tạo bài viết bằng AI và quản lý kết quả chờ duyệt.
        </div>
      </div>
      <div class="d-flex flex-wrap align-center gap-4">
        <VBtn
          color="secondary"
          variant="tonal"
          prepend-icon="tabler-circle-check"
          :to="{ name: 'ai-approved' }"
        >
          AI Approved
        </VBtn>
        <VBtn
          prepend-icon="tabler-plus"
          :disabled="generation.busy"
          @click="createNew"
        >
          Tạo content AI mới
        </VBtn>
      </div>
    </div>

    <VAlert
      v-if="visibleNotice"
      :type="visibleNotice.type"
      :color="getAlertColor(visibleNotice.type)"
      variant="tonal"
      class="mb-4"
      closable
      @click:close="clearNotice"
    >
      {{ visibleNotice.message }}
    </VAlert>
    <VRow>
      <VCol
        cols="12"
        lg="5"
      >
        <AiContentList
          :items="items"
          :loading="listLoading"
          :error="listError"
          :targets="catalog.targetOptions"
          :output-options="catalog.outputOptions"
          :busy-id="contentBusyId"
          @reload="loadItems"
          @edit="openEditor"
          @remove="requestAction('remove', $event)"
          @regenerate="requestAction('regenerate', $event)"
          @history="openVersionHistory"
          @refresh-status="resumeRun"
          @apply="openReview"
          @review="openReview"
          @cancel="requestAction('cancel', $event)"
          @retry-thumbnail="thumbnailAction($event)"
          @cancel-thumbnail="thumbnailAction($event, 'cancel')"
        />
      </VCol>
      <VCol
        cols="12"
        lg="7"
      >
        <AiContentCreateForm
          v-model="source"
          :catalog="catalog"
          :generation="generation"
          @generate="generate"
          @retry-run="retryRun"
          @reload-catalog="loadCatalog"
        />
      </VCol>
    </VRow>
    <AiContentEditorDialog
      :session="editor"
      :loading="editorLoading"
      :saving="editorSaving"
      :error="editorError"
      @save="saveEditor"
      @close="closeEditor"
      @reload="openEditor({ id: editor.job_id, status: 'review', targetType: editor.target_type })"
    />
    <AiContentRunActionDialog
      :action="action"
      :busy="actionBusy"
      :error="actionError"
      :catalog="catalog"
      @confirm="confirmAction"
      @close="closeAction"
    />
    <AiContentReviewDialog
      :state="reviewState"
      @close="closeReview"
      @after-leave="finishClose"
      @reload="loadReview"
      @rescore="rescoreQuality"
      @history-reload="loadHistory"
      @history-more="loadHistory(reviewState.historyPagination.current_page + 1)"
      @approve="requestDecision('approve')"
      @reject="requestDecision('reject')"
      @edit="editReviewedContent"
    />
    <AiContentReviewDecisionDialog
      :kind="reviewState.decision"
      :detail="reviewState.detail"
      :busy="reviewState.busy"
      :loading="reviewState.loading"
      :blocked="reviewState.decisionBlocked"
      :error="reviewState.decisionError"
      @confirm="confirmDecision"
      @close="closeDecision"
      @reload="loadReview"
    />
    <AiContentVersionHistoryDialog
      :item="historyItem"
      @close="closeVersionHistory"
      @open="openVersion"
    />
    <VSnackbar
      :model-value="snackbar.visible"
      :color="getAlertColor(snackbar.type)"
      location="top end"
      :timeout="4000"
      @update:model-value="setSnackbarVisible"
    >
      {{ snackbar.message }}
      <template #actions>
        <VBtn
          icon="tabler-x"
          size="small"
          aria-label="Đóng thông báo AI"
          @click="setSnackbarVisible(false)"
        />
      </template>
    </VSnackbar>
  </div>
</template>
