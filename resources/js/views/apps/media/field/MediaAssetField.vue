<!--
  =====================================================================
  CHỨC NĂNG FILE: Custom form field chọn MediaAsset
  =====================================================================

  Field này là lớp tích hợp nhỏ để Resource/Post/Resource Version chỉ cần
  bind v-model và truyền field contract. Việc lưu usage/attach vẫn do form cha
  và API nghiệp vụ xử lý ở bước tích hợp domain.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - selectedAssets(): chuẩn hóa single/multiple model value
  - handleSelect(): cập nhật v-model và phát selection lên form cha
  - clearAsset()/removeAsset(): bỏ asset khỏi field hiện tại

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : modelValue, kind, field, multiple, visibility và capability.
  - OUTPUT: update:modelValue, select và clear.
  =====================================================================
-->
<script setup>
import { computed, shallowRef } from 'vue'
import { useMediaCapabilities } from './useMediaCapabilities'
import { getMediaAssetFieldConfig } from './mediaAssetFields'
import MediaLibraryDialog from './MediaLibraryDialog.vue'

const props = defineProps({
  modelValue: {
    type: [Object, Array],
    default: null,
  },
  kind: {
    type: String,
    default: null,
  },
  field: {
    type: String,
    required: true,
  },
  multiple: {
    type: Boolean,
    default: false,
  },
  visibility: {
    type: String,
    default: null,
  },
  label: {
    type: String,
    default: 'Media',
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  canAttach: {
    type: Boolean,
    default: null,
  },
  canUpload: {
    type: Boolean,
    default: null,
  },
})

const emit = defineEmits(['update:modelValue', 'select', 'clear'])

const {
  canAttach: authCanAttach,
  canUpload: authCanUpload,
} = useMediaCapabilities()

const isPickerOpen = shallowRef(false)

const canAttach = computed(() => props.canAttach ?? authCanAttach.value)
const canUpload = computed(() => props.canUpload ?? authCanUpload.value)
const effectiveKind = computed(() => props.kind || getMediaAssetFieldConfig(props.field)?.kind || 'image')

const selectedAssets = computed(() => {
  if (props.multiple)
    return Array.isArray(props.modelValue) ? props.modelValue : []

  return props.modelValue ? [props.modelValue] : []
})

const isImage = asset => asset?.kind === 'image'
const previewOf = asset => asset?.file?.preview_url || asset?.file?.url || null

const handleSelect = selection => {
  emit('update:modelValue', selection)
  emit('select', selection)
}

const clearAsset = () => {
  emit('update:modelValue', props.multiple ? [] : null)
  emit('clear')
}

const removeAsset = asset => {
  if (!props.multiple)
    return clearAsset()

  emit('update:modelValue', selectedAssets.value.filter(selected => selected.id !== asset.id))
}
</script>

<template>
  <div class="media-asset-field">
    <VLabel class="mb-2">
      {{ props.label }}
    </VLabel>

    <div
      v-if="selectedAssets.length"
      class="d-flex flex-wrap gap-3 mb-3"
    >
      <div
        v-for="asset in selectedAssets"
        :key="asset.id"
        class="media-asset-field__item"
      >
        <VImg
          v-if="isImage(asset) && previewOf(asset)"
          :src="previewOf(asset)"
          width="72"
          height="72"
          cover
          rounded
          alt=""
        />
        <VAvatar
          v-else
          rounded
          size="72"
          color="primary"
          variant="tonal"
        >
          <VIcon icon="tabler-file" />
        </VAvatar>
        <div class="text-body-2 text-truncate mt-1">
          {{ asset.title }}
        </div>
        <VBtn
          icon="tabler-x"
          size="x-small"
          variant="tonal"
          color="error"
          class="media-asset-field__remove"
          :disabled="props.disabled || !canAttach"
          :aria-label="`Remove ${asset.title}`"
          @click="removeAsset(asset)"
        />
      </div>
    </div>

    <div class="d-flex flex-wrap align-center gap-3">
      <VBtn
        variant="tonal"
        prepend-icon="tabler-photo-plus"
        :disabled="props.disabled || !canAttach"
        @click="isPickerOpen = true"
      >
        {{ selectedAssets.length ? 'Change file' : 'Choose file' }}
      </VBtn>
      <VBtn
        v-if="selectedAssets.length"
        variant="text"
        color="error"
        :disabled="props.disabled || !canAttach"
        @click="clearAsset"
      >
        Clear
      </VBtn>
      <span
        v-if="!canAttach"
        class="text-body-2 text-medium-emphasis"
      >
        Bạn không có quyền attach media.
      </span>
    </div>
  </div>

  <MediaLibraryDialog
    v-model:open="isPickerOpen"
    :kind="effectiveKind"
    :field="props.field"
    :multiple="props.multiple"
    :visibility="props.visibility"
    :can-attach="canAttach"
    :can-upload="canUpload"
    @select="handleSelect"
  />
</template>

<style scoped>
.media-asset-field__item {
  position: relative;
  min-inline-size: 90px;
  max-inline-size: 140px;
}

.media-asset-field__remove {
  position: absolute;
  inset-block-start: -8px;
  inset-inline-end: -8px;
}
</style>

