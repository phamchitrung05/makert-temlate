<!--
  =====================================================================
  Header/footer cố định qua AppDialogLayout; chỉ content ở giữa được cuộn.
  CHỨC NĂNG FILE: Điều phối nguồn, phân tích và duyệt lưu văn phong tại Ai Prompt Add.
  =====================================================================
  Giữ layout/theme của giao diện người dùng; dùng API hiện có cho văn phong,
  các khối chưa có API giữ dữ liệu minh họa riêng. Nút danh sách mở Ai Prompt List.
  CÁC HÀM/METHOD TRONG FILE:
  - analyzeSource(): chặn thao tác khi busy rồi phân tích nguồn đã xác nhận.
  - restoreAnalysis(): mở UUID thật sau khi xác nhận bỏ form chưa lưu.
  - resetCreation(): dọn nguồn/analysis/form và resume để tạo mẫu mới, giữ database.
  - startNewProfile(): yêu cầu xác nhận nếu bỏ bản đang duyệt chưa lưu.
  - saveProfile(): lưu rồi reset Add khi thành công; giữ form khi lỗi/default lỗi.
  - copyPrompt(): sao chép hướng dẫn đang xem/sửa, báo kết quả clipboard.
  - exportReport(): tải Markdown từ analysis thật, không kèm toàn bài tham khảo.
  - confirmAction(): xác nhận bỏ form cũ hoặc cho phép lượt POST mới có chủ đích.
  - hasUnsavedChanges (computed): đối chiếu bản đang duyệt với profile đã lưu.
  - canStartNew (computed): chặn reset khi request đang chạy hoặc POST chưa xác định.
  - onMounted(): GET catalog và resume UUID của actor, không tự gửi model.
  - onBeforeRouteLeave(): nhắc bản sửa chưa lưu khi người dùng rời trang.
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : tên, nguồn, model, thao tác phân tích/hủy/duyệt/lưu và UUID resume.
  - OUTPUT: component hai cột, lifecycle/result/profile thật và minh họa có nhãn.
  - SIDE EFFECT: qua composable gọi Admin API; clipboard/download theo click.
  =====================================================================
-->
<script setup>
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import { computed, onMounted, shallowRef } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { getAlertColor } from '@/config/alertColors'
import { useAdminAuthStore } from '@/stores/adminAuth'
import { useAiPromptSource } from '@/composables/ai/prompt/useAiPromptSource'
import { useAiPromptAnalysis } from '@/composables/ai/prompt/useAiPromptAnalysis'
import { useAiPromptProfiles } from '@/composables/ai/prompt/useAiPromptProfiles'
import { analysisReport, profilePayload } from '@/utils/aiWritingProfile'
import AiPromptSourceCard from '@/views/ai/prompt/AiPromptSourceCard.vue'
import AiPromptAnalysisCard from '@/views/ai/prompt/AiPromptAnalysisCard.vue'
import AiPromptInsights from '@/views/ai/prompt/AiPromptInsights.vue'
import AiPromptProfileForm from '@/views/ai/prompt/AiPromptProfileForm.vue'
import { promptPreviewReport } from '@/views/ai/prompt/promptPreview'

const auth = useAdminAuthStore()
const canPreview = computed(() => auth.permissions.includes('posts.manage'))
const source = useAiPromptSource(canPreview)
const run = useAiPromptAnalysis(source, () => auth.user?.id)
const profiles = useAiPromptProfiles(run, source.catalog, () => auth.user?.id)
const { sourceHtml, profileName, sourceTab, sourceUrl, sourceFile, sourceText, modelId, previewing, sourcePrepared, metrics, modelOptions } = source
const { analysis, status, submitting, cancelling, paused, sourceChanged, uncertainSubmit } = run
const { form, savedProfile, saving, restoring, conflict, defaultError, defaultProfileId, canSave } = profiles
const catalogLoading = source.catalog.loading
const catalogError = source.catalog.error
const sourceErrors = computed(() => ({ ...source.errors.value, ...run.errors.value, file: source.errors.value.html_file ?? source.errors.value.file ?? [] }))
const sourceNotice = source.notice
const analysisMessage = run.message
const profileMessage = profiles.message
const profileErrors = profiles.errors
const busy = computed(() => run.running.value || saving.value || restoring.value || catalogLoading.value)

const canStartNew = computed(() => !run.running.value && !saving.value && !restoring.value && !previewing.value
  && !uncertainSubmit.value && !profiles.uncertainSave.value)

