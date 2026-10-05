<!--
  =====================================================================
  Header/footer cố định qua AppDialogLayout; chỉ content ở giữa được cuộn.
  CHỨC NĂNG FILE: Dialog AI dùng chung theo target capability, có preview
  candidate, regenerate và apply chọn lọc trước khi lưu tài nguyên.
  =====================================================================

  Đây là component logic/UI dùng cho các target được Target Registry cho phép.
  Component cung cấp contract tổng quát cho các màn hình tài nguyên;
  việc tạo nội dung Post được quản lý tại trang AI Content.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - load(): tải capability và khởi tạo form theo target/operation.
  - buildRequest(): tạo payload AI Agent từ form.
  - run(): tạo session candidate.
  - pollUntilDone(): polling có backoff và dọn timer khi đóng dialog.
  - regenerate(): tạo run mới, không xóa candidate cũ.
  - apply(): emit hoặc gọi apply tùy persistOnApply.
  - cancel()/stopPolling(): hủy tác vụ và giải phóng polling.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : targetType, targetId, operation, capability và candidate API data.
  - OUTPUT: event apply/applied; side effect gọi store/API; không tự publish.
  =====================================================================
-->
<script setup>
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
/* eslint-disable camelcase */
import { computed, onBeforeUnmount, reactive, shallowRef, watch } from 'vue'
import { useAiAgentStore } from '@/stores/aiAgent'
import AiAgentCandidatePreview from './AiAgentCandidatePreview.vue'
import { findProvider, providerModels } from '@/utils/aiModelOptions'
import { buildAiContentRegenerateRequest } from '@/utils/aiContentInput'

const props = defineProps({
  targetType: { type: String, required: true },
  targetId: { type: [Number, String], default: null },
  operation: { type: String, default: 'create' },
  persistOnApply: { type: Boolean, default: false },
})

const emit = defineEmits(['apply', 'applied'])
const visible = defineModel({ type: Boolean, default: false })
const store = useAiAgentStore()
const selectedCandidateId = shallowRef(null)
const selectedFields = shallowRef([])
const message = shallowRef('')
const polling = shallowRef(false)
let pollingTimer
let pollingGeneration = 0
const form = reactive({ operation: props.operation, inputType: 'url', inputValue: '', language: 'vi', instructions: '', selectionMode: 'auto', promptKey: '', provider: '', model: '', outputs: [] })
const capability = computed(() => store.capabilities ?? {})
const inputTypes = computed(() => capability.value.input_types ?? capability.value.inputs ?? [])
const outputFields = computed(() => capability.value.outputs ?? [])
const prompts = computed(() => (capability.value.prompts ?? []).map(item => typeof item === 'string' ? { key: item, label: item } : item))
const providers = computed(() => capability.value.providers ?? [])

const models = computed(() => {
  return providerModels(findProvider(providers.value, form.provider), null)
})

const currentCandidate = computed(() => store.candidates.find(item => String(item.id) === String(selectedCandidateId.value)) ?? store.candidates[0] ?? null)
const candidateItems = computed(() => store.candidates.map((item, index) => ({ title: `${index + 1}. ${item.model || item.provider || 'Candidate'}`, value: item.id })))
const busy = computed(() => store.isLoading || polling.value)
const sessionId = computed(() => store.session?.job_id ?? store.session?.id ?? store.session?.session_id)

/**
 * =====================================================================
 * CHỨC NĂNG: Tải capability và khởi tạo lựa chọn cho target
 * =====================================================================
 * INPUT: targetType/operation từ props.
 * OUTPUT: Capability và form options tương thích target.
 * SIDE EFFECT: Gọi capability API qua store, cập nhật state; để trống model nhằm dùng default server.
 * EXCEPTION/TRANSACTION: Lỗi tải capability truyền lên lifecycle caller; không tự gọi provider.
 * =====================================================================
 */
