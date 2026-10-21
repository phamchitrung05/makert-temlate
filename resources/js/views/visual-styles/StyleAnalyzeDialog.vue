<!--
  =====================================================================
  CHỨC NĂNG FILE: Dialog tạo Visual Style mới bằng phân tích ảnh mẫu.
  =====================================================================
  Form gồm: ảnh tham chiếu (kéo-thả/chọn), tên style, category và select
  model Vision (lấy từ AI provider catalog — chỉ model có vision +
  text_generation của openai/openai-compatible/gemini). Gửi multipart qua
  service analyze() → style status=queued, worker phân tích bất đồng bộ.
  Dùng trong `pages/visual-styles/index.vue`.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - onFileSelect()/onDrop(): nhận file ảnh và preview.
  - submit(): validate rồi emit 'submit' {name, model_id, image, category}.
  - close(): phát close; state dọn sau after-leave.
  - modelOptions: computed option {title, value} từ provider catalog.
  - watcher providers: nạp catalog model Vision khi mở.
  - props/emits: modelValue/saving vào; 'update:modelValue','submit','close','after-leave' ra.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : prop modelValue, saving; catalog provider từ useAiProviderSettings.
  - OUTPUT: emit 'submit' payload multipart-ready; preview ảnh cục bộ.
  - SIDE EFFECT: GET catalog AI settings; ObjectURL preview; không gọi provider.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Analyze payload keys follow the Laravel API contract. */
import { computed, ref, watch } from 'vue'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import { useAiProviderSettings } from '@/composables/useAiProviderSettings'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
  errorMessage: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'submit', 'close', 'afterLeave'])

const { providers, load } = useAiProviderSettings()

const name = ref('')
const category = ref('')
const modelId = ref(null)
const imageFile = ref(null)
const imagePreview = ref('')
const error = ref('')
const fileInputRef = ref(null)

// Chỉ model Vision + Text của driver openai/openai-compatible/gemini (khớp backend)
const ALLOWED_DRIVERS = ['openai', 'openai-compatible', 'gemini']

const modelOptions = computed(() => providers.value
  .filter(provider => provider.is_active && provider.has_api_key && ALLOWED_DRIVERS.includes(provider.driver))
  .flatMap(provider => (provider.models ?? [])
    .filter(model => model.is_enabled && model.is_available
      && (model.capabilities ?? []).includes('vision')
      && (model.capabilities ?? []).includes('text_generation'))
    .map(model => ({ title: `${provider.name} · ${model.label || model.remote_model_id}`, value: model.id }))))

watch(() => props.modelValue, async visible => {
  if (!visible) return
  error.value = ''
  if (!providers.value.length) {
    try { await load() } catch { /* error surfaced by composable */ }
  }
})

function pickFile(file) {
  if (!file || !file.type?.startsWith('image/')) {
    error.value = 'Chọn file ảnh JPG, PNG hoặc WEBP.'

    return
  }
  if (file.size > 5 * 1024 * 1024) {
    error.value = 'Ảnh tối đa 5MB.'

    return
  }
  error.value = ''
  imageFile.value = file
  imagePreview.value = URL.createObjectURL(file)
}

function onFileSelect(event) {
  pickFile(event.target.files?.[0])
  event.target.value = ''
}

function onDrop(event) {
  pickFile(event.dataTransfer.files?.[0])
}

function submit() {
  if (!imageFile.value) {
    error.value = 'Cần ảnh tham chiếu để phân tích.'

    return
  }
  if (!name.value.trim()) {
    error.value = 'Tên style là bắt buộc.'

    return
  }
  if (!modelId.value) {
    error.value = 'Chọn model Vision để phân tích.'

    return
  }
  error.value = ''
  emit('submit', {
    name: name.value.trim(),
    model_id: modelId.value,
    image: imageFile.value,
    category: category.value?.trim() || null,
  })
}

function close() {
  emit('update:modelValue', false)
  emit('close')
}

function onAfterLeave() {
  name.value = ''
  category.value = ''
  modelId.value = null
  imageFile.value = null
  imagePreview.value = ''
  error.value = ''
  emit('afterLeave')
}
</script>

<template>
  <VDialog
    :model-value="props.modelValue"
    max-width="640"
    scrollable
    :persistent="props.saving"
    @update:model-value="!$event && close()"
    @after-leave="onAfterLeave"
  >
    <AppDialogLayout
      title="Tạo style từ ảnh"
      subtitle="Tải ảnh tham chiếu — AI phân tích thành Style DNA + gợi ý recipes"
      :close-disabled="props.saving"
      @close="close"
    >
      <VCardText>
        <VAlert
          v-if="error || props.errorMessage"
          type="error"
          variant="tonal"
          class="mb-4"
          density="compact"
        >
          {{ error || props.errorMessage }}
        </VAlert>

        <div
          class="analyze-dropzone rounded-lg pa-4 text-center mb-4"
          @dragover.prevent
          @drop.prevent="onDrop"
        >
          <template v-if="imagePreview">
            <VImg
              :src="imagePreview"
              cover
              height="180"
              class="rounded-lg mb-3"
            />
            <VBtn
              size="small"
              color="secondary"
              variant="tonal"
              @click="imageFile = null; imagePreview = ''"
            >
              Chọn ảnh khác
            </VBtn>
          </template>
          <template v-else>
            <VIcon
              size="36"
              icon="tabler-cloud-upload"
              class="mb-2 text-medium-emphasis"
            />
            <div class="text-body-2 font-weight-medium">
              Kéo thả ảnh vào đây hoặc bấm chọn
            </div>
            <div class="text-caption text-medium-emphasis mb-3">
              JPG, PNG, WEBP — tối đa 5MB
            </div>
            <VBtn
              size="small"
              color="primary"
              @click="fileInputRef?.click()"
            >
              Chọn ảnh
            </VBtn>
          </template>
          <input
            ref="fileInputRef"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            class="d-none"
            @change="onFileSelect"
          >
        </div>

        <AppTextField
          v-model="name"
          label="Tên style *"
          placeholder="Studio ấm — góc nhìn sản phẩm"
          class="mb-3"
        />
        <AppTextField
          v-model="category"
          label="Nhóm phong cách"
          placeholder="ví dụ: Mỹ phẩm, Đồ uống"
          class="mb-3"
        />
        <AppSelect
          v-model="modelId"
          :items="modelOptions"
          label="Model Vision *"
          placeholder="Chọn model có Vision + Text"
          :loading="!providers.length"
          hint="Model phải có capability Vision và Text generation."
          persistent-hint
        />
      </VCardText>

      <template #footer>
        <VCardActions class="justify-end">
          <VBtn
            color="secondary"
            variant="tonal"
            :disabled="props.saving"
            @click="close"
          >
            Hủy
          </VBtn>
          <VBtn
            color="primary"
            prepend-icon="tabler-sparkles"
            :loading="props.saving"
            :disabled="props.saving"
            @click="submit"
          >
            Phân tích
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>

<style scoped>
.analyze-dropzone {
  border: 1.5px dashed rgba(var(--v-theme-primary), 0.35);
  background-color: rgba(var(--v-theme-primary), 0.04);
}
</style>
