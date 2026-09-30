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
  - run(): tạo session AI từ URL/text và bắt đầu polling.
  - poll(): cập nhật progress/candidate theo trạng thái backend.
  - apply(): emit field được chọn cho PostForm.
  - cancel(): hủy session đang chạy và dừng polling.
  - watcher visible: khởi tạo/dọn polling theo vòng đời dialog.

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

const props = defineProps({ targetId: { type: [Number, String], default: null } })
const emit = defineEmits(['apply', 'applied'])
const visible = defineModel({ type: Boolean, default: false })
const store = useAiAgentStore()
const currentStep = shallowRef(1)
const selectedCandidateId = shallowRef(null)
const selectedFields = shallowRef([])
const message = shallowRef('')
const polling = shallowRef(false)
const form = reactive({ inputType: 'url', inputValue: '', language: 'vi', instructions: '', selectionMode: 'auto', promptKey: '', provider: '', model: '', outputs: [] })
const sourceLanguage = shallowRef('en')
let timer

const capability = computed(() => store.capabilities ?? {})
const candidates = computed(() => store.candidates)
const candidate = computed(() => candidates.value.find(item => String(item.id) === String(selectedCandidateId.value)) ?? candidates.value[0])
const busy = computed(() => store.isLoading || polling.value)
const sessionId = computed(() => store.session?.id ?? store.session?.session_id ?? store.session?.job_id)
const prompts = computed(() => (capability.value.prompts ?? []).map(item => typeof item === 'string' ? { key: item, label: item } : item))
const providers = computed(() => capability.value.providers ?? [])
const models = computed(() => providers.value.find(item => item.key === form.provider)?.models ?? [])

const stepperItems = [
  { title: 'Nhập nguồn', subtitle: 'URL hoặc nội dung nguồn' },
  { title: 'Đang xử lý', subtitle: 'AI phân tích và tạo nội dung' },
  { title: 'Xem trước', subtitle: 'Kiểm tra candidate' },
  { title: 'Hoàn tất', subtitle: 'Áp dụng bản nháp' },
]

/**
 * Input: không có. Output: đưa dialog về trạng thái sạch khi mở lại.
 * Side effect: reset session/candidate trong store và các field cục bộ.
 */
const resetDialogState = () => {
  store.reset()
  currentStep.value = 1
  selectedCandidateId.value = null
  selectedFields.value = []
  message.value = ''
  sourceLanguage.value = 'en'
  Object.assign(form, {
    inputType: 'url', inputValue: '', language: 'vi', instructions: '',
    selectionMode: 'auto', promptKey: '', provider: '', model: '', outputs: [],
  })
}

const load = async () => {
  resetDialogState()
  try {
    const result = await store.loadCapabilities('post')

    form.inputType = result.input_types?.[0] ?? result.inputs?.[0] ?? 'url'
    form.provider = result.providers?.[0]?.key ?? ''
    form.outputs = [...(result.outputs ?? [])]
  }
  catch (error) {
    message.value = error?.data?.message || error.message || 'Không thể tải cấu hình AI.'
  }
}

const buildRequest = () => ({ target_type: 'post', target_id: props.targetId, operation: 'create', input: { type: form.inputType, ...(form.inputType === 'url' ? { url: form.inputValue } : { text: form.inputValue }) }, output_language: form.language, instructions: form.instructions, selection_mode: form.selectionMode, prompt_key: form.selectionMode === 'manual' ? form.promptKey : null, provider: form.provider, model: form.model || null, requested_outputs: form.outputs })

const stop = () => { clearTimeout(timer); polling.value = false }

const poll = async response => {
  const id = response?.id ?? response?.session_id ?? response?.job_id
  if (!id || ['ready', 'completed', 'succeeded'].includes(response.status)) return
  polling.value = true

  const tick = async () => {
    try {
      const result = await store.poll(id, 'post')
      if (['ready', 'completed', 'succeeded', 'failed', 'cancelled', 'expired'].includes(result?.status)) { polling.value = false; currentStep.value = result.status === 'ready' || result.status === 'completed' || result.status === 'succeeded' ? 3 : 2

        return }
      timer = setTimeout(tick, 1200)
    }
    catch (error) { polling.value = false; message.value = error?.data?.message || error.message }
  }

  timer = setTimeout(tick, 800)
}