const result = computed(() => status.value === 'ready' ? analysis.value?.result ?? null : null)
const historyVisible = shallowRef(false)
const resumeId = shallowRef('')
const confirmKind = shallowRef('')
const snackbarVisible = shallowRef(false)
const snackbarMessage = shallowRef('')
const snackbarType = shallowRef('success')
const statusLabels = { idle: 'Chưa phân tích', queued: 'Đang chờ xử lý', analyzing: 'Đang phân tích', ready: 'Đã phân tích', failed: 'Phân tích thất bại', cancelled: 'Đã hủy', expired: 'Đã hết hạn', forbidden: 'Không có quyền truy cập' }
const statusColor = computed(() => ({ ready: 'success', failed: 'error', cancelled: 'secondary', expired: 'warning', forbidden: 'error', idle: 'secondary' }[status.value] || 'primary'))

const staticHistory = [
  { name: 'Giải thích dễ hiểu', note: 'Bản ghi minh họa, chưa lưu thành mẫu' },
  { name: 'Kể chuyện gần gũi', note: 'Bản ghi minh họa' },
]

/**
 * =====================================================================
 * CHỨC NĂNG: Nhận biết form đã sửa nhưng chưa được server ghi nhận.
 * Input: form hiện tại và profile đã lưu. Output: boolean cho nhắc rời trang.
 * =====================================================================
 */
const hasUnsavedChanges = computed(() => {
  if (!result.value && !savedProfile.value) return false
  if (!savedProfile.value) return true
  const current = profilePayload(form.value, analysis.value?.id, savedProfile.value)
  const stored = savedProfile.value

  return current.name !== stored.name || current.description !== stored.description
    || current.style_instructions !== stored.style_instructions || current.is_enabled !== stored.is_enabled
    || JSON.stringify(current.rules_json) !== JSON.stringify(stored.rules_json)
    || JSON.stringify(current.evidence_json) !== JSON.stringify(stored.evidence_json)
    || form.value.setAsDefault !== (stored.id === defaultProfileId.value)
})

/**
 * =====================================================================
 * CHỨC NĂNG: Đưa trang Add về trạng thái đầu sau khi lưu hoặc xác nhận tạo mới.
 * Input: lifecycle hiện tại. Output: boolean đã dọn; không gọi API/model.
 * SIDE EFFECT: reset nguồn/analysis/form và UUID resume; giữ mẫu đã lưu trong DB.
 * =====================================================================
 */
function resetCreation() {
  if (!canStartNew.value || !run.reset()) return false
  profiles.reset()
  source.resetForNewProfile()
  resumeId.value = ''
  historyVisible.value = false
  confirmKind.value = ''

  return true
}

/**
 * =====================================================================
 * CHỨC NĂNG: Cho phép tạo tiếp văn phong từ kết quả đang mở.
 * Input: click Văn phong mới. Output: form trống hoặc dialog xác nhận bản chưa lưu.
 * SIDE EFFECT: không bỏ trạng thái POST bất định, không xóa profile database.
 * =====================================================================
 */
function startNewProfile() {
  if (!canStartNew.value) return
  if (hasUnsavedChanges.value) {
    confirmKind.value = 'reset'

    return
  }
  resetCreation()
}

/**
 * =====================================================================
 * CHỨC NĂNG: Lưu văn phong và chuẩn bị Add cho mẫu kế tiếp.
 * Input: form người dùng đã duyệt. Output: thông báo thành công và form mới.
 * SIDE EFFECT: mutation qua profiles.save(); chỉ reset sau server xác nhận và
 * cập nhật mặc định thành công nếu có yêu cầu. Lỗi giữ nguyên bản đang duyệt.
 * =====================================================================
 */
async function saveProfile() {
  if (!await profiles.save()) return
  const name = savedProfile.value.name

  if (!resetCreation()) return
  snackbarType.value = 'success'
  snackbarMessage.value = `Đã lưu văn phong “${name}”. Bạn có thể tạo văn phong mới.`
  snackbarVisible.value = true
}

/**
 * =====================================================================
 * CHỨC NĂNG: Phân tích theo click, xác nhận khi sẽ thay bản đang duyệt.
 * Input: nguồn/tên/model hiện tại. Output: POST qua composable hoặc dialog.
 * SIDE EFFECT: không gửi AI khi input đang khóa hoặc POST trước chưa rõ kết quả.
 * =====================================================================
 */
function analyzeSource() {
  if (busy.value || previewing.value || uncertainSubmit.value) return
  if (hasUnsavedChanges.value) {
    confirmKind.value = 'analyze'

    return
  }
  void run.analyze()
}

