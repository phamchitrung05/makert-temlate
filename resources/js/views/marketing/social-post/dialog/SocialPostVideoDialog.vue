<!--
  =====================================================================
  CHỨC NĂNG FILE: Dialog xem video của một Social Post.
  =====================================================================

  Dialog hiển thị video, thumbnail poster và mô tả của Social Post được chọn.
  Nguồn video hiện nhận từ fixture; dữ liệu API thật sẽ thay thế videoUrl sau.
  Header và footer dùng AppDialogLayout của project; chỉ phần nội dung được cuộn.

  CÁC HÀM/METHOD TRONG FILE:
  - close(): đóng dialog và phát event để page đồng bộ trạng thái.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : modelValue và Social Post được chọn qua props.
  - OUTPUT: video player, thông tin bài viết và event đóng dialog.
  =====================================================================
-->
<script setup>
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'

const props = defineProps({
  modelValue: Boolean,
  post: { type: Object, default: null },
})

const emit = defineEmits(['update:modelValue', 'close', 'afterLeave'])

/** Input: thao tác đóng từ header/footer hoặc overlay. Output: yêu cầu page đóng dialog. */
function close() {
  emit('update:modelValue', false)
  emit('close')
}
</script>

<template>
  <VDialog
    :model-value="props.modelValue"
    max-width="960"
    scrollable
    @update:model-value="value => !value && close()"
    @after-leave="emit('afterLeave')"
  >
    <AppDialogLayout
      close-label="Đóng"
      @close="close"
    >
      <template #header>
        <VCardItem>
          <VCardTitle>
            {{ props.post?.title || 'Xem video Social Post' }}
          </VCardTitle>
          <VCardSubtitle>
            Xem trước video và nội dung Social Post.
          </VCardSubtitle>
        </VCardItem>
      </template>

      <VCardText>
        <div
          v-if="props.post?.videoUrl"
          class="social-post-video-dialog__player"
        >
          <video
            class="social-post-video-dialog__video"
            :src="props.post.videoUrl"
            :poster="props.post.thumbnail"
            controls
            playsinline
            preload="metadata"
          />
        </div>
        <VAlert
          v-else
          type="info"
          variant="tonal"
        >
          Social Post này chưa có nguồn video để phát.
        </VAlert>

        <p
          v-if="props.post?.description"
          class="text-body-2 text-medium-emphasis mt-4 mb-0"
        >
          {{ props.post.description }}
        </p>
      </VCardText>

      <template #footer>
        <VCardActions class="justify-end gap-3">
          <VBtn
            variant="tonal"
            color="secondary"
            @click="close"
          >
            Đóng
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>

<style scoped>
.social-post-video-dialog__player {
  overflow: hidden;
  background: rgb(var(--v-theme-surface-variant));
  border-radius: 0.25rem;
}

.social-post-video-dialog__video {
  display: block;
  inline-size: 100%;
  max-block-size: 68vh;
  background: #000;
}
</style>
