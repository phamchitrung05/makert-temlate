<!--
  =====================================================================
  CHỨC NĂNG FILE: Xem nguồn, kết quả AI, quyết định và lịch sử trước khi duyệt.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: reviewLabel/reviewColor/postId/locked/displayLoading/qualityLabel/qualityColor (computed),
  watcher open, finishEnter(), finishLeave(), dateLabel(), qualityCriterionLabel().
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
const emit = defineEmits(['close', 'afterLeave', 'reload', 'rescore', 'historyReload', 'historyMore', 'approve', 'reject', 'edit'])
const contentActive = shallowRef(false)
const displayLoading = computed(() => props.state.loading || (props.state.open && !contentActive.value))
const reviewLabel = computed(() => ({ pending_review: 'Chờ duyệt', approved: 'Đã duyệt', rejected: 'Từ chối', not_ready: 'Chưa sẵn sàng' }[props.state.detail?.review?.status] ?? 'Đang tải'))
const reviewColor = computed(() => ({ pending_review: 'warning', approved: 'success', rejected: 'error' }[props.state.detail?.review?.status] ?? 'secondary'))
const postId = computed(() => props.state.detail?.review?.post_id || props.state.detail?.applied_target_id)
const locked = computed(() => displayLoading.value || props.state.busy || Boolean(props.state.error))
const quality = computed(() => props.state.detail?.quality_evaluation ?? null)
const qualityScore = computed(() => Number.isFinite(Number(quality.value?.score_total)) ? Number(quality.value.score_total) : null)
const qualityColor = computed(() => quality.value?.status === 'ready' ? (quality.value?.eligibility?.eligible ? 'success' : 'error') : 'warning')

const qualityLabel = computed(() => {
  if (qualityScore.value !== null) return `Đánh giá chất lượng ${qualityScore.value.toFixed(2)}/5`

  return ({ pending: 'Đang chờ đánh giá chất lượng', queued: 'Đang xếp hàng đánh giá', running: 'Đang đánh giá', failed: 'Đánh giá lỗi', expired: 'Điểm đã hết hạn' }[quality.value?.status] ?? 'Chưa có đánh giá chất lượng')
})

