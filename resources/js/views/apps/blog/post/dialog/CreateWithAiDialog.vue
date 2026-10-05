<!--
  =====================================================================
  CHỨC NĂNG FILE: Dialog tạo nội dung AI cho Post, giữ nguyên hierarchy
  giao diện view-moi và bổ sung capability/prompt/provider dùng chung.
  =====================================================================

  Component được mở từ Post list hoặc Post form. Component điều phối session
  AI qua Pinia store, nhưng chỉ trả candidate/field đã chọn lên parent; không
  tự tạo slug, không tự lưu và không tự publish Post.

  CÁC HÀM/METHOD TRONG FILE:
  - load(): tải capability và reset field theo target Post.
  - resetDialogState(): xóa session và lựa chọn khi mở lại.
  - buildRequest(): chuẩn hóa lựa chọn nguồn/prompt/model thành request.
  - run(): tạo session AI từ URL/text và bắt đầu polling.
  - poll()/tick(), finishPolling(): cập nhật progress/candidate, dừng khi terminal hoặc quá hạn.
  - resumePolling(): tiếp tục kiểm tra run hiện tại, không gửi tạo job mới.
  - apply(): emit payload và nhóm field provenance đúng contract Post.
  - regenerate(): tạo candidate mới từ lựa chọn hiện tại.
  - cancel(): hủy session đang chạy và dừng polling.
  - retryRun(): retry kỹ thuật run lỗi, không tạo candidate lineage mới.
  - progressSteps(): ánh xạ lifecycle backend thành các bước hiển thị.
  - useAiSourcePreview()/writingOptions(): preview HTML gốc và snapshot văn phong/brief;
  regenerate dùng lựa chọn riêng, mặc định giữ snapshot của parent.
  - watcher visible/provider/candidate/form defaults: điều phối state theo vòng đời dialog.
  - onBeforeUnmount(): vô hiệu response và dọn timer khi component bị tháo.
  - stop(), swapLanguages(): dọn timer và đổi ngôn ngữ nguồn/đích.
  - capability, generationOutputs, candidates, candidate, busy, sessionId, prompts, providers, models,
  elapsedTime, progressSteps: đọc state/allowlist để render; watcher candidate/provider
  cập nhật field selection và xóa model không thuộc provider mới.

  INPUT/OUTPUT CỦA CLASS (tổng thể):
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
import { buildAiContentRegenerateRequest, withoutAiTaxonomyOutputs } from '@/utils/aiContentInput'
import { formatAiError, isAiSuccess } from '@/utils/aiErrors'
import { useAiRunFeedback } from '@/composables/useAiRunFeedback'
import { getAlertColor } from '@/config/alertColors'
import { articleRequestBody, articleSourcePayload, emptyWritingPreferences, pipelineProgress, writingOptions } from '@/utils/aiArticleOptions'
import { useAiSourcePreview } from '@/composables/ai/useAiSourcePreview'
import AiWritingPreferences from '@/views/ai/shared/AiWritingPreferences.vue'
import AiSourcePreview from '@/views/ai/shared/AiSourcePreview.vue'
import AiPipelineReport from '@/views/ai/shared/AiPipelineReport.vue'

const props = defineProps({ targetId: { type: [Number, String], default: null }, categoryIds: { type: Array, default: () => [] }, tagIds: { type: Array, default: () => [] } })
const emit = defineEmits(['apply', 'applied'])
const visible = defineModel({ type: Boolean, default: false })
const store = useAiAgentStore()
const { snackbar, observeRun, setSnackbarVisible } = useAiRunFeedback()
const currentStep = shallowRef(1)
const selectedCandidateId = shallowRef(null)
const selectedFields = shallowRef([])
const message = shallowRef('')
const polling = shallowRef(false)
const loadingCapabilities = shallowRef(false)
const pollingPaused = shallowRef(false)
const startedAt = shallowRef(null)
const checkedAt = shallowRef(null)
const form = reactive({ inputType: 'url', inputValue: '', file: null, sourceEncoding: 'UTF-8', writing: emptyWritingPreferences(), language: 'vi', instructions: '', selectionMode: 'auto', promptKey: '', provider: '', model: '', outputs: [] })
const regenerateWriting = shallowRef(emptyWritingPreferences(true))
const regenerateInstructions = shallowRef('')
const refreshSource = shallowRef(false)
const sourcePreview = useAiSourcePreview(() => form)
const sourceLanguage = shallowRef('en')
let timer
let loadGeneration = 0
let disposed = false
const editedDefaultFields = new Set()
let applyingDefaults = false