const run = async () => {
  if (!form.inputValue.trim() || busy.value) return
  message.value = ''
  currentStep.value = 2
  try { await poll(await store.start(buildRequest())) }
  catch (error) { message.value = error?.data?.message || error.message; currentStep.value = 1 }
}

/**
 * Input: output canonical và danh sách field người dùng chọn.
 * Output: payload đúng shape PostForm; không tự lưu hoặc tạo slug.
 */
const toPostPayload = (output, fields) => {
  const payload = {}
  const seo = {}

  const seoMap = {
    focus_keyword: 'focusKeyword', seo_title: 'title', seo_description: 'description',
    canonical_url: 'canonicalUrl', robots_index: 'robotsIndex', robots_follow: 'robotsFollow',
    og_title: 'ogTitle', og_description: 'ogDescription',
  }

  fields.forEach(key => {
    const value = output[key]?.value ?? output[key]

    if (key === 'content' || key === 'content_html') {
      payload.content = output.content_html?.value ?? output.content_html ?? value
    }
    else if (key === 'seo' || key in seoMap) {
      if (key === 'seo' && value && typeof value === 'object') Object.assign(seo, value)
      else seo[seoMap[key]] = value
    }
    else if (key === 'taxonomy') {
      payload.categories = output.category_ids ?? output.suggested_category_ids ?? []
      payload.tags = output.tag_ids ?? output.suggested_tag_ids ?? []
    }
    else if (key === 'category_ids' || key === 'suggested_category_ids') payload.categories = value
    else if (key === 'tag_ids' || key === 'suggested_tag_ids') payload.tags = value
    else payload[key] = value
  })

  if (Object.keys(seo).length) payload.seo = seo

  return payload
}

const apply = () => {
  if (!candidate.value || !selectedFields.value.length) return
  const output = candidate.value.outputs ?? candidate.value.draft ?? {}

  emit('apply', toPostPayload(output, selectedFields.value.filter(key => key in output || ['content', 'seo', 'taxonomy'].includes(key))))
  visible.value = false
}

const regenerate = async () => {
  if (!sessionId.value || busy.value) return
  try { await poll(await store.regenerate(sessionId.value, { ...buildRequest(), fields: selectedFields.value })) }
  catch (error) { message.value = error?.data?.message || error.message }
}

const cancel = async () => { if (sessionId.value) await store.cancel(sessionId.value); stop() }

/** Input: không có. Output: đổi ngôn ngữ nguồn/đích theo UI cũ. */
const swapLanguages = () => { const value = sourceLanguage.value

  sourceLanguage.value = form.language; form.language = value }

watch(visible, open => { if (open) load(); else stop() })
watch(candidate, value => { selectedFields.value = Object.keys(value?.outputs ?? value?.draft ?? {}) })
onBeforeUnmount(stop)
</script>

<template>
  <VDialog
    v-model="visible"
    max-width="1120"
    scrollable
    data-testid="create-with-ai-dialog"
  >
    <DialogCloseBtn @click="visible = false" />
    <div id="view-moi">
      <VCard>
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
                :disabled="!form.inputValue"
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
                :disabled="busy"
              /><AppSelect
                v-model="form.model"
                class="mt-2"
                label="Model"
                :items="models"
                clearable
                :disabled="busy"
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
            color="error"
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
              <ArticleSourcePreviewCard :source-url="form.inputValue" />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AiImportProgressCard
                :steps="[]"
                :elapsed-time="store.session?.elapsed_time || '00:00:00'"
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
            v-if="busy"
            color="error"
            variant="tonal"
            @click="cancel"
          >
            Hủy tác vụ
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
    </div>
  </VDialog>
</template>