/**
 * =====================================================================
 * CHỨC NĂNG: Đổi tên rubric quality sang nhãn tiếng Việt dễ đọc.
 * =====================================================================
 * INPUT: mã tiêu chí từ quality.scores của API.
 * OUTPUT: nhãn tiếng Việt theo allowlist; mã lạ dùng nhãn trung tính.
 * SIDE EFFECT: hàm thuần, không gọi API hoặc mutate detail.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
function qualityCriterionLabel(criterion) {
  return {
    accuracy: 'Độ chính xác',
    source_grounding: 'Bám sát nguồn',
    clarity: 'Độ rõ ràng',
    structure: 'Cấu trúc',
    style: 'Văn phong',
  }[criterion] ?? 'Tiêu chí chất lượng'
}

/**
 * =====================================================================
 * CHỨC NĂNG: Đặt lại cờ hiển thị phần so sánh khi mở review.
 * =====================================================================
 * INPUT: state.open từ composable review.
 * OUTPUT: contentActive false để chờ after-enter trước khi render nội dung dài.
 * SIDE EFFECT: cập nhật ref cục bộ; không gọi API hoặc mutate props.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
watch(() => props.state.open, open => {
  if (open) contentActive.value = false
})

/**
 * =====================================================================
 * CHỨC NĂNG: Bật nội dung review sau khi hiệu ứng mở hoàn tất.
 * =====================================================================
 * INPUT: lifecycle after-enter của VDialog.
 * OUTPUT: contentActive true nếu dialog vẫn mở.
 * SIDE EFFECT: cập nhật ref cục bộ; không gọi API hoặc mutate props.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
function finishEnter() {
  if (props.state.open) contentActive.value = true
}

/**
 * =====================================================================
 * CHỨC NĂNG: Hoàn tất transition đóng review và báo page dọn state.
 * =====================================================================
 * INPUT: lifecycle after-leave của VDialog.
 * OUTPUT: reset cờ hiển thị và emit afterLeave.
 * SIDE EFFECT: emit cục bộ; không gọi API hoặc thay đổi dữ liệu server.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
function finishLeave() {
  if (!props.state.open) contentActive.value = false
  emit('afterLeave')
}

/**
 * =====================================================================
 * CHỨC NĂNG: Định dạng thời điểm review từ server.
 * =====================================================================
 * INPUT: timestamp ISO tùy chọn.
 * OUTPUT: ngày giờ tiếng Việt hoặc nhãn chưa có thời điểm.
 * SIDE EFFECT: hàm thuần, không gọi API hoặc mutate props.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
const dateLabel = value => value && !Number.isNaN(new Date(value).getTime()) ? new Date(value).toLocaleString('vi-VN') : 'Chưa có thời điểm'
</script>

<template>
  <VDialog
    :model-value="props.state.open"
    class="ai-content-review-dialog"
    max-width="1440"
    height="min(1100px, calc(100dvh - 48px))"
    scrollable
    :persistent="props.state.busy"
    :retain-focus="!props.state.decision"
    @update:model-value="!$event && emit('close')"
    @after-enter="finishEnter"
    @after-leave="finishLeave"
  >
    <AppDialogLayout
      id="view-moi"
      title="Duyệt content AI"
      :subtitle="props.state.detail?.draft?.title"
      :close-disabled="props.state.busy"
      close-label="Đóng duyệt content AI"
      @close="emit('close')"
    >
      <VCardText
        :aria-busy="displayLoading"
        class="ai-review-body"
      >
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
          <VChip
            :color="reviewColor"
            size="small"
            variant="tonal"
            :prepend-icon="props.state.detail?.review?.status === 'approved' ? 'tabler-circle-check' : props.state.detail?.review?.status === 'rejected' ? 'tabler-circle-x' : 'tabler-clock'"
          >
            {{ reviewLabel }}
          </VChip>
          <span v-if="props.state.detail?.review?.reviewed_at">
            {{ props.state.detail.review.reviewed_by?.name ?? 'Không còn thông tin người duyệt' }}
            · {{ dateLabel(props.state.detail.review.reviewed_at) }}
          </span>
          <span
            v-if="props.state.detail?.expires_at"
            class="d-flex align-center gap-1 text-caption text-medium-emphasis"
          >
            <VIcon
              icon="tabler-clock"
              size="16"
            />
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
        <VCard
          v-if="quality"
          variant="tonal"
          class="mb-4"
        >
          <VCardText class="d-flex flex-wrap align-center gap-4 py-3">
            <VProgressCircular
              :model-value="(qualityScore ?? 0) * 20"
              :indeterminate="qualityScore === null && ['queued', 'running'].includes(quality.status)"
              :color="qualityColor"
              :size="56"
              :width="6"
              :aria-label="qualityLabel"
            >
              <span class="text-body-2 font-weight-bold">
                {{ qualityScore === null ? '–' : qualityScore.toFixed(1) }}
              </span>
            </VProgressCircular>
            <div class="flex-grow-1">
              <div class="text-subtitle-2">
                {{ qualityLabel }}
              </div>
              <div class="text-caption text-medium-emphasis">
                Rubric {{ quality.rubric_version }} · {{ quality.status === 'ready' ? (quality.eligibility?.eligible ? 'Đủ điều kiện duyệt' : 'Chưa đủ điều kiện duyệt') : 'Chưa thể kết luận' }}
              </div>
              <div
                v-if="quality.eligibility?.reasons?.length"
                class="text-caption text-medium-emphasis mt-1"
              >
                {{ quality.eligibility.reasons.join(' ') }}
              </div>
              <div class="d-flex flex-wrap gap-1 mt-2">
                <VChip
                  v-for="(score, criterion) in quality.scores"
                  :key="criterion"
                  size="x-small"
                  variant="tonal"
                  color="info"
                  prepend-icon="tabler-star"
                >
                  {{ qualityCriterionLabel(criterion) }}: {{ Number(score).toFixed(1) }}/5
                </VChip>
              </div>
            </div>
          </VCardText>
          <VCardText
            v-if="quality.evidence?.length || quality.source_references?.length"
            class="pt-0"
          >
            <div class="text-caption font-weight-medium mb-1">
              Dẫn chứng nguồn
            </div>
            <div
              v-for="evidence in quality.evidence"
              :key="`${evidence.source_block_id}-${evidence.excerpt}`"
              class="text-caption text-medium-emphasis mb-1"
            >
              <strong>{{ evidence.source_block_id }}</strong>: “{{ evidence.excerpt }}” — {{ evidence.reason }}
            </div>
            <div class="text-caption text-disabled">
              Block tham chiếu: {{ quality.source_references?.join(', ') || 'Chưa có' }}
            </div>
          </VCardText>
        </VCard>
        <AiContentComparison
          :source="props.state.detail?.source ?? {}"
          :draft="props.state.detail?.draft ?? {}"
          :loading="displayLoading"
          :active="contentActive"
        />
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
            prepend-icon="tabler-refresh"
            :disabled="props.state.busy || props.state.loading"
            @click="emit('reload')"
          >
            Tải lại trạng thái
          </VBtn>
          <VBtn
            v-if="['failed', 'expired'].includes(quality?.status)"
            variant="text"
            color="warning"
            prepend-icon="tabler-rotate-clockwise"
            :disabled="locked"
            @click="emit('rescore')"
          >
            Chấm lại
          </VBtn>
          <VBtn
            v-if="postId"
            :to="{ name: 'apps-blog-post-add', query: { post: postId } }"
            :disabled="locked"
            variant="tonal"
            color="primary"
            prepend-icon="tabler-external-link"
          >
            Mở Post
          </VBtn>
          <template v-if="props.state.detail?.can_review">
            <VBtn
              v-if="props.state.detail?.can_edit !== false"
              variant="tonal"
              color="primary"
              prepend-icon="tabler-edit"
              :disabled="locked"
              @click="emit('edit')"
            >
              Chỉnh sửa
            </VBtn>
            <VBtn
              color="error"
              variant="tonal"
              prepend-icon="tabler-x"
              :disabled="locked || props.state.decisionBlocked"
              @click="emit('reject')"
            >
              Từ chối
            </VBtn>
            <VBtn
              color="primary"
              variant="flat"
              prepend-icon="tabler-check"
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

.ai-review-body {
  padding: 24px;
}

@media (max-width: 599.98px) {
  .ai-review-body {
    padding: 16px;
  }
}
</style>