/**
 * =====================================================================
 * CHỨC NĂNG: Ghi nhận người dùng sửa lựa chọn trước khi capability trả về.
 * =====================================================================
 * INPUT: inputType/outputs thay đổi qua form.
 * OUTPUT: set field đã sửa để default không ghi đè lựa chọn mới.
 * SIDE EFFECT: watcher sync cập nhật set cục bộ; không gọi API.
 * =====================================================================
 */
for (const field of ['inputType', 'outputs']) {
  watch(() => form[field], () => {
    if (!applyingDefaults) editedDefaultFields.add(field)
  }, { deep: true, flush: 'sync' })
}

/**
 * =====================================================================
 * CHỨC NĂNG: Đọc capability hiện tại từ store.
 * =====================================================================
 * INPUT: store.capabilities.
 * OUTPUT: capability hoặc object rỗng khi chưa tải.
 * SIDE EFFECT: computed thuần; không gọi API.
 * =====================================================================
 */
const capability = computed(() => store.capabilities ?? {})

/**
 * =====================================================================
 * CHỨC NĂNG: Lấy output generation từ capability Post.
 * =====================================================================
 * INPUT: output registry backend.
 * OUTPUT: danh sách output AI; bỏ taxonomy/field suggested legacy.
 * SIDE EFFECT: computed thuần; không sửa danh mục và tag của Post.
 * =====================================================================
 */
const generationOutputs = computed(() => withoutAiTaxonomyOutputs(capability.value.outputs ?? []))

/**
 * =====================================================================
 * CHỨC NĂNG: Chọn candidate hợp lệ để review.
 * =====================================================================
 * INPUT: candidates và session lifecycle trong store.
 * OUTPUT: danh sách không chứa output của run đã lỗi/hủy/hết hạn.
 * SIDE EFFECT: computed thuần, không thay đổi store.
 * =====================================================================
 */
const candidates = computed(() => store.candidates.filter(item => (!item.status || isAiSuccess(item))
  && !(['failed', 'cancelled', 'expired'].includes(store.session?.status)
    && String(item.id) === String(store.session?.job_id ?? store.session?.id))))

/**
 * =====================================================================
 * CHỨC NĂNG: Đọc candidate đang chọn hoặc bản hợp lệ đầu tiên.
 * =====================================================================
 * INPUT: danh sách candidates và selectedCandidateId.
 * OUTPUT: candidate đang review hoặc undefined.
 * SIDE EFFECT: computed thuần, không áp dụng vào Post.
 * =====================================================================
 */
const candidate = computed(() => candidates.value.find(item => String(item.id) === String(selectedCandidateId.value)) ?? candidates.value[0])

/**
 * =====================================================================
 * CHỨC NĂNG: Khóa thao tác khi dialog đang tải hoặc polling.
 * =====================================================================
 * INPUT: loading store, polling và loadingCapabilities.
 * OUTPUT: trạng thái busy dùng bởi controls.
 * SIDE EFFECT: computed thuần, không gửi request.
 * =====================================================================
 */
const busy = computed(() => store.isLoading || polling.value || loadingCapabilities.value)

/**
 * =====================================================================
 * CHỨC NĂNG: Xác định UUID run để kiểm tra/thao tác tiếp.
 * =====================================================================
 * INPUT: session trong store.
 * OUTPUT: job_id ưu tiên, hoặc id/session_id tương thích.
 * SIDE EFFECT: computed thuần, không tạo run.
 * =====================================================================
 */
const sessionId = computed(() => store.session?.job_id ?? store.session?.id ?? store.session?.session_id)

