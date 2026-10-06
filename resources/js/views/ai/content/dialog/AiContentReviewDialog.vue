<!--
  =====================================================================
  CHỨC NĂNG FILE: Xem nguồn, kết quả AI, quyết định và lịch sử trước khi duyệt.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: reviewLabel/locked/displayLoading (computed),
  watcher open, finishEnter(), finishLeave(), dateLabel().
  INPUT/OUTPUT CỦA CLASS (tổng thể): state -> emit thao tác GET/biên tập/quyết định.
  SIDE EFFECT: không gọi API, không render HTML nguồn; giữ content đến after-leave.
  Header/footer cố định qua AppDialogLayout; đóng bị khóa khi POST đang chạy.
  Dùng hiệu ứng dialog chuẩn; nguồn dài chỉ render sau after-enter để không khựng.
  Nền fade cùng khung; tiến trình tải nằm trong body, không phủ tối card.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Trạng thái theo contract Laravel. */
import { computed, shallowRef, watch } from 'vue'
import AiThumbnailStatus from '@/views/ai/shared/AiThumbnailStatus.vue'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import AiContentComparison from '@/views/ai/content/AiContentComparison.vue'
import AiContentReviewHistory from '@/views/ai/content/AiContentReviewHistory.vue'

const props = defineProps({ state: { type: Object, required: true } })
const emit = defineEmits(['close', 'afterLeave', 'reload', 'historyReload', 'historyMore', 'approve', 'reject', 'edit'])
const contentActive = shallowRef(false)
const displayLoading = computed(() => props.state.loading || (props.state.open && !contentActive.value))
const reviewLabel = computed(() => ({ pending_review: 'Chờ duyệt', approved: 'Đã duyệt', rejected: 'Từ chối', not_ready: 'Chưa sẵn sàng' }[props.state.detail?.review?.status] ?? 'Đang tải'))
const locked = computed(() => displayLoading.value || props.state.busy || Boolean(props.state.error))

/** Input: mở lại dialog. Output: trì hoãn text dài cho đến khi hiệu ứng mở hoàn tất. */
watch(() => props.state.open, open => {
  if (open) contentActive.value = false
})

/** Input: after-enter. Output: bật nội dung nếu dialog vẫn mở, không gọi API. */
function finishEnter() {
  if (props.state.open) contentActive.value = true
}

/** Input: after-leave. Output: giữ nội dung hết hiệu ứng đóng, rồi báo caller dọn state. */
function finishLeave() {
  if (!props.state.open) contentActive.value = false
  emit('afterLeave')
}

/** Input: thời điểm ISO từ server. Output: ngày giờ theo locale hiện tại, không mutation. */
const dateLabel = value => value ? new Date(value).toLocaleString('vi-VN') : ''
</script>

<template>
  <VDialog
    :model-value="props.state.open"
    class="ai-content-review-dialog"
    max-width="1100"
    scrollable
    :persistent="props.state.busy"
    :retain-focus="!props.state.decision"
    @update:model-value="!$event && emit('close')"
    @after-enter="finishEnter"
    @after-leave="finishLeave"
  >
    <AppDialogLayout
      title="Duyệt content AI"
      :subtitle="props.state.detail?.draft?.title"
      :close-disabled="props.state.busy"
      close-label="Đóng duyệt content AI"
      @close="emit('close')"
    >
      <VCardText :aria-busy="displayLoading">
        <div
          v-if="displayLoading"
          role="status"
          aria-live="polite"
          class="mb-4"
        >
          <p class="text-body-2 mb-2">
            Đang tải nguồn và nội dung đã lưu…
          </p>
          <VProgressLinear
            indeterminate
            aria-label="Đang tải nội dung để duyệt"
          />
        </div>
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
          :loading="displayLoading"
          :active="contentActive"
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
        <AiThumbnailStatus
          :asset="props.state.detail?.thumbnail"
          :generation="props.state.detail?.thumbnail_generation"
        />
        <p
          v-if="props.state.detail?.can_approve === false && props.state.detail?.can_review"
          class="text-body-2 text-medium-emphasis mt-2"
        >
          Chờ thumbnail hoàn tất rồi tải lại trạng thái để duyệt. Có thể hủy tạo ảnh ở danh sách bài.
        </p>
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
              :disabled="locked || props.state.decisionBlocked || props.state.detail?.can_approve === false"
              @click="emit('approve')"
            >
              Duyệt tạo Post nháp
            </VBtn>
          </template>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>

<style scoped>
/* Vuexy giữ opacity nền cố định; trả lại fade và thời lượng tương ứng dialog chuẩn. */
/* stylelint-disable selector-pseudo-class-no-unknown -- :deep() là selector scoped của Vue. */
@media (prefers-reduced-motion: no-preference) {
  .ai-content-review-dialog :deep(.v-overlay__scrim.fade-transition-enter-from),
  .ai-content-review-dialog :deep(.v-overlay__scrim.fade-transition-leave-to) {
    opacity: 0 !important;
  }

  .ai-content-review-dialog :deep(.v-overlay__scrim.fade-transition-enter-active) {
    transition-duration: 225ms !important;
    transition-timing-function: cubic-bezier(0, 0, 0.2, 1) !important;
  }

  .ai-content-review-dialog :deep(.v-overlay__scrim.fade-transition-leave-active) {
    transition-duration: 125ms !important;
    transition-timing-function: cubic-bezier(0.4, 0, 1, 1) !important;
  }
}
/* stylelint-enable selector-pseudo-class-no-unknown */

.ai-review-value {
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
</style>