/**
 * =====================================================================
 * CHỨC NĂNG: Mở UUID đã có mà không làm mất bản đang duyệt ngoài ý muốn.
 * Input: resumeId hợp lệ và form hiện tại. Output: dialog xác nhận hoặc GET detail.
 * SIDE EFFECT: không tạo analysis mới; giữ form cũ đến khi người dùng xác nhận.
 * =====================================================================
 */
function restoreAnalysis() {
  if (busy.value || !/^[\da-f]{8}(?:-[\da-f]{4}){3}-[\da-f]{12}$/i.test(resumeId.value.trim())) return
  historyVisible.value = false
  if (hasUnsavedChanges.value) {
    confirmKind.value = 'resume'

    return
  }
  run.restore(resumeId.value.trim())
}

/**
 * =====================================================================
 * CHỨC NĂNG: Copy đúng hướng dẫn đang hiển thị, kể cả bản người dùng đã sửa.
 * Input: click copy. Output: clipboard và snackbar thành công/lỗi.
 * SIDE EFFECT: trước phân tích chỉ copy fixture đã gắn nhãn minh họa.
 * =====================================================================
 */
async function copyPrompt() {
  try {
    await navigator.clipboard.writeText(result.value ? form.value.style_instructions : promptPreviewReport.promptText)
    snackbarType.value = 'success'
    snackbarMessage.value = result.value ? 'Đã sao chép hướng dẫn văn phong.' : 'Đã sao chép hướng dẫn minh họa.'
  }
  catch {
    snackbarType.value = 'error'
    snackbarMessage.value = 'Không thể sao chép. Bạn có thể chọn nội dung và sao chép thủ công.'
  }
  snackbarVisible.value = true
}

/**
 * =====================================================================
 * CHỨC NĂNG: Export bản đang xem thành Markdown có metadata thật.
 * Input: analysis ready/form/profile. Output: download, không gọi API export/model.
 * SIDE EFFECT: tạo và thu hồi Blob URL; không xuất toàn bài nguồn hoặc secret.
 * =====================================================================
 */
