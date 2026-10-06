<!--
  =====================================================================
  CHỨC NĂNG FILE: Xem nguồn, kết quả AI, quyết định và lịch sử trước khi duyệt.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: reviewLabel/locked (computed), dateLabel().
  INPUT/OUTPUT CỦA CLASS (tổng thể): state -> emit thao tác GET/biên tập/quyết định.
  SIDE EFFECT: không gọi API, không render HTML nguồn; giữ content đến after-leave.
  Header/footer cố định qua AppDialogLayout; đóng bị khóa khi POST đang chạy.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Trạng thái theo contract Laravel. */
import { computed } from 'vue'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import AiContentComparison from '@/views/ai/content/AiContentComparison.vue'
import AiContentReviewHistory from '@/views/ai/content/AiContentReviewHistory.vue'

const props = defineProps({ state: { type: Object, required: true } })
const emit = defineEmits(['close', 'afterLeave', 'reload', 'historyReload', 'historyMore', 'approve', 'reject', 'edit'])
const reviewLabel = computed(() => ({ pending_review: 'Chờ duyệt', approved: 'Đã duyệt', rejected: 'Từ chối', not_ready: 'Chưa sẵn sàng' }[props.state.detail?.review?.status] ?? 'Đang tải'))
const locked = computed(() => props.state.loading || props.state.busy || Boolean(props.state.error))

/** Input: thời điểm ISO từ server. Output: ngày giờ theo locale hiện tại, không mutation. */
const dateLabel = value => value ? new Date(value).toLocaleString('vi-VN') : ''
</script>

<template>
  <VDialog
    :model-value="props.state.open"
    max-width="1100"
    scrollable
    :persistent="props.state.busy"
    :retain-focus="!props.state.decision"
    @update:model-value="!$event && emit('close')"
    @after-leave="emit('afterLeave')"
  >
    <AppDialogLayout
      title="Duyệt content AI"
      :subtitle="props.state.detail?.draft?.title"
      :close-disabled="props.state.busy"
      close-label="Đóng duyệt content AI"
      @close="emit('close')"
    >
      <VCardText :aria-busy="props.state.loading">
        <VAlert
          v-if="props.state.error"
          type="error"
          variant="tonal"
          class="mb-4"
        >
          {{ props.state.error }}
          <VBtn
            variant="text"
            :disabled="props.state.loading || props.state.busy"
            @click="emit('reload')"
          >
            Thử lại
          </VBtn>
        </VAlert>
        <div class="d-flex flex-wrap align-center gap-3 mb-4">
          <VChip :color="props.state.detail?.review?.status === 'rejected' ? 'error' : 'primary'">
            {{ reviewLabel }}
          </VChip>
          <span v-if="props.state.detail?.review?.reviewed_at">
            {{ props.state.detail.review.reviewed_by?.name ?? 'Không còn thông tin người duyệt' }}
            · {{ dateLabel(props.state.detail.review.reviewed_at) }}
          </span>
          <span
            v-if="props.state.detail?.expires_at"
            class="text-caption text-medium-emphasis"
          >
            Nội dung AI có hạn đến {{ dateLabel(props.state.detail.expires_at) }}
          </span>
        </div>
        <VAlert
          v-if="props.state.detail?.review?.reason"
          variant="tonal"
          color="secondary"
          class="mb-4"
        >
          Lý do: {{ props.state.detail.review.reason }}
        </VAlert>
        <AiContentComparison
          :source="props.state.detail?.source ?? {}"
          :draft="props.state.detail?.draft ?? {}"
        />
        <VRow class="mt-2">
          <VCol cols="12">
            <div class="text-subtitle-2">
              Tóm tắt
            </div>
            <p class="text-body-2 ai-review-value">
              {{ props.state.detail?.draft?.excerpt || 'Chưa có' }}
            </p>
          </VCol>
          <VCol
            cols="12"
            md="6"
          >
            <div class="text-subtitle-2">
              Tiêu đề SEO
            </div>
            <p class="text-body-2 ai-review-value">
              {{ props.state.detail?.draft?.seo_title || 'Chưa có' }}
            </p>
            <div class="text-subtitle-2">
              Từ khóa chính
            </div>
            <p class="text-body-2 ai-review-value">
              {{ props.state.detail?.draft?.focus_keyword || 'Chưa có' }}
            </p>
          </VCol>
          <VCol
            cols="12"
            md="6"
          >
            <div class="text-subtitle-2">
              Mô tả SEO
            </div>
            <p class="text-body-2 ai-review-value">
              {{ props.state.detail?.draft?.seo_description || 'Chưa có' }}
            </p>
          </VCol>
        </VRow>
        <VDivider class="my-4" />
        <AiContentReviewHistory
          :items="props.state.history"
          :pagination="props.state.historyPagination"
          :loading="props.state.historyLoading"
          :error="props.state.historyError"
          @reload="emit('historyReload')"
          @more="emit('historyMore')"
        />
      </VCardText>
      <template #footer>
        <VCardActions>
          <VBtn
            variant="tonal"
            color="secondary"
            :disabled="props.state.busy"
            @click="emit('close')"
          >
            Đóng
          </VBtn>
          <VSpacer />
          <VBtn
            variant="text"
            :disabled="props.state.busy || props.state.loading"
            @click="emit('reload')"
          >
            Tải lại trạng thái
          </VBtn>
          <VBtn
            v-if="props.state.detail?.review?.post_id"
            :to="{ name: 'apps-blog-post-add', query: { post: props.state.detail.review.post_id } }"
            :disabled="locked"
          >
            Mở Post
          </VBtn>
          <template v-if="props.state.detail?.can_review">
            <VBtn
              variant="tonal"
              :disabled="locked"
              @click="emit('edit')"
            >
              Chỉnh sửa
            </VBtn>
            <VBtn
              color="error"
              variant="tonal"
              :disabled="locked || props.state.decisionBlocked"
              @click="emit('reject')"
            >
              Từ chối
            </VBtn>
            <VBtn
              color="success"
              :disabled="locked || props.state.decisionBlocked"
              @click="emit('approve')"
            >
              Duyệt tạo Post nháp
            </VBtn>
          </template>
        </VCardActions>
      </template>
      <template #overlay>
        <VOverlay
          :model-value="props.state.loading"
          contained
          persistent
          class="align-center justify-center"
        >
          <VProgressCircular indeterminate />
        </VOverlay>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>

<style scoped>
.ai-review-value {
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
</style>