/**
 * =====================================================================
 * CHỨC NĂNG: Chuẩn hóa options prompt để render select.
 * =====================================================================
 * INPUT: prompt registry từ capability.
 * OUTPUT: danh sách object key/label cho prompt.
 * SIDE EFFECT: computed thuần, không sửa config backend.
 * =====================================================================
 */
const prompts = computed(() => (capability.value.prompts ?? []).map(item => typeof item === 'string' ? { key: item, label: item } : item))

/**
 * =====================================================================
 * CHỨC NĂNG: Đọc provider catalog công khai cho dialog.
 * =====================================================================
 * INPUT: capability.providers.
 * OUTPUT: options provider hoặc mảng rỗng.
 * SIDE EFFECT: computed thuần, không chứa API key.
 * =====================================================================
 */
const providers = computed(() => capability.value.providers ?? [])

/**
 * =====================================================================
 * CHỨC NĂNG: Lấy model options của provider hiện tại.
 * =====================================================================
 * INPUT: provider được chọn và provider catalog.
 * OUTPUT: danh sách model hợp lệ cho select.
 * SIDE EFFECT: computed thuần, backend vẫn resolve/validate model.
 * =====================================================================
 */
const models = computed(() => providerModels(findProvider(providers.value, form.provider)))
const succeeded = ['ready', 'completed', 'succeeded']
const terminal = [...succeeded, 'failed', 'cancelled', 'expired']

/**
 * =====================================================================
 * CHỨC NĂNG: Định dạng thời gian xử lý trên progress card.
 * =====================================================================
 * INPUT: mốc bắt đầu và lần kiểm tra gần nhất.
 * OUTPUT: chuỗi HH:MM:SS.
 * SIDE EFFECT: computed thuần, không tạo timer.
 * =====================================================================
 */
const elapsedTime = computed(() => {
  const seconds = Math.max(0, Math.floor(((checkedAt.value ?? startedAt.value) - startedAt.value) / 1000))

  return [Math.floor(seconds / 3600), Math.floor(seconds / 60) % 60, seconds % 60]
    .map(value => String(value).padStart(2, '0')).join(':')
})

/**
 * =====================================================================
 * CHỨC NĂNG: Ánh xạ lifecycle thành progress card hiện có.
 * =====================================================================
 * INPUT: status/current_step từ backend và polling state.
 * OUTPUT: bước pending/processing/done kèm nhãn hiển thị.
 * SIDE EFFECT: computed thuần; không điều phối pipeline backend.
 * =====================================================================
 */
const progressSteps = computed(() => pipelineProgress(store.session, polling.value))

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
  setSnackbarVisible(false)
  store.reset()
  currentStep.value = 1
  selectedCandidateId.value = null
  selectedFields.value = []
  message.value = ''
  pollingPaused.value = false
  startedAt.value = null
  checkedAt.value = null
  sourceLanguage.value = 'en'
  applyingDefaults = true
  Object.assign(form, {
    inputType: 'url', inputValue: '', language: 'vi', instructions: '',
    selectionMode: 'auto', promptKey: '', provider: '', model: '', outputs: [],
    writing: emptyWritingPreferences(), file: null, sourceEncoding: 'UTF-8',
  })
  regenerateWriting.value = emptyWritingPreferences(true)
  regenerateInstructions.value = ''
  refreshSource.value = false
  sourcePreview.reset()
  applyingDefaults = false
  editedDefaultFields.clear()
}

/**
 * =====================================================================
 * CHỨC NĂNG: Tải capability và khởi tạo lựa chọn của dialog Post.
 * =====================================================================
 * INPUT: trạng thái dialog đang mở.
 * OUTPUT: options mới nhất; bỏ response của lần mở trước.
 * SIDE EFFECT: GET qua store và reset state cục bộ; không gọi provider.
 * =====================================================================
 */
