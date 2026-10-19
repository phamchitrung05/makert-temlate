<!--
  =====================================================================
  CHỨC NĂNG FILE: Card preview một Social Post canonical.
  =====================================================================
  Component chỉ hiển thị và emit action; state mutation nằm ở parent/composable.

  CÁC HÀM/METHOD TRONG FILE:
  - statusColor(): map lifecycle sang màu UI.
  - emitAction(): phát action cùng post ID.

  INPUT/OUTPUT CỦA COMPONENT:
  - INPUT : post từ API.
  - OUTPUT: emits view/generate/regenerate/submit/approve/publish/reject.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Status values follow the Laravel Social Post contract. */
const props = defineProps({
  post: { type: Object, required: true },
})

const emit = defineEmits(['view', 'generate', 'regenerate-caption', 'regenerate-image', 'submit-review', 'approve', 'publish', 'reject'])

/** Input: status. Output: Vuetify color. */
function statusColor(status) {
  return {
    draft: 'secondary', generating: 'info', partial: 'warning', ready: 'success',
    pending_review: 'warning', approved: 'success', rejected: 'error',
    published: 'success', failed: 'error', pending: 'secondary', publishing: 'info',
  }[status] ?? 'secondary'
}

/** Input: event name. Output: emit event with ID. */
function emitAction(event) {
  emit(event, props.post.id)
}
</script>

<template>
  <VCard class="social-post-card h-100">
    <VCardItem>
      <template #prepend>
        <VAvatar
          color="primary"
          variant="tonal"
          rounded="lg"
        >
          <VIcon icon="tabler-brand-facebook" />
        </VAvatar>
      </template>
      <VCardTitle class="text-body-1 text-truncate">
        {{ post.campaign?.name || 'Social Post' }}
      </VCardTitle>
      <template #append>
        <VChip
          size="small"
          :color="statusColor(post.reviewStatus)"
          variant="tonal"
        >
          {{ post.reviewStatus || 'draft' }}
        </VChip>
      </template>
    </VCardItem>

    <VCardText class="pt-0">
      <div class="social-post-card__media rounded-lg overflow-hidden mb-4">
        <VImg
          v-if="post.facebook_variant?.images?.[0]?.asset?.file?.preview_url || post.facebook_variant?.images?.[0]?.asset?.file?.url || post.image?.file?.preview_url || post.image?.file?.url"
          :src="post.facebook_variant?.images?.[0]?.asset?.file?.preview_url || post.facebook_variant?.images?.[0]?.asset?.file?.url || post.image.file.preview_url || post.image.file.url"
          cover
          height="190"
          alt="Social Post image"
        />
        <div
          v-else
          class="social-post-card__empty d-flex flex-column align-center justify-center text-medium-emphasis"
        >
          <VIcon
            icon="tabler-photo-off"
            size="32"
          />
          <span class="text-caption mt-2">Chưa có ảnh</span>
        </div>
      </div>
      <p class="text-body-2 line-clamp-4 mb-3">
        {{ post.facebook_variant?.content?.body || post.caption || 'Đang chờ AI tạo content…' }}
      </p>
      <div class="d-flex flex-wrap gap-2 text-caption text-medium-emphasis">
        <span><VIcon
          icon="tabler-sparkles"
          size="14"
        /> {{ post.generationStatus }}</span>
        <span><VIcon
          icon="tabler-share"
          size="14"
        /> {{ post.targets?.length || 0 }} Page</span>
      </div>
    </VCardText>

    <VCardActions class="pt-0 flex-wrap gap-1">
      <VBtn
        size="small"
        variant="text"
        @click="emitAction('view')"
      >
        Chi tiết
      </VBtn>
      <VBtn
        v-if="post.generationStatus !== 'ready'"
        size="small"
        variant="tonal"
        color="info"
        @click="emitAction('generate')"
      >
        Tạo AI
      </VBtn>
      <VBtn
        v-if="post.generationStatus === 'ready'"
        size="small"
        variant="tonal"
        color="warning"
        @click="emitAction('regenerate-caption')"
      >
        Tạo lại caption
      </VBtn>
      <VBtn
        v-if="post.generationStatus === 'ready'"
        size="small"
        variant="tonal"
        color="warning"
        @click="emitAction('regenerate-image')"
      >
        Tạo lại ảnh
      </VBtn>
      <VBtn
        v-if="['draft', 'revision_requested', 'rejected'].includes(post.reviewStatus) && post.generationStatus === 'ready'"
        size="small"
        variant="tonal"
        color="primary"
        @click="emitAction('submit-review')"
      >
        Gửi duyệt
      </VBtn>
      <VBtn
        v-if="post.reviewStatus === 'pending_review'"
        size="small"
        variant="tonal"
        color="success"
        @click="emitAction('approve')"
      >
        Approve
      </VBtn>
      <VBtn
        v-if="post.reviewStatus === 'approved' && post.publicationStatus !== 'published'"
        size="small"
        variant="tonal"
        color="success"
        @click="emitAction('publish')"
      >
        Đăng Facebook
      </VBtn>
      <VBtn
        v-if="post.reviewStatus === 'pending_review'"
        size="small"
        variant="text"
        color="error"
        @click="emitAction('reject')"
      >
        Reject
      </VBtn>
    </VCardActions>
  </VCard>
</template>

<style scoped>
.social-post-card__media { min-height: 190px; background: rgba(var(--v-theme-on-surface), 0.04); }
.social-post-card__empty { min-height: 190px; }
.line-clamp-4 { display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical; overflow: hidden; min-height: 92px; }
</style>