function exportReport() {
  if (!result.value) return
  const url = URL.createObjectURL(new Blob([analysisReport(analysis.value, form.value, savedProfile.value)], { type: 'text/markdown;charset=utf-8' }))
  const link = document.createElement('a')

  link.href = url
  link.download = `van-phong-${analysis.value.id}.md`
  link.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

/**
 * =====================================================================
 * CHỨC NĂNG: Thực hiện lựa chọn sau xác nhận bỏ bản sửa hoặc POST mới bất định.
 * Input: confirmKind đã chọn. Output: action chủ động; không tự retry mutation.
 * =====================================================================
 */
function confirmAction() {
  const kind = confirmKind.value

  confirmKind.value = ''
  if (kind === 'analyze') void run.analyze()
  if (kind === 'resume') run.restore(resumeId.value.trim())
  if (kind === 'reload') void profiles.reloadProfile()
  if (kind === 'new') run.allowNewSubmission()
  if (kind === 'reset') resetCreation()
}

onMounted(async () => {
  await source.loadCatalog()
  run.restore()
})

onBeforeRouteLeave(() => !hasUnsavedChanges.value || window.confirm('Bạn có mẫu văn phong chưa lưu. Rời trang và bỏ bản chỉnh sửa hiện tại?'))
</script>

<template>
  <div class="ai-prompt-page">
    <div class="d-flex flex-wrap justify-space-between gap-y-4 gap-x-6 mb-6">
      <div class="ai-prompt-page__heading">
        <div class="d-flex align-center flex-wrap gap-3 mb-1">
          <h4 class="text-h4 font-weight-medium">
            Thêm văn phong
          </h4>
          <VChip
            :color="statusColor"
            variant="tonal"
            size="small"
          >
            {{ submitting ? 'Đang gửi bài mẫu' : statusLabels[status] }}
          </VChip>
        </div>
        <div class="text-body-1 text-medium-emphasis">
          Phân tích bài tham khảo, duyệt và lưu mẫu văn phong để sử dụng lại.
        </div>
      </div>
      <div class="d-flex flex-wrap align-center gap-3">
        <VBtn
          v-if="analysis || savedProfile"
          color="secondary"
          variant="tonal"
          prepend-icon="tabler-plus"
          :disabled="!canStartNew"
          @click="startNewProfile"
        >
          Văn phong mới
        </VBtn>
        <VBtn
          :to="{ name: 'ai-prompt-list' }"
          color="secondary"
          variant="text"
          prepend-icon="tabler-arrow-left"
        >
          Danh sách
        </VBtn>
        <VBtn
          color="secondary"
          variant="tonal"
          prepend-icon="tabler-history"
          @click="historyVisible = true"
        >
          Lịch sử phân tích
        </VBtn>
        <VBtn
          color="secondary"
          variant="tonal"
          prepend-icon="tabler-download"
          :disabled="!result"
          @click="exportReport"
        >
          Tải báo cáo
        </VBtn>
        <VBtn
          prepend-icon="tabler-sparkles"
          :loading="submitting"
          :disabled="busy || previewing || uncertainSubmit"
          @click="analyzeSource"
        >
          {{ analysis ? 'Phân tích lại' : 'Phân tích với AI' }}
        </VBtn>
      </div>
    </div>
    <VAlert
      v-if="analysisMessage"
      :type="status === 'failed' ? 'error' : 'warning'"
      variant="tonal"
      class="mb-6"
    >
      {{ analysisMessage }}
    </VAlert>
    <VAlert
      v-if="uncertainSubmit"
      type="warning"
      variant="tonal"
      class="mb-6"
    >
      <div class="d-flex flex-wrap align-center justify-space-between gap-3">
        <span>Chỉ tạo lượt mới sau khi đã kiểm tra yêu cầu trước. Một lượt mới có thể phát sinh thêm chi phí.</span>
        <VBtn
          color="warning"
          variant="tonal"
          @click="confirmKind = 'new'"
        >
          Cho phép lượt mới
        </VBtn>
      </div>
    </VAlert>
    <VCard
      v-if="analysis"
      class="mb-6"
    >
      <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3">
        <div class="ai-prompt-page__heading">
          <div class="font-weight-medium">
            {{ analysis.name || 'Đang tải tác vụ' }}
          </div>
          <div class="text-body-2 text-medium-emphasis text-break">
            ID: {{ analysis.id }}
          </div>
          <div
            v-if="analysis.model"
            class="text-body-2 text-medium-emphasis"
          >
            {{ analysis.provider }} / {{ analysis.model }}
          </div>
          <div
            v-if="analysis.expires_at"
            class="text-body-2 text-medium-emphasis"
          >
            Thời hạn phân tích: {{ new Date(analysis.expires_at).toLocaleString('vi-VN') }}
          </div>
        </div>
        <div class="d-flex flex-wrap gap-3">
          <VBtn
            v-if="paused"
            variant="tonal"
            :disabled="cancelling"
            @click="run.resumePolling"
          >
            Kiểm tra lại
          </VBtn>
          <VBtn
            v-if="['queued', 'analyzing'].includes(status)"
            color="error"
            variant="tonal"
            :loading="cancelling"
            @click="run.cancel"
          >
            Hủy phân tích
          </VBtn>
        </div>
      </VCardText>
      <VProgressLinear
        v-if="['queued', 'analyzing'].includes(status) && !paused"
        indeterminate
        color="primary"
      />
    </VCard>
    <VRow>
      <VCol
        cols="12"
        lg="7"
        class="ai-prompt-page__column"
      >
        <div class="d-flex flex-column gap-6">
          <AiPromptSourceCard
            v-model="sourceHtml"
            v-model:profile-name="profileName"
            v-model:source-tab="sourceTab"
            v-model:source-url="sourceUrl"
            v-model:source-file="sourceFile"
            v-model:source-text="sourceText"
            v-model:model-id="modelId"
            :word-count="metrics.wordCount"
            :model-options="modelOptions"
            :catalog-loading="catalogLoading"
            :catalog-error="catalogError"
            :busy="busy || uncertainSubmit"
            :previewing="previewing"
            :can-preview="canPreview"
            :source-prepared="sourcePrepared"
            :errors="sourceErrors"
            :source-notice="sourceNotice"
            @reset="source.resetSource"
            @clear="source.clearSource"
            @preview="source.preview"
            @analyze="analyzeSource"
            @reload-catalog="source.loadCatalog"
          />
          <AiPromptAnalysisCard
            :report="promptPreviewReport"
            :result="result"
            :status="status"
            :analysis-name="analysis?.name || ''"
            :source-changed="sourceChanged"
          />
          <VAlert
            v-if="profileMessage"
            :type="conflict || profiles.uncertainSave.value || Object.keys(profileErrors).length ? 'warning' : 'success'"
            variant="tonal"
          >
            {{ profileMessage }}
          </VAlert>
          <VBtn
            v-if="profiles.uncertainSave.value"
            color="warning"
            variant="tonal"
            :loading="restoring"
            @click="profiles.recoverSave"
          >
            Kiểm tra mẫu đã lưu
          </VBtn>
          <AiPromptProfileForm
            v-if="result || savedProfile"
            v-model="form"
            :saving="saving"
            :busy="run.running.value || restoring"
            :errors="profileErrors"
            :saved-profile="savedProfile"
            :conflict="conflict"
            :can-save="canSave"
            :default-profile-id="defaultProfileId"
            :default-error="defaultError"
            @save="saveProfile"
            @reload-profile="confirmKind = 'reload'"
          />
        </div>
      </VCol>
      <VCol
        cols="12"
        lg="5"
        class="ai-prompt-page__column"
      >
        <AiPromptInsights
          :report="promptPreviewReport"
          :metrics="metrics"
          :result="result"
          :status="status"
          :provider="analysis?.provider || ''"
          :model="analysis?.model || ''"
          :analysis-name="analysis?.name || profileName"
          :prompt-text="form.style_instructions"
          @copy="copyPrompt"
          @export="exportReport"
        />
      </VCol>
    </VRow>
    <VDialog
      v-model="historyVisible"
      scrollable
      max-width="680"
    >
      <AppDialogLayout
        title="Lịch sử phân tích"
        @close="historyVisible = false"
      >
        <VCardText>
          <VAlert
            type="info"
            variant="tonal"
            class="mb-5"
          >
            Danh sách bên dưới là dữ liệu minh họa. Bạn có thể nhập ID thật để mở lại kết quả của mình còn thời hạn.
          </VAlert>
          <VList
            lines="two"
            class="mb-5"
          >
            <VListItem
              v-for="item in staticHistory"
              :key="item.name"
              :title="item.name"
              :subtitle="item.note"
            >
              <template #append>
                <VChip
                  size="small"
                  color="secondary"
                  variant="tonal"
                >
                  Minh họa
                </VChip>
              </template>
            </VListItem>
          </VList>
          <AppTextField
            v-model="resumeId"
            label="ID phân tích của bạn"
            placeholder="UUID tác vụ đã tạo"
            :disabled="busy"
          />
          <VBtn
            class="mt-4"
            :disabled="busy || !/^[\da-f]{8}(?:-[\da-f]{4}){3}-[\da-f]{12}$/i.test(resumeId.trim())"
            @click="restoreAnalysis"
          >
            Mở kết quả
          </VBtn>
        </VCardText>
        <template #footer>
          <VCardActions>
            <VSpacer /><VBtn
              variant="flat"
              color="secondary"
              @click="historyVisible = false"
            >
              Đóng
            </VBtn>
          </VCardActions>
        </template>
      </AppDialogLayout>
    </VDialog>
    <VDialog
      scrollable
      :model-value="Boolean(confirmKind)"
      max-width="520"
      @update:model-value="confirmKind = ''"
    >
      <AppDialogLayout
        title="Xác nhận thao tác"
        @close="confirmKind = ''"
      >
        <VCardText>{{ confirmKind === 'new' ? 'Yêu cầu trước chưa xác định đã được tạo hay chưa. Bạn đã kiểm tra và muốn cho phép một lượt phân tích mới?' : 'Thao tác này sẽ thay bản văn phong đang chỉnh sửa. Tiếp tục và bỏ bản chỉnh sửa chưa lưu?' }}</VCardText>
        <template #footer>
          <VCardActions>
            <VSpacer /><VBtn
              variant="flat"
              color="secondary"
              @click="confirmKind = ''"
            >
              Giữ bản hiện tại
            </VBtn><VBtn
              variant="flat"
              @click="confirmAction"
            >
              Tiếp tục
            </VBtn>
          </VCardActions>
        </template>
      </AppDialogLayout>
    </VDialog>
    <VSnackbar
      v-model="snackbarVisible"
      :color="getAlertColor(snackbarType)"
      location="top end"
      :timeout="4000"
    >
      {{ snackbarMessage }}
      <template #actions>
        <IconBtn
          aria-label="Đóng thông báo"
          @click="snackbarVisible = false"
        >
          <VIcon icon="tabler-x" />
        </IconBtn>
      </template>
    </VSnackbar>
  </div>
</template>

<style scoped>
.ai-prompt-page__heading,
.ai-prompt-page__column {
  min-inline-size: 0;
}
</style>
