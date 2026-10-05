<script setup>
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
/* eslint-disable camelcase -- Laravel API DTOs keep server field names. */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { aiAgentService } from '@/services/aiAgent'
import { aiImageGenerationService } from '@/services/aiImageGeneration'
import { findProvider, providerModels } from '@/utils/aiModelOptions'

/**
 * =====================================================================
 * Header/footer cố định qua AppDialogLayout; chỉ content ở giữa được cuộn.
  CHỨC NĂNG FILE: Tạo ảnh độc lập với luồng content
 * =====================================================================
 *
 * Dialog cho phép chọn provider/model ảnh hoặc để server lấy default image
 * model. Nó chỉ emit asset/provenance; PostForm quyết định khi nào lưu.
 *
 * CÁC HÀM/METHOD: stop(), load(), providerChanged(), start(), poll(), apply().
 * INPUT: title, dialog lifecycle và lựa chọn prompt/provider/model.
 * OUTPUT: event apply với MediaAsset và lineage của image run.
 * SIDE EFFECT: gọi Admin API/polling; không gọi provider hoặc lưu Post trực tiếp.
 * EXCEPTION/TRANSACTION: lỗi API hiển thị trong dialog; callback cũ bị vô hiệu
 * khi đóng dialog để không làm thay đổi phiên mới.
 * =====================================================================
 */
const props = defineProps({ title: { type: String, default: '' } })
const emit = defineEmits(['apply'])
const visible = defineModel({ type: Boolean, default: false })
const form = ref({ prompt: '', provider: '', model: '', model_id: null })
const capability = ref({ providers: [], image_providers: [] })
const run = ref(null)
const asset = ref(null)
const loading = ref(false)
const message = ref('')
let timer = null
let generation = 0
const providers = computed(() => capability.value.image_providers ?? capability.value.providers ?? [])
const selectedProvider = computed(() => findProvider(providers.value, form.value.provider))
const modelItems = computed(() => providerModels(selectedProvider.value, 'image_generation'))

/**
 * =====================================================================
 * CHỨC NĂNG: Dừng image polling và vô hiệu callback cũ
 * =====================================================================
 * INPUT: dialog lifecycle hoặc unmount.
 * OUTPUT: timer bị hủy, request sau đó không ghi vào state dialog mới.
 * SIDE EFFECT: reset loading để dialog có thể chạy lại khi mở lại.
 * EXCEPTION/TRANSACTION: không gọi API, không mở transaction.
 * =====================================================================
 */
function stop() {
  clearTimeout(timer)
  timer = null
  generation++
  loading.value = false
}

/**
 * =====================================================================
 * CHỨC NĂNG: Khởi tạo phiên dialog và tải các model có capability tạo ảnh
 * =====================================================================
 * INPUT: title prop.
 * OUTPUT: Prompt mặc định theo title và capability options mới.
 * SIDE EFFECT: gọi capabilities API và reset state dialog; không gọi provider trực tiếp.
 * EXCEPTION/TRANSACTION: lỗi tải options hiển thị trong dialog; bỏ qua response của phiên đã đóng.
 * =====================================================================
 */
