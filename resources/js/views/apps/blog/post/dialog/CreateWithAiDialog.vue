<!--
  =====================================================================
  CHỨC NĂNG FILE: Dialog tạo nội dung AI cho Post, giữ nguyên hierarchy
  giao diện view-moi và bổ sung capability/prompt/provider dùng chung.
  =====================================================================

  Component được mở từ Post list hoặc Post form. Component điều phối session
  AI qua Pinia store, nhưng chỉ trả candidate/field đã chọn lên parent; không
  tự tạo slug, không tự lưu và không tự publish Post.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - load(): tải capability và reset field theo target Post.
  - resetDialogState(): xóa session và lựa chọn khi mở lại.
  - buildRequest(): chuẩn hóa lựa chọn nguồn/prompt/model thành request.
  - run(): tạo session AI từ URL/text và bắt đầu polling.
  - poll(), finishPolling(): cập nhật progress/candidate, dừng khi terminal hoặc quá hạn.
  - resumePolling(): tiếp tục kiểm tra run hiện tại, không gửi tạo job mới.
  - apply(): emit payload và nhóm field provenance đúng contract Post.
  - regenerate(): tạo candidate mới từ lựa chọn hiện tại.
  - cancel(): hủy session đang chạy và dừng polling.
  - retryRun(): retry kỹ thuật run lỗi, không tạo candidate lineage mới.
  - progressSteps(): ánh xạ lifecycle backend thành các bước hiển thị.
  - watcher visible: khởi tạo/dọn polling theo vòng đời dialog.
  - stop(), swapLanguages(): dọn timer và đổi ngôn ngữ nguồn/đích.
  - capability, candidates, candidate, busy, sessionId, prompts, providers, models,
  elapsedTime, progressSteps: đọc state/allowlist để render; watcher candidate/provider
  cập nhật field selection và xóa model không thuộc provider mới.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : v-model visible, targetId, capability API và dữ liệu candidate.
  - OUTPUT: UI giữ nguyên view-moi, event apply/applied; side effect gọi
  AI Agent API qua Pinia store; không ghi database trực tiếp.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase */
import { computed, onBeforeUnmount, reactive, shallowRef, watch } from 'vue'
import { useAiAgentStore } from '@/stores/aiAgent'
import AiAgentCandidatePreview from '@/components/ai/AiAgentCandidatePreview.vue'
import ArticleSourcePreviewCard from './ArticleSourcePreviewCard.vue'
import AiImportProgressCard from './AiImportProgressCard.vue'
import { toPostPayload } from '@/composables/aiCandidate'
import { findProvider, providerModels } from '@/utils/aiModelOptions'
import { buildAiContentRegenerateRequest } from '@/utils/aiContentInput'

const props = defineProps({ targetId: { type: [Number, String], default: null } })
const emit = defineEmits(['apply', 'applied'])
const visible = defineModel({ type: Boolean, default: false })
const store = useAiAgentStore()
const currentStep = shallowRef(1)
const selectedCandidateId = shallowRef(null)
const selectedFields = shallowRef([])
const message = shallowRef('')
const polling = shallowRef(false)
const loadingCapabilities = shallowRef(false)
const pollingPaused = shallowRef(false)
const startedAt = shallowRef(null)
const checkedAt = shallowRef(null)
const form = reactive({ inputType: 'url', inputValue: '', language: 'vi', instructions: '', selectionMode: 'auto', promptKey: '', provider: '', model: '', outputs: [] })
const sourceLanguage = shallowRef('en')
let timer
let loadGeneration = 0
let disposed = false

const capability = computed(() => store.capabilities ?? {})
const candidates = computed(() => store.candidates)
const candidate = computed(() => candidates.value.find(item => String(item.id) === String(selectedCandidateId.value)) ?? candidates.value[0])
const busy = computed(() => store.isLoading || polling.value || loadingCapabilities.value)
const sessionId = computed(() => store.session?.job_id ?? store.session?.id ?? store.session?.session_id)
const prompts = computed(() => (capability.value.prompts ?? []).map(item => typeof item === 'string' ? { key: item, label: item } : item))
const providers = computed(() => capability.value.providers ?? [])