const load = async () => {
  const generation = ++loadGeneration

  resetDialogState()
  loadingCapabilities.value = true
  try {
    const result = await store.loadCapabilities('post')
    if (generation !== loadGeneration || !visible.value) return

    applyingDefaults = true
    if (!editedDefaultFields.has('inputType'))
      form.inputType = result.input_types?.[0] ?? result.inputs?.[0] ?? 'url'

    /**
     * =====================================================================
     * GHI CHÚ: Không chọn provider/model thì server resolve text model mặc định.
     * =====================================================================
     */
    if (!editedDefaultFields.has('outputs')) {
      const defaults = result.content_defaults ?? {}

      form.outputs = withoutAiTaxonomyOutputs(result.outputs ?? []).filter(output =>
        !(output === 'seo' && defaults.generate_seo === false)
        && !(output === 'thumbnail' && defaults.generate_thumbnail === false))
    }
    applyingDefaults = false
  }
  catch (error) {
    if (generation !== loadGeneration) return
    message.value = formatAiError(error, 'Không thể tải cấu hình AI.')
  }
  finally {
    if (generation === loadGeneration) loadingCapabilities.value = false
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Tạo payload từ nguồn và lựa chọn hiện tại.
 * =====================================================================
 * INPUT: form nguồn/prompt/provider và capability backend.
 * OUTPUT: payload create chỉ yêu cầu output AI được hỗ trợ.
 * SIDE EFFECT: hàm thuần; optional trống được bỏ để server resolve default.
 * =====================================================================
 */
const buildRequest = () => articleRequestBody({
  target_type: 'post', operation: 'create',
  ...(props.targetId ? { target_id: props.targetId } : {}),
  ...(form.inputType === 'file' ? articleSourcePayload(form) : { input: { type: form.inputType, [form.inputType === 'url' ? 'url' : form.inputType === 'html' ? 'html' : 'text']: form.inputValue }, ...(form.inputType === 'html' ? { source_encoding: form.sourceEncoding } : {}) }),
  ...writingOptions(form.writing),
  category_ids: props.categoryIds.map(item => Number(item?.id ?? item)),
  tag_ids: props.tagIds.map(item => Number(item?.id ?? item)),
  output_language: form.language, selection_mode: form.selectionMode,
  ...(form.instructions ? { instructions: form.instructions } : {}),
  ...(form.selectionMode === 'manual' && form.promptKey ? { prompt_key: form.promptKey } : {}),
  ...(form.provider ? { provider: form.provider } : {}),
  ...(form.model ? { model: form.model } : {}),
  requested_outputs: withoutAiTaxonomyOutputs(form.outputs).filter(output => generationOutputs.value.includes(output)),
  generate_thumbnail: form.outputs.includes('thumbnail'),
  thumbnail_mode: 'auto',
})

let pollGeneration = 0

/**
 * =====================================================================
 * CHỨC NĂNG: Dừng kiểm tra trạng thái ở client.
 * =====================================================================
 * INPUT: timer/generation polling hiện tại.
 * OUTPUT: timer dừng và response polling cũ bị vô hiệu.
 * SIDE EFFECT: xóa timer, cập nhật state; không hủy run backend.
 * =====================================================================
 */
const stop = () => { clearTimeout(timer); pollGeneration++; polling.value = false }

/**
 * =====================================================================
 * CHỨC NĂNG: Hoàn tất polling sau response terminal.
 * =====================================================================
 * INPUT: lifecycle response backend.
 * OUTPUT: trạng thái review hoặc lỗi an toàn hiển thị trên dialog.
 * SIDE EFFECT: dừng timer, cập nhật progress và feedback.
 * =====================================================================
 */
const finishPolling = result => {
  stop()
  pollingPaused.value = false
  currentStep.value = succeeded.includes(result.status) ? 3 : 2
  message.value = succeeded.includes(result.status) ? '' : formatAiError(result, 'Tác vụ đã kết thúc trước khi tạo được nội dung.', capability.value.output_options)
  observeRun(result, 'Tác vụ đã kết thúc trước khi tạo được nội dung.', capability.value.output_options)
}

/**
 * =====================================================================
 * CHỨC NĂNG: Kiểm tra trạng thái tuần tự cho đúng UUID run.
 * =====================================================================
 * INPUT: run vừa tạo hoặc đang chờ, ưu tiên job_id child thay vì session cha.
 * OUTPUT: dừng terminal; tạm dừng sau 60 giây chờ hoặc 5 phút xử lý.
 * SIDE EFFECT: GET status; không dispatch/hủy job khi client hết thời gian.
 * =====================================================================
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
  observeRun(response)
  message.value = ''
  pollingPaused.value = false
  polling.value = true

  const pollStartedAt = Date.now()
  let queuedAt = response.status === 'queued' ? pollStartedAt : null
  let pollAttempts = 0

  startedAt.value ??= pollStartedAt
  checkedAt.value = pollStartedAt

  const generation = ++pollGeneration

  /**
   * =====================================================================
   * CHỨC NĂNG: Đọc một lần status và hẹn lần kiểm tra tiếp theo.
   * =====================================================================
   * INPUT: UUID run và generation polling đang hoạt động.
   * OUTPUT: state mới hoặc timer tiếp theo khi run chưa terminal.
   * SIDE EFFECT: GET qua store; bỏ response sau đóng hoặc đổi run.
   * =====================================================================
   */
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
      observeRun(result)
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
      message.value = formatAiError(error?.data, 'Chưa đọc được trạng thái. Tác vụ vẫn được lưu; bấm Kiểm tra tiến trình để kiểm tra lại.')
    }
  }

  timer = setTimeout(tick, 800)
}

