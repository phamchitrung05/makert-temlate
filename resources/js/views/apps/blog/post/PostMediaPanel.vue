<!--
  =====================================================================
  CHỨC NĂNG FILE: Chọn thumbnail và content images cho Post
  =====================================================================

  Component giữ phần media của giao diện Post mới và tái sử dụng MediaAssetField
  để mọi thao tác chọn/upload tuân theo contract Media Library hiện có.

  CÁC HÀM/METHOD TRONG FILE:
  - handleAiImage(): chuyển asset/provenance cho PostForm review trước khi ghi đè.

  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : v-model thumbnail/contentImages, disabled khi form tải/lưu
  - OUTPUT: cập nhật asset đã chọn cho PostForm
  =====================================================================
-->
<script setup>
import { ref } from 'vue'
import MediaAssetField from '@/views/apps/media/field/MediaAssetField.vue'
import AiImageGenerationDialog from '@/components/ai/AiImageGenerationDialog.vue'
import { useMediaCapabilities } from '@/views/apps/media/field/useMediaCapabilities'

const props = defineProps({ disabled: { type: Boolean, default: false }, title: { type: String, default: '' } })
const emit = defineEmits(['aiImageApplied'])
const thumbnail = defineModel('thumbnail', { type: Object, default: null })
const contentImages = defineModel('contentImages', { type: Array, default: () => [] })
const imageDialog = ref(false)
const { canUpload, canAttach } = useMediaCapabilities()

/**
 * =====================================================================
 * CHỨC NĂNG: thumbnail update for PostForm.
 * =====================================================================
 * INPUT: generated asset/provenance.
 * OUTPUT: thumbnail update for PostForm.
 * SIDE EFFECT: Không ghi database hoặc gọi provider.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
const handleAiImage = (asset, provenance) => {
  emit('aiImageApplied', asset, provenance)
}
</script>

<template>
  <VCard class="mb-6">
    <VCardItem>
      <template #title>
        Featured Image
      </template>
    </VCardItem>

    <VCardText>
      <div class="media-picker-shell pa-4 rounded-lg">
        <MediaAssetField
          v-model="thumbnail"
          :disabled="props.disabled"
          :can-attach="canAttach"
          field="post.thumbnail"
          :multiple="false"
          visibility="public"
          label="Post thumbnail"
        />
        <VBtn
          v-if="canUpload"
          class="mt-3"
          variant="tonal"
          color="secondary"
          prepend-icon="tabler-sparkles"
          :disabled="props.disabled"
          @click="imageDialog = true"
        >
          Tạo ảnh bằng AI
        </VBtn>
      </div>
    </VCardText>
  </VCard>

  <AiImageGenerationDialog
    v-model="imageDialog"
    :title="props.title"
    @apply="handleAiImage"
  />

  <VCard class="mb-6">
    <VCardItem>
      <template #title>
        Image Gallery
      </template>
      <template #subtitle>
        Optional
      </template>
    </VCardItem>

    <VCardText>
      <div class="media-picker-shell pa-4 rounded-lg">
        <MediaAssetField
          v-model="contentImages"
          :disabled="props.disabled"
          :can-attach="canAttach"
          field="post.content_images"
          multiple
          visibility="public"
          label="Post content images"
        />
      </div>
    </VCardText>
  </VCard>
</template>

<style scoped>
.media-picker-shell {
  border: 1.5px dashed rgba(var(--v-theme-primary), 0.35);
  background: rgba(var(--v-theme-primary), 0.035);
  transition: border-color 0.2s ease, background-color 0.2s ease;
}

.media-picker-shell:hover {
  border-color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.07);
}
</style>