const models = computed(() => providerModels(findProvider(providers.value, form.provider)))
const succeeded = ['ready', 'completed', 'succeeded']
const terminal = [...succeeded, 'failed', 'cancelled', 'expired']

const elapsedTime = computed(() => {
  const seconds = Math.max(0, Math.floor(((checkedAt.value ?? startedAt.value) - startedAt.value) / 1000))

  return [Math.floor(seconds / 3600), Math.floor(seconds / 60) % 60, seconds % 60]
    .map(value => String(value).padStart(2, '0')).join(':')
})

const progressSteps = computed(() => {
  const status = store.session?.status
  const current = succeeded.includes(status) ? 'ready' : store.session?.current_step || status || 'queued'
  const order = ['queued', 'fetching', 'extracting', 'rewriting', 'seo', 'thumbnail', 'ready']
  const currentIndex = Math.max(0, order.indexOf(current))

  const labels = {
    queued: ['Xếp hàng', 'Đang chờ worker xử lý'],
    fetching: ['Đọc nguồn', 'Tải dữ liệu URL an toàn'],
    extracting: ['Trích xuất', 'Lọc nội dung và metadata'],
    rewriting: ['Tạo nội dung', 'Provider tạo structured candidate'],
    seo: ['Tối ưu SEO', 'Kiểm tra field và taxonomy'],
    thumbnail: ['Xử lý ảnh', 'Chuẩn bị thumbnail nguồn'],
    ready: ['Hoàn tất', 'Candidate sẵn sàng review'],
  }

  return order.map((id, index) => ({
    id: index + 1,
    title: labels[id][0],
    subtitle: labels[id][1],
    status: current === 'ready' || index < currentIndex ? 'done' : current === id && polling.value ? 'processing' : 'pending',
  }))
})

const stepperItems = [
  { title: 'Nhập nguồn', subtitle: 'URL hoặc nội dung nguồn' },
  { title: 'Đang xử lý', subtitle: 'AI phân tích và tạo nội dung' },
  { title: 'Xem trước', subtitle: 'Kiểm tra candidate' },
  { title: 'Hoàn tất', subtitle: 'Áp dụng bản nháp' },
]

/**
 * =====================================================================
 * CHỨC NĂNG: Đưa dialog về trạng thái sạch khi mở lại.
 * =====================================================================
 * INPUT: không có.
 * OUTPUT: state sạch để tạo session mới.
 * SIDE EFFECT: reset session/candidate trong store và các field cục bộ.
 * EXCEPTION: không gọi provider hoặc lưu Post.
 * =====================================================================
 */
const resetDialogState = () => {
  stop()
  store.reset()
  currentStep.value = 1
  selectedCandidateId.value = null
  selectedFields.value = []
  message.value = ''
  pollingPaused.value = false
  startedAt.value = null
  checkedAt.value = null
  sourceLanguage.value = 'en'
  Object.assign(form, {
    inputType: 'url', inputValue: '', language: 'vi', instructions: '',
    selectionMode: 'auto', promptKey: '', provider: '', model: '', outputs: [],
  })
}

/** Input: dialog mở. Output: options mới nhất; bỏ qua response của lần mở cũ. */
const load = async () => {
  const generation = ++loadGeneration

  resetDialogState()
  loadingCapabilities.value = true
  try {
    const result = await store.loadCapabilities('post')
    if (generation !== loadGeneration || !visible.value) return

    form.inputType = result.input_types?.[0] ?? result.inputs?.[0] ?? 'url'

    /**
     * =====================================================================
     * GHI CHÚ: Không chọn provider/model thì server resolve text model mặc định.
     * =====================================================================
     */
    form.provider = ''
    form.outputs = [...(result.outputs ?? [])]
  }
  catch (error) {
    if (generation !== loadGeneration) return
    message.value = error?.data?.message || error.message || 'Không thể tải cấu hình AI.'
  }
  finally {
    if (generation === loadGeneration) loadingCapabilities.value = false
  }
}

