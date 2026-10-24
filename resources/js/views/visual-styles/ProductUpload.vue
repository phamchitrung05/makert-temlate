<!--
  =====================================================================
  CHỨC NĂNG FILE: Khu vực chọn ảnh tham chiếu (multi) cho trang Generate.
  =====================================================================
  Nút `Chọn ảnh` mở MediaLibraryDialog để chọn MỘT MediaAsset image đã có
  trên server; component giữ `assets` là danh sách tối đa 1 asset đã chọn —
  page gộp `media_ids` = assets.id khi generate. Preview dùng
  `file.preview_url`/`file.url` của MediaAssetResource; asset private hiện
  icon placeholder.
  Dùng trong `pages/visual-styles/generate/index.vue`.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - openLibrary(): mở MediaLibraryDialog chọn asset image (single).
  - onLibrarySelect(): nhận asset đã chọn, thay selection hiện tại (tối đa 1).
  - removeAsset(): bỏ asset đã chọn.
  - previews: computed danh sách {kind, name, url, asset} cho thumbnail.
  - props/emits: `assets` vào; 'update:assets' ra.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : props `assets` (MediaAsset[], tối đa 1), `disabled`, `max`.
  - OUTPUT: emit 'update:assets' MediaAsset[].
  - SIDE EFFECT: mở dialog Media Library; không upload file.
  =====================================================================
-->
<script setup>
import { computed, ref } from 'vue'
import MediaLibraryDialog from '@/views/apps/media/field/MediaLibraryDialog.vue'

const props = defineProps({
  assets: { type: Array, default: () => [] },
  disabled: { type: Boolean, default: false },
  max: { type: Number, default: 1 },
})

const emit = defineEmits(['update:assets'])

const isLibraryOpen = ref(false)

// Preview theo MediaAsset đã chọn; asset private không có preview_url → icon
const previews = computed(() => props.assets.map((asset, index) => ({
  kind: 'asset',
  index,
  asset,
  name: asset?.title ?? asset?.file?.original_name ?? `Asset #${asset?.id}`,
  url: asset?.file?.preview_url || asset?.file?.url || null,
})))

// Mở Media Library chọn ảnh tham chiếu đã có trên server (mỗi lần 1 ảnh)
function openLibrary() {
  if (!props.disabled)
    isLibraryOpen.value = true
}

// Nhận asset từ MediaLibraryDialog (single) — thay selection hiện tại
function onLibrarySelect(selection) {
  const asset = Array.isArray(selection) ? selection[0] : selection

  emit('update:assets', asset?.id ? [asset] : [])
}

function removeAsset(index) {
  const next = [...props.assets]

  next.splice(index, 1)
  emit('update:assets', next)
}
</script>

<template>
  <div>
    <div
      class="product-upload__zone rounded-lg pa-4 text-center"
      :class="{ 'product-upload__zone--disabled': disabled }"
    >
      <VIcon
        size="34"
        icon="tabler-photo-library"
        class="mb-2 text-medium-emphasis"
      />
      <div class="text-body-2 font-weight-medium">
        Ảnh tham chiếu
      </div>
      <div class="text-caption text-medium-emphasis mb-2">
        Chọn 1 ảnh từ Media Library
      </div>
      <div class="d-flex justify-center">
        <VBtn
          size="small"
          color="primary"
          variant="tonal"
          prepend-icon="tabler-photo"
          :disabled="disabled"
          @click.stop="openLibrary"
        >
          Chọn ảnh
        </VBtn>
      </div>
    </div>

    <VRow
      v-if="previews.length"
      dense
      class="mt-3"
    >
      <VCol
        v-for="item in previews"
        :key="`${item.kind}-${item.index}`"
        cols="4"
        sm="3"
      >
        <div class="position-relative rounded-lg overflow-hidden product-upload__thumb">
          <VImg
            v-if="item.url"
            :src="item.url"
            cover
            aspect-ratio="1"
          />
          <div
            v-else
            class="d-flex flex-column align-center justify-center product-upload__placeholder"
          >
            <VIcon
              size="26"
              icon="tabler-photo"
              class="text-medium-emphasis"
            />
          </div>
          <VBtn
            icon="tabler-x"
            size="22"
            variant="flat"
            class="product-upload__remove"
            :disabled="disabled"
            @click="removeAsset(item.index)"
          />
        </div>
      </VCol>
    </VRow>

    <MediaLibraryDialog
      v-model:open="isLibraryOpen"
      kind="image"
      field="product.reference_images"
      :multiple="false"
      :initial-selection="assets"
      @select="onLibrarySelect"
    />
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
  aspect-ratio: 1;
  background-color: rgba(var(--v-theme-on-surface), 0.04);
}
.product-upload__placeholder {
  aspect-ratio: 1;
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