const load = async () => {
  message.value = ''

  const result = await store.loadCapabilities(props.targetType)

  form.operation = props.operation
  form.inputType = (result.input_types ?? result.inputs ?? ['url'])[0]
  form.outputs = [...(result.outputs ?? [])]

  /**
   * =====================================================================
   * GHI CHÚ: Để trống provider/model để server dùng default theo capability.
   * =====================================================================
   * Không suy đoán default ở browser; resolver server-side là source of truth.
   * =====================================================================
   */
  form.provider = ''
}

/**
 * =====================================================================
 * CHỨC NĂNG: Tạo AI Agent request từ form
 * =====================================================================
 * INPUT: Form đang chọn và target props.
 * OUTPUT: Payload canonical cho input/output/prompt/provider/model.
 * SIDE EFFECT: Hàm thuần; không gửi request hoặc ghi Post.
 * EXCEPTION/TRANSACTION: Validation thực tế do backend đảm nhiệm.
 * =====================================================================
 */
const buildRequest = () => ({
  target_type: props.targetType, operation: form.operation,
  ...(props.targetId ? { target_id: props.targetId } : {}),
  input: { type: form.inputType, ...(form.inputType === 'url' ? { url: form.inputValue } : { text: form.inputValue }) },
  output_language: form.language, selection_mode: form.selectionMode,
  ...(form.instructions ? { instructions: form.instructions } : {}),
  ...(form.selectionMode === 'manual' && form.promptKey ? { prompt_key: form.promptKey } : {}),
  ...(form.provider ? { provider: form.provider } : {}),
  ...(form.model ? { model: form.model } : {}),
  requested_outputs: form.outputs,
})

/**
 * =====================================================================
 * CHỨC NĂNG: Dừng timer và vô hiệu callback của vòng polling cũ
 * =====================================================================
 * INPUT: Không có đối số; dùng dialog lifecycle.
 * OUTPUT: Polling state về idle; job backend vẫn chạy.
 * SIDE EFFECT: Hủy timeout và tăng generation token.
 * EXCEPTION/TRANSACTION: Không gọi API hoặc hủy job backend ngầm.
 * =====================================================================
 */
const stopPolling = () => {
  pollingGeneration++
  clearTimeout(pollingTimer)
  polling.value = false
}

/**
 * =====================================================================
 * CHỨC NĂNG: Theo dõi run đến trạng thái terminal với backoff
 * =====================================================================
 * INPUT: Response session/job queued.
 * OUTPUT: Tiến trình/candidate trong store và trạng thái polling.
 * SIDE EFFECT: Gọi status API định kỳ; tạo timeout có thể hủy.
 * EXCEPTION/TRANSACTION: Lỗi polling hiển thị trong dialog; không gọi provider trực tiếp.
 * =====================================================================
 */
const pollUntilDone = async response => {
  const id = response?.job_id ?? response?.id ?? response?.session_id
  if (!id || ['ready', 'completed', 'succeeded', 'failed', 'cancelled', 'expired'].includes(response.status)) return
  const generation = ++pollingGeneration

  polling.value = true
  let attempts = 0

  const tick = async () => {
    if (generation !== pollingGeneration || !visible.value) return
    try {
      const result = await store.poll(id, props.targetType)
      if (generation !== pollingGeneration) return
      if (['ready', 'completed', 'succeeded', 'failed', 'cancelled', 'expired'].includes(result?.status)) {
        polling.value = false
        message.value = result?.error || ''

        return
      }
      if (++attempts >= 120) {
        polling.value = false
        message.value = 'Tác vụ vẫn xử lý trong nền. Hãy kiểm tra lại sau.'

        return
      }
      pollingTimer = setTimeout(tick, Math.min(1000 + attempts * 100, 5000))
    }
    catch (error) { polling.value = false; message.value = error?.data?.message || error.message }
  }

  pollingTimer = setTimeout(tick, 1000)
}

/**
 * =====================================================================
 * CHỨC NĂNG: Xếp hàng session nội dung từ form hiện tại
 * =====================================================================
 * INPUT: Input có nội dung và ít nhất một output đã chọn.
 * OUTPUT: Session/candidate mới và vòng polling.
 * SIDE EFFECT: Gọi start API qua store; không tự lưu Post.
 * EXCEPTION/TRANSACTION: Lỗi API được chuyển thành message cho người dùng.
 * =====================================================================
 */
