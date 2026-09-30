<!--
  =====================================================================
  CHỨC NĂNG FILE: Custom form field chọn MediaAsset
  =====================================================================

  Field này là lớp tích hợp nhỏ để Resource/Post/Resource Version chỉ cần
  bind v-model và truyền field contract. Việc lưu usage/attach vẫn do form cha
  và API nghiệp vụ xử lý ở bước tích hợp domain.

  CÁC HÀM/METHOD TRONG FILE:
  - selectedAssets(): chuẩn hóa single/multiple model value
  - handleSelect(): cập nhật v-model và phát selection lên form cha
  - clearAsset()/removeAsset(): bỏ asset khỏi field hiện tại
  - moveAsset(): đổi thứ tự danh sách bằng mảng mới
  - isImage()/previewOf(): đọc loại và URL preview

  INPUT/OUTPUT CỦA CLASS (tổng thể):
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

/** Input: asset. Output: có phải ảnh hay không. */
const isImage = asset => asset?.kind === 'image'

/** Input: asset. Output: URL preview hoặc null. */
const previewOf = asset => asset?.file?.preview_url || asset?.file?.url || null

/** Input: selection dialog. Output: emit model khi field có quyền và không disabled. */
const handleSelect = selection => {
  if (props.disabled || !canAttach.value)
    return
  emit('update:modelValue', selection)
  emit('select', selection)
}

/** Input: không có. Output: emit field rỗng, không xóa asset trong thư viện. */
const clearAsset = () => {
  if (props.disabled || !canAttach.value)
    return
  emit('update:modelValue', props.multiple ? [] : null)
  emit('clear')
}

/** Input: asset cần bỏ. Output: emit selection còn lại, không ghi API. */
const removeAsset = asset => {
  if (props.disabled || !canAttach.value)
    return
  if (!props.multiple)
    return clearAsset()

  emit('update:modelValue', selectedAssets.value.filter(selected => selected.id !== asset.id))
}

/** Input: index và hướng (-1/1). Output: emit danh sách đã đổi thứ tự, không ghi API. */
const moveAsset = (index, direction) => {
  if (props.disabled || !canAttach.value)
    return
  const next = [...selectedAssets.value]
  const target = index + direction
  if (target < 0 || target >= next.length)
    return
  ;[next[index], next[target]] = [next[target], next[index]]
  emit('update:modelValue', next)
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
        v-for="(asset, index) in selectedAssets"
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
        <div
          v-if="props.multiple"
          class="d-flex"
        >
          <VBtn
            icon="tabler-arrow-left"
            size="x-small"
            variant="text"
            :disabled="props.disabled || !canAttach || index === 0"
            :aria-label="`Move ${asset.title} earlier`"
            @click="moveAsset(index, -1)"
          />
          <VBtn
            icon="tabler-arrow-right"
            size="x-small"
            variant="text"
            :disabled="props.disabled || !canAttach || index === selectedAssets.length - 1"
            :aria-label="`Move ${asset.title} later`"
            @click="moveAsset(index, 1)"
          />
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
    :initial-selection="selectedAssets"
    :can-attach="canAttach && !props.disabled"
    :can-upload="canUpload && !props.disabled"
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