/** Input: nguồn và provider. Output: payload create; bỏ optional trống để server dùng default. */
const buildRequest = () => ({
  target_type: 'post', operation: 'create',
  ...(props.targetId ? { target_id: props.targetId } : {}),
  input: { type: form.inputType, ...(form.inputType === 'url' ? { url: form.inputValue } : { text: form.inputValue }) },
  output_language: form.language, selection_mode: form.selectionMode,
  ...(form.instructions ? { instructions: form.instructions } : {}),
  ...(form.selectionMode === 'manual' && form.promptKey ? { prompt_key: form.promptKey } : {}),
  ...(form.provider ? { provider: form.provider } : {}),
  ...(form.model ? { model: form.model } : {}),
  requested_outputs: form.outputs,
})

let pollGeneration = 0

/** Input: không có. Output: dừng timer, vô hiệu response polling cũ. */
const stop = () => { clearTimeout(timer); pollGeneration++; polling.value = false }

/** Input: lifecycle response. Output: kết thúc polling và hiện lỗi an toàn từ backend. */
const finishPolling = result => {
  stop()
  pollingPaused.value = false
  currentStep.value = succeeded.includes(result.status) ? 3 : 2
  message.value = succeeded.includes(result.status) ? '' : result.error || 'Tác vụ đã kết thúc trước khi tạo được nội dung.'
}

/**
 * Input: run vừa tạo hoặc run đang chờ, ưu tiên job_id của child thay vì session cha.
 * Output: poll tuần tự, dừng terminal; tạm dừng sau 60 giây chờ hoặc 5 phút xử lý.
 * Side effect: GET status; không dispatch job hoặc hủy job khi client hết thời gian.
 */
const poll = async response => {
  if (!visible.value || disposed) return
  stop()

  const id = response?.job_id ?? response?.id ?? response?.session_id
  if (!id) return
  if (terminal.includes(response.status)) {
    finishPolling(response)

    return
  }
  message.value = ''
  pollingPaused.value = false
  polling.value = true

  const pollStartedAt = Date.now()
  let queuedAt = response.status === 'queued' ? pollStartedAt : null
  let pollAttempts = 0

  startedAt.value ??= pollStartedAt
  checkedAt.value = pollStartedAt

  const generation = ++pollGeneration

  const tick = async () => {
    if (generation !== pollGeneration) return
    try {
      const result = await store.poll(id, 'post')
      if (generation !== pollGeneration) return
      checkedAt.value = Date.now()
      if (terminal.includes(result?.status)) {
        finishPolling(result)

        return
      }
      queuedAt = result?.status === 'queued' ? queuedAt ?? checkedAt.value : null
      if (queuedAt && checkedAt.value - queuedAt >= 60000 || checkedAt.value - pollStartedAt >= 300000) {
        stop()
        pollingPaused.value = true
        message.value = queuedAt
          ? 'Tác vụ vẫn đang xếp hàng, chưa được worker nhận xử lý. Hãy kiểm tra queue worker rồi bấm Kiểm tra tiến trình.'
          : 'Tác vụ chưa hoàn tất. Đã tạm dừng kiểm tra tự động; bạn có thể kiểm tra tiến trình hoặc hủy tác vụ.'

        return
      }
      pollAttempts += 1
      timer = setTimeout(tick, Math.min((result?.status === 'queued' ? 3000 : 1200) + pollAttempts * 200, 5000))
    }
    catch (error) {
      if (generation !== pollGeneration) return
      stop()
      pollingPaused.value = true
      message.value = error?.data?.message || error.message || 'Không thể kiểm tra tiến trình.'
    }
  }

  timer = setTimeout(tick, 800)
}

/** Input: run đang tạm dừng polling. Output: kiểm tra cùng UUID, không tạo lại run. */
const resumePolling = () => poll(store.session)