/**
 * =====================================================================
 * CHỨC NĂNG: Tiếp tục đọc run khi polling đang tạm dừng.
 * =====================================================================
 * INPUT: session hiện tại trong store.
 * OUTPUT: bắt đầu kiểm tra lại cùng UUID.
 * SIDE EFFECT: GET status; không tạo run mới.
 * =====================================================================
 */
const resumePolling = () => poll(store.session)

/**
 * =====================================================================
 * CHỨC NĂNG: Gửi nguồn để tạo một run AI mới.
 * =====================================================================
 * INPUT: nguồn và lựa chọn form hiện tại.
 * OUTPUT: run queued hoặc lỗi validation/transport trên dialog.
 * SIDE EFFECT: POST qua store và bắt đầu polling; không tự lưu Post.
 * =====================================================================
 */
const run = async () => {
  if ((form.inputType === 'file' ? !form.file : !form.inputValue.trim()) || busy.value || pollingPaused.value) return
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
    message.value = formatAiError(error); currentStep.value = 1
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

/**
 * =====================================================================
 * CHỨC NĂNG: Tạo candidate child theo phần AI được chọn.
 * =====================================================================
 * INPUT: candidate/run cha và field/prompt/model override hiện tại.
 * OUTPUT: run child được polling; taxonomy thủ công không gửi để AI tạo.
 * SIDE EFFECT: POST regenerate qua store; giữ candidate cha.
 * EXCEPTION: lựa chọn chỉ có taxonomy bị từ chối trước khi gửi API.
 * =====================================================================
 */
const regenerate = async () => {
  if (!sessionId.value || busy.value) return
  const generation = loadGeneration

  startedAt.value = null
  checkedAt.value = null
  try {
    const response = await store.regenerate(candidate.value?.id ?? sessionId.value, buildAiContentRegenerateRequest({
      fields: selectedFields.value, instructions: regenerateInstructions.value,
      ...writingOptions(regenerateWriting.value, true), ...(refreshSource.value ? { refresh_source: true } : {}),
      prompt_key: form.selectionMode === 'manual' ? form.promptKey : '', provider: form.provider, model: form.model,
    }))

    if (generation === loadGeneration) await poll(response)
  }
  catch (error) { if (generation === loadGeneration) message.value = formatAiError(error) }
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
  catch (error) { if (generation === loadGeneration) message.value = formatAiError(error) }
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
  catch (error) { if (generation === loadGeneration) message.value = formatAiError(error, 'Không thể hủy tác vụ.') }
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

/**
 * =====================================================================
 * CHỨC NĂNG: Khởi tạo/dọn dialog khi đóng mở.
 * =====================================================================
 * INPUT: visible v-model.
 * OUTPUT: capability mới khi mở, polling/feedback dừng khi đóng.
 * SIDE EFFECT: load GET hoặc dọn timer, vô hiệu response cũ.
 * =====================================================================
 */
watch(visible, open => {
  if (open) load()
  else { loadGeneration++; loadingCapabilities.value = false; setSnackbarVisible(false); stop() }
}, { immediate: true })

/**
 * =====================================================================
 * CHỨC NĂNG: Reset model không thuộc provider mới.
 * =====================================================================
 * INPUT: form.provider thay đổi.
 * OUTPUT: form.model rỗng khi lựa chọn cũ không còn hợp lệ.
 * SIDE EFFECT: cập nhật form cục bộ; không gọi provider.
 * =====================================================================
 */
watch(() => form.provider, () => {
  if (!models.value.some(model => model.value === form.model)) form.model = ''
})

/**
 * =====================================================================
 * CHỨC NĂNG: Khởi tạo field review khi đổi candidate.
 * =====================================================================
 * INPUT: outputs/draft của candidate mới.
 * OUTPUT: các field nội dung được chọn; taxonomy chỉ áp dụng khi chọn tay.
 * SIDE EFFECT: cập nhật selectedFields; không sửa category/tag của Post.
 * =====================================================================
 */
watch(candidate, value => { selectedFields.value = withoutAiTaxonomyOutputs(Object.keys(value?.outputs ?? value?.draft ?? {})) })

/**
 * =====================================================================
 * CHỨC NĂNG: Dọn polling khi component bị tháo.
 * =====================================================================
 * INPUT: lifecycle onBeforeUnmount.
 * OUTPUT: response cũ bị vô hiệu và timer được xóa.
 * SIDE EFFECT: chỉ dọn client; không hủy run backend.
 * =====================================================================
 */
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
            <VFileInput
              v-if="form.inputType === 'file'"
              v-model="form.file"
              label="File HTML nguồn"
              accept=".html,.htm,text/html"
              :disabled="busy"
            />
            <AppTextarea
              v-else-if="['text', 'html'].includes(form.inputType)"
              v-model="form.inputValue"
              :label="form.inputType === 'html' ? 'HTML nguyên bản' : 'Nội dung nguồn'"
              rows="5"
              :disabled="busy"
            />
            <AppTextField
              v-else
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
              :disabled="(form.inputType === 'file' ? !form.file : !form.inputValue) || loadingCapabilities || pollingPaused"
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
              :items="[{ title: 'URL nguồn', value: 'url' }, { title: 'Văn bản', value: 'text' }, { title: 'HTML nguyên bản', value: 'html' }, { title: 'File HTML', value: 'file' }]"
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
        <AppSelect
          v-if="['file', 'html'].includes(form.inputType)"
          v-model="form.sourceEncoding"
          :items="['UTF-8', 'Windows-1252', 'ISO-8859-1']"
          label="Encoding nguồn"
          class="mb-4"
          :disabled="busy"
        />
        <AiSourcePreview
          :snapshot="sourcePreview.snapshot.value"
          :busy="sourcePreview.busy.value"
          :error="sourcePreview.error.value"
          :disabled="busy"
          @read="sourcePreview.read"
        />
        <AiWritingPreferences
          v-model="form.writing"
          :active="visible"
          :disabled="busy"
        />
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
              maxlength="4000"
              :disabled="busy"
            />
          </VCol>
          <VCol cols="12">
            <AppSelect
              v-model="form.outputs"
              label="Các phần cần tạo"
              :items="generationOutputs"
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
        <AiPipelineReport :session="store.session" />
        <VExpansionPanels
          v-if="candidate"
          class="mt-4"
        >
          <VExpansionPanel title="Yêu cầu khi tạo lại">
            <VExpansionPanelText>
              <AiWritingPreferences
                v-model="regenerateWriting"
                regenerate
                :parent-profile="store.session?.writing_profile"
                :active="visible"
                :disabled="busy"
              />
              <AppTextarea
                v-model="regenerateInstructions"
                label="Yêu cầu cho lần tạo lại"
                placeholder="Để trống để giữ yêu cầu cũ"
                maxlength="4000"
                rows="3"
                :disabled="busy"
              />
              <VCheckbox
                v-model="refreshSource"
                label="Đọc lại nguồn thay vì snapshot đã lưu"
                :disabled="busy"
              />
            </VExpansionPanelText>
          </VExpansionPanel>
        </VExpansionPanels>
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
</template>