async function load() {
  stop()

  const token = generation

  run.value = null
  asset.value = null
  message.value = ''
  capability.value = { providers: [], image_providers: [] }
  form.value = {
    prompt: props.title ? `Tạo ảnh đại diện cho bài viết: ${props.title}` : '',
    provider: '', model: '', model_id: null,
  }
  try {
    const options = await aiAgentService.capabilities('post')
    if (token !== generation) return
    capability.value = options
  }
  catch (error) {
    if (token !== generation) return
    message.value = error?.data?.message ?? 'Không thể tải danh sách provider.'
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Xóa model cũ khi đổi provider
 * =====================================================================
 * INPUT: provider selection.
 * OUTPUT: model/model_id được reset để không gửi lựa chọn sai provider.
 * SIDE EFFECT: chỉ thay state form cục bộ.
 * EXCEPTION/TRANSACTION: không gọi API hoặc ghi Post.
 * =====================================================================
 */
function providerChanged() {
  form.value.model = ''
  form.value.model_id = null
}

/**
 * =====================================================================
 * CHỨC NĂNG: Xếp hàng image run độc lập với content run
 * =====================================================================
 * INPUT: prompt và optional provider/model override.
 * OUTPUT: image job UUID; dialog chuyển sang polling.
 * SIDE EFFECT: gọi API server-side; không attach asset vào Post.
 * EXCEPTION/TRANSACTION: lỗi request hiển thị trong dialog; không mở transaction.
 * =====================================================================
 */
async function start() {
  if (!form.value.prompt.trim() || loading.value) return
  const token = ++generation

  loading.value = true
  message.value = ''
  run.value = null
  asset.value = null
  try {
    const response = await aiImageGenerationService.create({
      prompt: form.value.prompt,
      provider: form.value.provider || null,
      model: form.value.model || null,
      model_id: form.value.model_id || null,
      title: props.title || 'AI generated image',
      alt_text: props.title || null,
    })

    if (token !== generation) return
    run.value = response
    poll(run.value.job_id)
  }
  catch (error) {
    if (token !== generation) return
    loading.value = false
    message.value = error?.data?.message ?? error?.message ?? 'Không thể tạo ảnh.'
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Theo dõi image run và tải asset khi ready
 * =====================================================================
 * INPUT: image run UUID.
 * OUTPUT: lifecycle và asset để preview; không cập nhật phiên dialog đã đóng.
 * SIDE EFFECT: polling Admin API, cập nhật asset state và timeout; không gọi provider trực tiếp.
 * EXCEPTION/TRANSACTION: lỗi/timeout polling hiển thị trong dialog; không mở transaction.
 * =====================================================================
 */
function poll(id) {
  const token = ++generation
  let attempts = 0

  const tick = async () => {
    if (token !== generation) return
    try {
      const status = await aiImageGenerationService.status(id)
      if (token !== generation) return
      run.value = status
      if (run.value.status === 'ready') {
        const assetId = run.value.image?.media_asset_id
        const resolvedAsset = run.value.image?.asset ?? (assetId ? await aiImageGenerationService.asset(assetId) : null)

        if (token !== generation) return
        asset.value = resolvedAsset
        loading.value = false

        return
      }
      if (['failed', 'cancelled', 'expired'].includes(run.value.status)) {
        loading.value = false
        message.value = run.value.error ?? 'Provider không tạo được ảnh.'

        return
      }
      if (++attempts >= 240) {
        loading.value = false
        message.value = 'Tác vụ vẫn đang xử lý. Hãy kiểm tra lại sau.'

        return
      }
      timer = setTimeout(tick, 1200)
    }
    catch (error) {
      if (token !== generation) return
      loading.value = false
      message.value = error?.data?.message ?? error?.message ?? 'Không thể đọc trạng thái tạo ảnh.'
    }
  }

  timer = setTimeout(tick, 700)
}

/**
 * =====================================================================
 * CHỨC NĂNG: Chuyển asset đã chọn và lineage về PostForm
 * =====================================================================
 * INPUT: ready asset.
 * OUTPUT: event apply với asset, run ID và field thumbnail.
 * SIDE EFFECT: emit lên parent và đóng dialog; không tự lưu Post.
 * EXCEPTION/TRANSACTION: không gọi API hoặc mở transaction.
 * =====================================================================
 */
function apply() {
  if (!asset.value || !run.value) return
  emit('apply', asset.value, { runId: run.value.job_id, fields: ['thumbnail'] })
  visible.value = false
}

watch(visible, value => value ? load() : stop(), { immediate: true })
onBeforeUnmount(stop)
</script>

<template>
  <VDialog
    v-if="visible"
    v-model="visible"
    max-width="680"
    scrollable
  >
    <AppDialogLayout @close="visible = false">
      <template #header>
        <VCardItem>
          <VCardTitle>Tạo thumbnail bằng AI</VCardTitle>
        </VCardItem>
      </template>
      <VCardText>
        <VTextarea
          v-model="form.prompt"
          label="Prompt tạo ảnh"
          rows="4"
          class="mb-4"
        />
        <VRow>
          <VCol
            cols="12"
            md="6"
          >
            <VSelect
              v-model="form.provider"
              :items="providers"
              item-title="label"
              item-value="key"
              label="Provider (tuỳ chọn)"
              clearable
              @update:model-value="providerChanged"
            />
          </VCol>
          <VCol
            cols="12"
            md="6"
          >
            <VSelect
              v-model="form.model"
              :items="modelItems"
              item-title="label"
              item-value="value"
              label="Model ảnh (tuỳ chọn)"
              clearable
            />
          </VCol>
        </VRow>
        <VAlert
          v-if="message"
          type="error"
          variant="tonal"
          class="mb-4"
        >
          {{ message }}
        </VAlert>
        <VProgressLinear
          v-if="loading"
          indeterminate
          color="primary"
          class="mb-4"
        />
        <VImg
          v-if="asset?.file?.preview_url || asset?.file?.url"
          :src="asset.file.preview_url || asset.file.url"
          max-height="260"
          cover
          rounded="lg"
        />
        <VAlert
          v-if="run?.status === 'ready' && !asset"
          type="warning"
          variant="tonal"
        >
          Ảnh đã tạo nhưng không tải được asset để chọn.
        </VAlert>
      </VCardText>
      <template #footer>
        <VCardActions class="justify-end">
          <VBtn
            variant="text"
            @click="visible = false"
          >
            Đóng
          </VBtn><VBtn
            v-if="!asset"
            variant="flat"
            color="primary"
            :loading="loading"
            :disabled="!form.prompt.trim()"
            @click="start"
          >
            Tạo ảnh
          </VBtn><VBtn
            v-else
            variant="flat"
            color="primary"
            @click="apply"
          >
            Dùng thumbnail này
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>
