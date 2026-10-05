<!--
  =====================================================================
  CHỨC NĂNG FILE: Điều phối danh sách và form tạo bài AI mới độc lập.
  =====================================================================
  Page dùng header giống Post, catalog AI Settings và API session hiện có.

  CÁC HÀM/METHOD TRONG FILE:
  - useAiContentWorkspace()/useAiContentCatalog(): nguồn và catalog/list thật.
  - useAiContentGeneration(): tạo và polling, không tự apply bài vào Post.
  - useAiContentActions(): dialog biên tập và action riêng từng candidate.
  - createNew(): reset nguồn khi không đang chạy tác vụ.
  - onMounted(): tải list và catalog độc lập.

  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : thao tác tìm kiếm và tạo bài viết mới.
  - OUTPUT: giao diện hai cột theo component/theme của project.
  - SIDE EFFECT: GET catalog/list, POST create và GET polling qua Admin API.
  =====================================================================
-->
<script setup>
import { onMounted } from 'vue'
import AiContentList from '@/views/ai/content/AiContentList.vue'
import AiContentCreateForm from '@/views/ai/content/AiContentCreateForm.vue'
import { useAiContentWorkspace } from '@/composables/useAiContentWorkspace'
import { useAiContentCatalog } from '@/composables/useAiContentCatalog'
import { useAiContentGeneration } from '@/composables/useAiContentGeneration'
import { useAiContentActions } from '@/composables/useAiContentActions'
import AiContentEditorDialog from '@/views/ai/content/AiContentEditorDialog.vue'
import AiContentRunActionDialog from '@/views/ai/content/AiContentRunActionDialog.vue'
import { useAiRunFeedback } from '@/composables/useAiRunFeedback'
import { getAlertColor } from '@/config/alertColors'

const { snackbar, observeRun, setSnackbarVisible } = useAiRunFeedback()

const { items, source, listLoading, listError, loadItems, updateSession, removeItem, resetSource } = useAiContentWorkspace()
const { catalog, loadCatalog, resetContentDefaults } = useAiContentCatalog(source)

const onRunFeedback = (run, fallback, options = catalog.value.outputOptions) => observeRun(run, fallback, options)
const { generation, generate, retryRun, reset, resumePolling, cancel } = useAiContentGeneration(source, catalog, updateSession, onRunFeedback)

const { editor, editorLoading, editorSaving, editorError, action, actionBusy, actionError, notice, busyId,
  openEditor, closeEditor, saveEditor, requestAction, closeAction, confirmAction, resumeRun } = useAiContentActions({
  updateSession, removeItem, onFeedback: onRunFeedback, getOutputOptions: () => catalog.value.outputOptions,
})

onMounted(loadCatalog)
onMounted(loadItems)

/** Input: thao tác tạo mới. Output: reset form/thông báo, giữ list; không reset khi AI đang chạy. */
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
          prepend-icon="tabler-plus"
          :disabled="generation.busy"
          @click="createNew"
        >
          Tạo content AI mới
        </VBtn>
      </div>
    </div>

    <VAlert
      v-if="notice"
      :type="notice.type"
      :color="getAlertColor(notice.type)"
      variant="tonal"
      class="mb-4"
      closable
      @click:close="notice = null"
    >
      {{ notice.message }}
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
          :busy-id="busyId"
          @reload="loadItems"
          @edit="openEditor"
          @remove="requestAction('remove', $event)"
          @regenerate="requestAction('regenerate', $event)"
          @refresh-status="resumeRun"
          @apply="requestAction('apply', $event)"
          @cancel="requestAction('cancel', $event)"
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
          @resume-polling="resumePolling"
          @reload-catalog="loadCatalog"
          @cancel="cancel"
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
      @confirm="confirmAction"
      @close="closeAction"
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
