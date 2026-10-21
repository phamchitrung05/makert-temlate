<!--
  =====================================================================
  CHỨC NĂNG FILE: Khu vực tải ảnh sản phẩm (multi) cho trang Generate.
  =====================================================================
  Cho phép kéo-thả hoặc chọn nhiều ảnh sản phẩm; giữ danh sách File cục bộ và
  emit `update:files` để page dùng trong payload generate. Preview bằng
  URL.createObjectURL; không upload lên server ở component này.
  Dùng trong `pages/visual-styles/generate/index.vue`.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - triggerFileInput(): mở hộp chọn file.
  - handleFileSelect()/handleDrop(): thêm file ảnh vào danh sách.
  - removeFile(): xóa file theo index và revoke object URL.
  - previews: computed danh sách {name, url} cho thumbnail.
  - props/emits: `files` vào; 'update:files' ra.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : prop `files` (File[]), `disabled`, `max`.
  - OUTPUT: emit 'update:files' File[]; hiển thị preview + dropzone.
  - SIDE EFFECT: tạo/revoke ObjectURL cục bộ; không gọi API.
  =====================================================================
-->
<script setup>
import { computed, ref, onScopeDispose } from 'vue'

const props = defineProps({
  files: { type: Array, default: () => [] },
  disabled: { type: Boolean, default: false },
  max: { type: Number, default: 8 },
})

const emit = defineEmits(['update:files'])

const fileInputRef = ref(null)

// Object URL preview theo file; revoke khi file bị xóa/unmount
const previews = computed(() => props.files.map(file => ({
  name: file.name,
  url: URL.createObjectURL(file),
})))

function triggerFileInput() {
  if (!props.disabled)
    fileInputRef.value?.click()
}

// Thêm các file ảnh hợp lệ, giới hạn theo `max`
function addFiles(list) {
  const accepted = [...list].filter(file => file.type?.startsWith('image/'))
  const next = [...props.files, ...accepted].slice(0, props.max)

  emit('update:files', next)
}

function handleFileSelect(event) {
  addFiles(event.target.files ?? [])
  event.target.value = ''
}

function handleDrop(event) {
  if (props.disabled) return
  addFiles(event.dataTransfer.files ?? [])
}

function removeFile(index) {
  const next = [...props.files]

  next.splice(index, 1)
  emit('update:files', next)
}

onScopeDispose(() => {
  previews.value.forEach(item => URL.revokeObjectURL(item.url))
})
</script>

<template>
  <div>
    <div
      class="product-upload__zone rounded-lg pa-4 text-center"
      :class="{ 'product-upload__zone--disabled': disabled }"
      @dragover.prevent
      @drop.prevent="handleDrop"
      @click="triggerFileInput"
    >
      <VIcon
        size="34"
        icon="tabler-cloud-upload"
        class="mb-2 text-medium-emphasis"
      />
      <div class="text-body-2 font-weight-medium">
        Kéo thả ảnh sản phẩm vào đây
      </div>
      <div class="text-caption text-medium-emphasis mb-2">
        hoặc bấm để chọn — JPG, PNG, WEBP (tối đa {{ max }} ảnh)
      </div>
      <VBtn
        size="small"
        color="primary"
        variant="tonal"
        :disabled="disabled"
        @click.stop="triggerFileInput"
      >
        Chọn ảnh
      </VBtn>
      <input
        ref="fileInputRef"
        type="file"
        accept="image/jpeg,image/png,image/webp"
        multiple
        class="d-none"
        @change="handleFileSelect"
      >
    </div>

    <VRow
      v-if="previews.length"
      dense
      class="mt-3"
    >
      <VCol
        v-for="(item, index) in previews"
        :key="index"
        cols="4"
        sm="3"
      >
        <div class="position-relative rounded-lg overflow-hidden product-upload__thumb">
          <VImg
            :src="item.url"
            cover
            aspect-ratio="1"
          />
          <VBtn
            icon="tabler-x"
            size="22"
            variant="flat"
            class="product-upload__remove"
            :disabled="disabled"
            @click="removeFile(index)"
          />
        </div>
      </VCol>
    </VRow>
  </div>
</template>

<style scoped>
.product-upload__zone {
  border: 1.5px dashed rgba(var(--v-theme-primary), 0.35);
  background-color: rgba(var(--v-theme-primary), 0.04);
  cursor: pointer;
  transition: border-color 0.2s ease, background-color 0.2s ease;
}
.product-upload__zone:hover {
  border-color: rgb(var(--v-theme-primary));
  background-color: rgba(var(--v-theme-primary), 0.08);
}
.product-upload__zone--disabled {
  opacity: 0.55;
  pointer-events: none;
}
.product-upload__thumb {
  background-color: rgba(var(--v-theme-on-surface), 0.04);
}
.product-upload__remove {
  position: absolute;
  inset-block-start: 4px;
  inset-inline-end: 4px;
  z-index: 2;
  color: rgb(var(--v-theme-surface));
  background-color: rgba(var(--v-theme-on-surface), 0.55);
}
</style>