const run = async () => {
  if (!form.inputValue.trim() || !form.outputs.length || busy.value) return
  message.value = ''
  try { await pollUntilDone(await store.start(buildRequest())) }
  catch (error) { message.value = error?.data?.message || error.message }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Tạo candidate mới theo prompt/model/fields đã chọn
 * =====================================================================
 * INPUT: Session hiện tại và lựa chọn override.
 * OUTPUT: Child candidate mới, giữ candidate cũ.
 * SIDE EFFECT: Gọi regenerate API qua store rồi polling.
 * EXCEPTION/TRANSACTION: Lỗi API hiển thị trong dialog.
 * =====================================================================
 */
const regenerate = async () => {
  if (!sessionId.value || busy.value) return
  try { await pollUntilDone(await store.regenerate(sessionId.value, buildAiContentRegenerateRequest({
    fields: selectedFields.value, instructions: form.instructions,
    prompt_key: form.selectionMode === 'manual' ? form.promptKey : '', provider: form.provider, model: form.model,
  }))) }
  catch (error) { message.value = error?.data?.message || error.message }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Áp dụng các field đã chọn theo chế độ preview hoặc lưu draft
 * =====================================================================
 * INPUT: Candidate ready và danh sách field selected.
 * OUTPUT: Event apply hoặc applied.
 * SIDE EFFECT: Emit payload cho parent; persistOnApply=true gọi API lưu draft.
 * EXCEPTION/TRANSACTION: Lỗi apply hiển thị trong dialog; backend kiểm tra provenance khi lưu.
 * =====================================================================
 */
const apply = async () => {
  if (!currentCandidate.value || !selectedFields.value.length) return
  const output = currentCandidate.value.outputs ?? currentCandidate.value.draft ?? {}
  const payload = Object.fromEntries(selectedFields.value.filter(key => key in output).map(key => [key, output[key]?.value ?? output[key]]))
  try {
    if (props.persistOnApply) emit('applied', await store.apply(currentCandidate.value.id, { fields: selectedFields.value, target_type: props.targetType, target_id: props.targetId }))
    else emit('apply', payload)
    visible.value = false
  }
  catch (error) { message.value = error?.data?.message || error.message }
}

const cancel = async () => {
  try { await store.cancel(sessionId.value); stopPolling() }
  catch (error) { message.value = error?.data?.message || error.message }
}

watch(visible, value => { if (value) load(); else stopPolling() })
watch(() => form.provider, () => { form.model = '' })
watch(currentCandidate, candidate => { selectedFields.value = Object.keys(candidate?.outputs ?? candidate?.draft ?? {}) })
onBeforeUnmount(stopPolling)
</script>

<template>
  <VDialog
    v-model="visible"
    max-width="1120"
    scrollable
    data-testid="ai-agent-dialog"
  >
    <AppDialogLayout @close="visible = false">
      <template #header>
        <VCardItem
          title="Tạo nội dung bằng AI"
          :subtitle="`Tài nguyên: ${props.targetType} — kết quả chỉ là đề xuất, không tự publish.`"
        />
      </template>
      <VCardText>
        <VAlert
          v-if="message"
          type="warning"
          variant="tonal"
          class="mb-4"
        >
          {{ message }}
        </VAlert>
        <VRow>
          <VCol
            cols="12"
            md="6"
          >
            <AppSelect
              v-model="form.operation"
              label="Tác vụ"
              :items="capability.operations ?? []"
              :disabled="busy"
            />
          </VCol>
          <VCol
            cols="12"
            md="6"
          >
            <AppSelect
              v-model="form.inputType"
              label="Nguồn dữ liệu"
              :items="inputTypes"
              :disabled="busy"
            />
          </VCol>
          <VCol cols="12">
            <AppTextarea
              v-model="form.inputValue"
              :label="form.inputType === 'url' ? 'URL nguồn' : 'Nội dung nguồn'"
              :rows="form.inputType === 'url' ? 1 : 4"
              :disabled="busy"
            />
          </VCol>
          <VCol
            cols="12"
            md="6"
          >
            <AppSelect
              v-model="form.selectionMode"
              label="Chọn hướng xử lý"
              :items="[{ title: 'Agent tự chọn prompt phù hợp', value: 'auto' }, { title: 'Chọn prompt thủ công', value: 'manual' }]"
              :disabled="busy"
            />
          </VCol>
          <VCol
            cols="12"
            md="6"
          >
            <AppSelect
              v-model="form.language"
              label="Ngôn ngữ đầu ra"
              :items="[{ title: 'Tiếng Việt', value: 'vi' }, { title: 'English', value: 'en' }]"
              :disabled="busy"
            />
          </VCol>
          <VCol
            v-if="form.selectionMode === 'manual'"
            cols="12"
          >
            <AppSelect
              v-model="form.promptKey"
              label="Prompt"
              :items="prompts"
              item-title="label"
              item-value="key"
              :disabled="busy"
            />
          </VCol>
          <VCol cols="12">
            <AppTextarea
              v-model="form.instructions"
              label="Yêu cầu bổ sung"
              placeholder="Ví dụ: giữ nguyên code, viết cho người mới, giảm phần quảng cáo…"
              rows="2"
              :disabled="busy"
            />
          </VCol>
          <VCol
            cols="12"
            md="6"
          >
            <AppSelect
              v-model="form.provider"
              label="Provider"
              :items="providers"
              item-title="label"
              item-value="key"
              :disabled="busy"
            />
          </VCol>
          <VCol
            cols="12"
            md="6"
          >
            <AppSelect
              v-model="form.model"
              label="Model (trống = mặc định)"
              :items="models"
              item-title="label"
              item-value="value"
              clearable
              :disabled="busy"
            />
          </VCol>
          <VCol cols="12">
            <AppSelect
              v-model="form.outputs"
              label="Phần cần tạo"
              :items="outputFields"
              multiple
              chips
              :disabled="busy"
            />
          </VCol>
        </VRow>
        <VProgressLinear
          v-if="busy"
          indeterminate
          class="mt-4"
          color="primary"
        />
        <p
          v-if="store.session"
          class="text-caption mt-3"
        >
          Trạng thái: {{ store.session.status }} — {{ store.session.progress?.label || store.session.message || '' }}
        </p>
        <template v-if="store.candidates.length">
          <AppSelect
            v-model="selectedCandidateId"
            class="my-4"
            label="So sánh / chọn phiên bản"
            :items="candidateItems"
          />
          <AiAgentCandidatePreview
            v-model:selected-fields="selectedFields"
            :candidate="currentCandidate"
            :providers="providers"
          />
          <p class="text-caption mt-3">
            Chỉ các field được chọn mới được áp dụng. Hãy kiểm tra trước khi xác nhận thay nội dung hiện tại.
          </p>
        </template>
      </VCardText>

      <template #footer>
        <VCardActions class="justify-end flex-wrap gap-2 pa-4">
          <VBtn
            variant="text"
            @click="visible = false"
          >
            Đóng
          </VBtn>
          <VBtn
            v-if="busy && sessionId"
            color="error"
            variant="tonal"
            @click="cancel"
          >
            Hủy tác vụ
          </VBtn>
          <VBtn
            v-if="store.candidates.length"
            variant="tonal"
            :disabled="busy"
            @click="regenerate"
          >
            Tạo lại với lựa chọn mới
          </VBtn>
          <VBtn
            variant="flat"
            :loading="busy"
            :disabled="busy || !form.inputValue.trim() || !form.outputs.length"
            @click="run"
          >
            Tạo nội dung
          </VBtn>
          <VBtn
            v-if="currentCandidate"
            variant="flat"
            color="success"
            :disabled="busy || !selectedFields.length"
            @click="apply"
          >
            Áp dụng field đã chọn
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>
