<!--
  =====================================================================
  CHỨC NĂNG FILE: Vùng upload file trong Media Picker
  =====================================================================

  Component giữ state file/title cục bộ, hỗ trợ chọn file bằng bàn phím hoặc
  kéo-thả, rồi phát payload lên dialog. Upload thật đi qua Pinia store ở parent.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - setFile()/handleDrop(): nhận file từ input hoặc drag-drop
  - submit(): kiểm tra file và phát payload upload
  - reset(): xóa form cục bộ sau khi dialog đóng

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : kind, visibility, progress, loading, error và capability upload.
  - OUTPUT: emit upload `{ file, kind, title, visibility }`.
  =====================================================================
-->
<script setup>
import { computed, reactive, shallowRef, useTemplateRef, watch } from 'vue'
import { kindAccept } from './mediaAssetFields'

const props = defineProps({
  kind: {
    type: String,
    default: 'image',
  },
  visibility: {
    type: String,
    default: 'public',
  },
  loading: {
    type: Boolean,
    default: false,
  },
  progress: {
    type: Number,
    default: 0,
  },
  error: {
    type: String,
    default: '',
  },
  canUpload: {
    type: Boolean,
    default: true,
  },
})

const emit = defineEmits(['upload'])

const fileInput = useTemplateRef('fileInput')
const form = reactive({ file: null, title: '' })
const isDragging = shallowRef(false)

const accept = computed(() => kindAccept(props.kind))
const isArchive = computed(() => props.kind === 'archive')
const isReady = computed(() => Boolean(form.file && form.title.trim()))

const setFile = file => {
  if (!file || typeof file === 'string')
    return

  form.file = file
  form.title = file.name?.replace(/\.[^/.]+$/, '') || file.name || ''
}

const handleInput = event => {
  setFile(event.target.files?.[0] ?? null)
  event.target.value = ''
}

const handleDrop = event => {
  isDragging.value = false
  setFile(event.dataTransfer?.files?.[0] ?? null)
}

const submit = () => {
  if (!props.canUpload || props.loading || !isReady.value)
    return

  emit('upload', {
    file: form.file,
    kind: props.kind,
    title: form.title.trim(),
    visibility: isArchive.value ? 'private' : props.visibility,
  })
}

const reset = () => {
  form.file = null
  form.title = ''
  isDragging.value = false
}

watch(() => props.loading, isLoading => {
  if (!isLoading && props.progress === 100)
    reset()
})

defineExpose({ reset })
</script>

<template>
  <div>
    <div
      class="media-upload-drop-zone"
      :class="{ 'media-upload-drop-zone--dragging': isDragging, 'media-upload-drop-zone--disabled': !props.canUpload || props.loading }"
      role="button"
      tabindex="0"
      :aria-disabled="!props.canUpload || props.loading"
      @click="props.canUpload && !props.loading && fileInput?.click()"
      @keydown.enter.prevent="props.canUpload && !props.loading && fileInput?.click()"
      @keydown.space.prevent="props.canUpload && !props.loading && fileInput?.click()"
      @dragover.prevent="isDragging = true"
      @dragleave.prevent="isDragging = false"
      @drop.prevent="handleDrop"
    >
      <input
        ref="fileInput"
        class="d-none"
        type="file"
        :accept="accept"
        :disabled="!props.canUpload || props.loading"
        @change="handleInput"
      >
      <VIcon
        icon="tabler-cloud-upload"
        size="32"
        color="primary"
      />
      <div class="text-body-1 font-weight-medium mt-2">
        {{ form.file ? form.file.name : 'Kéo file vào đây hoặc bấm để chọn' }}
      </div>
      <div class="text-body-2 text-medium-emphasis mt-1">
        Loại file: {{ props.kind }}
      </div>
    </div>

    <VTextField
      v-if="form.file"
      v-model="form.title"
      class="mt-4"
      label="Title"
      :disabled="props.loading || !props.canUpload"
    />

    <VProgressLinear
      v-if="props.loading"
      class="mt-4"
      color="primary"
      :model-value="props.progress"
    />

    <VAlert
      v-if="props.error"
      class="mt-4"
      color="error"
      variant="tonal"
    >
      {{ props.error }}
    </VAlert>

    <div class="d-flex justify-end mt-4">
      <VBtn
        :disabled="!props.canUpload || props.loading || !isReady"
        :loading="props.loading"
        prepend-icon="tabler-upload"
        @click="submit"
      >
        Upload
      </VBtn>
    </div>
  </div>
</template>

<style scoped>
.media-upload-drop-zone {
  display: flex;
  min-block-size: 150px;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
  border: 1px dashed rgba(var(--v-theme-on-surface), 0.25);
  border-radius: 8px;
  text-align: center;
  transition: border-color 0.2s ease, background-color 0.2s ease;
}

.media-upload-drop-zone:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

.media-upload-drop-zone--dragging {
  border-color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.08);
}

.media-upload-drop-zone--disabled {
  cursor: not-allowed;
  opacity: 0.62;
}
</style>