/** Input: nguồn đã nhập. Output: tạo một run và chuyển sang progress. */
const run = async () => {
  if (!form.inputValue.trim() || busy.value || pollingPaused.value) return
  const generation = loadGeneration

  message.value = ''
  startedAt.value = null
  checkedAt.value = null
  currentStep.value = 2
  try {
    const response = await store.start(buildRequest())
    if (generation === loadGeneration) await poll(response)
  }
  catch (error) {
    if (generation !== loadGeneration) return
    message.value = error?.data?.message || error.message; currentStep.value = 1
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Phát candidate được chọn để PostForm áp dụng.
 * =====================================================================
 * INPUT: output canonical và danh sách field người dùng chọn.
 * OUTPUT: payload PostForm kèm nhóm field provenance backend chấp nhận;
 * không gửi khóa SEO chi tiết hoặc thumbnail chưa có asset vào lineage.
 * SIDE EFFECT: emit apply và đóng dialog; không tự lưu hoặc tạo slug.
 * EXCEPTION: bỏ qua khi candidate hoặc danh sách field rỗng.
 * =====================================================================
 */
const apply = () => {
  if (!candidate.value || !selectedFields.value.length) return
  const output = candidate.value.outputs ?? candidate.value.draft ?? {}
  const fields = selectedFields.value.filter(key => key in output || ['content', 'seo', 'taxonomy'].includes(key))
  if (!fields.length) return

  const payload = toPostPayload(output, fields)
  const appliedFields = [...new Set(Object.keys(payload).map(key => ['categories', 'tags'].includes(key) ? 'taxonomy' : key))]
  if (!appliedFields.length) return

  emit('apply', payload, {
    runId: candidate.value.id ?? sessionId.value,
    fields: appliedFields,
  })
  visible.value = false
}

const regenerate = async () => {
  if (!sessionId.value || busy.value) return
  const generation = loadGeneration

  startedAt.value = null
  checkedAt.value = null
  try {
    const response = await store.regenerate(sessionId.value, buildAiContentRegenerateRequest({
      fields: selectedFields.value, instructions: form.instructions,
      prompt_key: form.selectionMode === 'manual' ? form.promptKey : '', provider: form.provider, model: form.model,
    }))

    if (generation === loadGeneration) await poll(response)
  }
  catch (error) { if (generation === loadGeneration) message.value = error?.data?.message || error.message }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Queue lại run text đã lỗi và tiếp tục polling.
 * =====================================================================
 * INPUT: session/job ở trạng thái lỗi.
 * OUTPUT: run được queue lại.
 * SIDE EFFECT: gọi AI Agent API; không tự ghi Post.
 * EXCEPTION/TRANSACTION: lỗi transport hiển thị an toàn trên dialog.
 * =====================================================================
 */
const retryRun = async () => {
  if (!sessionId.value || busy.value) return
  const generation = loadGeneration

  message.value = ''
  startedAt.value = null
  checkedAt.value = null
  try {
    const response = await store.retry(sessionId.value)
    if (generation === loadGeneration) await poll(response)
  }
  catch (error) { if (generation === loadGeneration) message.value = error?.data?.message || error.message }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Gửi yêu cầu hủy session đang chạy và dừng polling.
 * =====================================================================
 * INPUT: session hiện tại.
 * OUTPUT: polling dừng ở client.
 * SIDE EFFECT: gọi cancel best-effort; worker server vẫn tự kết thúc an toàn.
 * EXCEPTION/TRANSACTION: lỗi cancel chỉ hiển thị message.
 * =====================================================================
 */
const cancel = async () => {
  const generation = loadGeneration

  try {
    if (sessionId.value) await store.cancel(sessionId.value)
    if (generation === loadGeneration) pollingPaused.value = false
  }
  catch (error) { if (generation === loadGeneration) message.value = error?.data?.message || error.message || 'Không thể hủy tác vụ.' }
  finally {
    if (generation === loadGeneration) stop()
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Hoán đổi ngôn ngữ nguồn/đích của dialog.
 * =====================================================================
 * INPUT: state ngôn ngữ hiện tại.
 * OUTPUT: cập nhật sourceLanguage và form.language.
 * SIDE EFFECT: chỉ đổi state cục bộ; không gọi API.
 * EXCEPTION/TRANSACTION: Không có.
 * =====================================================================
 */
const swapLanguages = () => { const value = sourceLanguage.value

  sourceLanguage.value = form.language; form.language = value }

watch(visible, open => {
  if (open) load()
  else { loadGeneration++; loadingCapabilities.value = false; stop() }
}, { immediate: true })
watch(() => form.provider, () => {
  if (!models.value.some(model => model.value === form.model)) form.model = ''
})
watch(candidate, value => { selectedFields.value = Object.keys(value?.outputs ?? value?.draft ?? {}) })
onBeforeUnmount(() => { disposed = true; loadGeneration++; stop() })
</script>

<template>
  <VDialog
    v-model="visible"
    max-width="1120"
    scrollable
    data-testid="create-with-ai-dialog"
  >
    <DialogCloseBtn @click="visible = false" />
    <VCard id="view-moi">
      <VCardItem>
        <template #prepend>
          <VAvatar
            color="primary"
            variant="tonal"
            rounded="lg"
            size="44"
            class="me-3"
          >
            <VIcon
              icon="tabler-robot"
              size="24"
            />
          </VAvatar>
        </template>
        <VCardTitle>Tạo nội dung bằng AI</VCardTitle>
        <VCardSubtitle>Tạo candidate cho Post bằng prompt và model đã chọn. Nội dung chỉ được áp dụng sau khi bạn xác nhận.</VCardSubtitle>
      </VCardItem>
      <VCardText>
        <VSheet
          border
          rounded="lg"
          class="pa-3 mb-5"
        >
          <AppStepper
            v-model:current-step="currentStep"
            :items="stepperItems"
            :current-step="currentStep"
            is-active-step-valid
            :direction="$vuetify?.display?.smAndDown ? 'vertical' : 'horizontal'"
            align="center"
            icon-size="22"
          />
        </VSheet>
        <VRow
          align="end"
          dense
          class="mb-4"
        >
          <VCol
            cols="12"
            md
          >
            <AppTextField
              v-model="form.inputValue"
              :label="form.inputType === 'url' ? 'URL nguồn' : 'Nội dung nguồn'"
              prepend-inner-icon="tabler-link"
              :disabled="busy"
            />
          </VCol>
          <VCol
            cols="12"
            md="auto"
          >
            <VBtn
              color="primary"
              prepend-icon="tabler-wand"
              class="text-none rounded-lg font-weight-medium"
              :loading="busy"
              :disabled="!form.inputValue || loadingCapabilities || pollingPaused"
              @click="run"
            >
              Bắt đầu tạo
            </VBtn>
          </VCol>
        </VRow>
        <VRow
          dense
          class="mb-4"
        >
          <VCol
            cols="12"
            sm="5"
          >
            <AppSelect
              v-model="sourceLanguage"
              label="Ngôn ngữ nguồn"
              :items="[{ title: 'Tiếng Anh (English)', value: 'en' }, { title: 'Tiếng Việt', value: 'vi' }, { title: 'Tiếng Nhật', value: 'ja' }]"
              :disabled="busy"
            />
          </VCol>
          <VCol
            cols="12"
            sm="2"
            class="d-flex justify-center pt-sm-5"
          >
            <VBtn
              icon
              variant="tonal"
              size="small"
              color="primary"
              aria-label="Đổi ngôn ngữ nguồn và đích"
              @click="swapLanguages"
            >
              <VIcon icon="tabler-arrows-exchange" />
            </VBtn>
          </VCol>
          <VCol
            cols="12"
            sm="5"
          >
            <AppSelect
              v-model="form.language"
              label="Ngôn ngữ đích"
              :items="[{ title: 'Tiếng Việt', value: 'vi' }, { title: 'Tiếng Anh (English)', value: 'en' }, { title: 'Tiếng Nhật', value: 'ja' }]"
              :disabled="busy"
            />
          </VCol>
        </VRow>
        <VRow
          dense
          class="mb-4"
        >
          <VCol
            cols="12"
            sm="6"
          >
            <AppSelect
              v-model="form.inputType"
              label="Loại nguồn"
              :items="capability.input_types ?? ['url', 'text']"
              :disabled="busy"
            />
          </VCol>
          <VCol
            cols="12"
            sm="6"
          >
            <AppSelect
              v-model="form.selectionMode"
              label="Hướng xử lý"
              :items="[{ title: 'Agent tự chọn prompt', value: 'auto' }, { title: 'Chọn prompt thủ công', value: 'manual' }]"
              :disabled="busy"
            />
          </VCol>
        </VRow>
        <VRow
          dense
          class="mb-4"
        >
          <VCol
            v-if="form.selectionMode === 'manual'"
            cols="12"
            sm="6"
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
          <VCol
            cols="12"
            sm="6"
          >
            <AppSelect
              v-model="form.provider"
              label="Provider / model"
              :items="providers"
              item-title="label"
              item-value="key"
              placeholder="Dùng model mặc định của hệ thống"
              clearable
              :loading="loadingCapabilities"
              :disabled="busy"
            /><AppSelect
              v-model="form.model"
              class="mt-2"
              label="Model"
              :items="models"
              item-title="label"
              item-value="value"
              clearable
              :disabled="busy || !form.provider"
            />
          </VCol>
          <VCol cols="12">
            <AppTextarea
              v-model="form.instructions"
              label="Yêu cầu bổ sung"
              placeholder="Giữ nguyên code, viết cho người mới…"
              rows="2"
              :disabled="busy"
            />
          </VCol>
          <VCol cols="12">
            <AppSelect
              v-model="form.outputs"
              label="Các phần cần tạo"
              :items="capability.outputs ?? []"
              multiple
              chips
              :disabled="busy"
            />
          </VCol>
        </VRow>
        <VAlert
          v-if="message"
          :color="pollingPaused ? 'warning' : 'error'"
          variant="tonal"
          class="mb-4"
        >
          {{ message }}
        </VAlert>
        <VProgressLinear
          v-if="busy"
          indeterminate
          color="primary"
          class="mb-4"
        />
        <VRow>
          <VCol
            cols="12"
            md="6"
          >
            <ArticleSourcePreviewCard
              :source-value="form.inputValue"
              :source-type="form.inputType"
            />
          </VCol>
          <VCol
            cols="12"
            md="6"
          >
            <AiImportProgressCard
              :steps="progressSteps"
              :elapsed-time="elapsedTime"
            />
          </VCol>
        </VRow>
        <VAlert
          color="primary"
          variant="tonal"
          icon="tabler-bulb"
          class="mt-4 mb-0"
        >
          Bạn có thể đóng dialog; tác vụ vẫn tiếp tục xử lý ở nền nếu backend hỗ trợ queue.
        </VAlert>
        <AiAgentCandidatePreview
          v-if="candidate"
          v-model:selected-fields="selectedFields"
          :candidate="candidate"
          :providers="providers"
        />
      </VCardText>
      <VDivider />
      <VCardActions class="justify-center gap-3 py-4">
        <VBtn
          variant="tonal"
          color="secondary"
          class="text-none px-5"
          @click="visible = false"
        >
          Hủy
        </VBtn>
        <VBtn
          v-if="polling || pollingPaused"
          color="error"
          variant="tonal"
          @click="cancel"
        >
          Hủy tác vụ
        </VBtn>
        <VBtn
          v-if="pollingPaused"
          color="primary"
          variant="tonal"
          @click="resumePolling"
        >
          Kiểm tra tiến trình
        </VBtn>
        <VBtn
          v-if="candidate"
          variant="tonal"
          :disabled="busy"
          @click="regenerate"
        >
          Tạo lại
        </VBtn>
        <VBtn
          v-if="['failed', 'cancelled', 'expired'].includes(store.session?.status)"
          variant="tonal"
          color="warning"
          :disabled="busy"
          @click="retryRun"
        >
          Thử lại
        </VBtn>
        <VBtn
          v-if="candidate"
          color="primary"
          :disabled="busy || !selectedFields.length"
          class="text-none px-5 font-weight-medium"
          @click="apply"
        >
          Áp dụng bản đã chọn
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
