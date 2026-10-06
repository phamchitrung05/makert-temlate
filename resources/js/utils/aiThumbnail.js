/**
 * CHỨC NĂNG FILE: Điều kiện theo dõi content và thumbnail dùng chung.
 * HÀM: isAiThumbnailPending(), isAiContentPending().
 * INPUT/OUTPUT: DTO public -> boolean; hàm thuần, không timer hoặc API.
 */
/* eslint-disable camelcase -- DTO public Laravel. */
export const isAiThumbnailPending = run => ['queued', 'generating'].includes(run?.thumbnail_generation?.status)

export const isAiContentPending = run => !['ready', 'completed', 'succeeded', 'failed', 'cancelled', 'expired'].includes(run?.status)
  || (['ready', 'completed', 'succeeded'].includes(run?.status) && !run.applied_target_id && run.review?.status !== 'rejected' && isAiThumbnailPending(run))
