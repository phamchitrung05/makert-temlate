/* eslint-disable camelcase -- Fixtures theo contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm lifecycle review, quyết định, GET lỗi và callback cũ.
 * CÁC HÀM/METHOD TRONG FILE: beforeEach(), afterEach(), detail(), setup(), deferred(), test cases.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): service giả lập -> assertions state/list/POST.
 * SIDE EFFECT: effectScope cô lập, không gọi API/model thật.
 * =====================================================================
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, shallowRef } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { useAiContentReview } from '@/composables/useAiContentReview'
import { useAiContentWorkspace } from '@/composables/useAiContentWorkspace'

const { service } = vi.hoisted(() => ({ service: {
  review: vi.fn(), reviewHistory: vi.fn(), approveCandidate: vi.fn(), rejectCandidate: vi.fn(), listSessions: vi.fn(),
} }))

vi.mock('@/services/aiAgent', () => ({ aiAgentService: service }))

const item = { id: 'run-a', title: 'Bài AI', targetType: 'post', status: 'review' }
let scope

/** INPUT: overrides. OUTPUT: DTO pending hoàn chỉnh đã GET. */
function detail(overrides = {}) {
  return { job_id: item.id, status: 'ready', target_type: 'post', draft: { title: item.title },
    draft_version: 'draft-v1', review_version: 'review-v1', can_review: true, can_edit: true,
    review: { status: 'pending_review' }, ...overrides }
}

/** INPUT: không có. OUTPUT: workspace và review cùng scope, giữ nguồn tạo mới. */
function setup() {
  return scope.run(() => {
    const workspace = useAiContentWorkspace()

    workspace.updateSession(detail())

    return { ...workspace, ...useAiContentReview(workspace.updateSession) }
  })
}

/** INPUT: không có. OUTPUT: Promise điều khiển thời điểm response. */
function deferred() {
  let resolveRequest
  const promise = new Promise(resolve => { resolveRequest = resolve })

  return { promise, resolve: resolveRequest }
}

beforeEach(() => {
  vi.resetAllMocks()
  scope = effectScope()
  service.review.mockResolvedValue(detail())
  service.reviewHistory.mockResolvedValue({ data: [], meta: { pagination: { current_page: 1, last_page: 1, total: 0 } } })
})
afterEach(() => { scope.stop() })

describe('AI content review', () => {
  it('shares the page notice so a later action message can replace a review success', async () => {
    const pageNotice = shallowRef(null)
    const s = scope.run(() => useAiContentReview(vi.fn(), pageNotice))

    await s.openReview(item)
    s.requestDecision('approve')
    service.approveCandidate.mockResolvedValue({ post_id: 6, review: { status: 'approved', post_id: 6 } })
    await s.confirmDecision({ fields: ['title'] })
    expect(pageNotice.value.message).toContain('#6')
    pageNotice.value = { type: 'error', message: 'Lỗi thao tác tiếp theo' }
    expect(s.notice.value).toEqual(pageNotice.value)
  })

  it('locks decisions while loading and ignores a closed detail after another run opens', async () => {
    const first = deferred()
    const s = setup()

    service.review.mockReturnValueOnce(first.promise)

    const pending = s.openReview(item)

    s.requestDecision('approve')
    await s.confirmDecision({ fields: ['title'] })
    expect(service.approveCandidate).not.toHaveBeenCalled()
    expect(s.state.value.loading).toBe(true)
    s.closeReview()
    service.review.mockResolvedValueOnce(detail({ job_id: 'run-b', draft: { title: 'Bài B' } }))
    await s.openReview({ ...item, id: 'run-b' })
    first.resolve(detail({ draft: { title: 'Phản hồi cũ' } }))
    await pending
    s.finishClose()
    expect(s.state.value.detail.job_id).toBe('run-b')
    expect(s.state.value.detail.draft.title).toBe('Bài B')
  })

  it('submits once, keeps the review open until completion and updates the list to approved', async () => {
    const s = setup()
    const result = deferred()
    const source = s.source.value

    await s.openReview(item)
    s.requestDecision('approve')
    service.approveCandidate.mockReturnValueOnce(result.promise)

    const pending = s.confirmDecision({ fields: ['title', 'content'], reason: 'Đã kiểm tra' })

    s.closeReview()
    s.closeDecision()
    await s.confirmDecision({ fields: ['title'] })
    expect(s.state.value.open).toBe(true)
    expect(s.state.value.decision).toBe('approve')
    expect(service.approveCandidate).toHaveBeenCalledExactlyOnceWith(item.id, {
      fields: ['title', 'content'], reason: 'Đã kiểm tra', expected_version: 'draft-v1', expected_review_version: 'review-v1',
    })
    result.resolve({ post_id: 7, review: { status: 'approved', post_id: 7 } })
    await pending
    expect(s.items.value[0].status).toBe('applied')
    expect(s.state.value.decision).toBeNull()
    expect(s.state.value.detail.can_review).toBe(false)
    expect(s.source.value).toBe(source)
    expect(s.notice.value.message).toContain('Post nháp #7')
  })

  it('persists rejected metadata in the list and keeps history failure separate from the decision', async () => {
    const s = setup()

    service.reviewHistory.mockRejectedValueOnce({ data: { message: 'Lỗi lịch sử' } })
    await s.openReview(item)
    await flushPromises()
    expect(s.state.value.historyError).toBe('Lỗi lịch sử')
    expect(s.state.value.decisionBlocked).toBe(false)
    s.requestDecision('reject')
    service.rejectCandidate.mockResolvedValue({ review: { status: 'rejected', reason: 'Sai số liệu' } })
    await s.confirmDecision({ reason: 'Sai số liệu' })
    expect(s.items.value[0]).toMatchObject({ status: 'rejected', review: { reason: 'Sai số liệu' } })
    expect(service.approveCandidate).not.toHaveBeenCalled()
    expect(s.state.value.detail.can_edit).toBe(false)
    s.requestDecision('approve')
    expect(s.state.value.decision).toBeNull()
  })

  it('requires a fresh GET after conflict and uses both new versions only on explicit resubmission', async () => {
    const s = setup()

    await s.openReview(item)
    s.requestDecision('reject')
    service.rejectCandidate.mockRejectedValueOnce({ status: 409, data: { message: 'Nội dung đã thay đổi' } })
    await s.confirmDecision({ reason: 'Bản đang nhập' })
    expect(s.state.value.decision).toBe('reject')
    expect(s.state.value.decisionBlocked).toBe(true)
    await s.confirmDecision({ reason: 'Bấm lại' })
    expect(service.rejectCandidate).toHaveBeenCalledOnce()
    service.review.mockResolvedValueOnce(detail({ draft_version: 'draft-v2', review_version: 'review-v2' }))
    await s.loadReview()
    expect(service.rejectCandidate).toHaveBeenCalledOnce()
    expect(s.state.value.decisionBlocked).toBe(false)
    service.rejectCandidate.mockResolvedValue({ review: { status: 'rejected', reason: 'Bản đang nhập' } })
    await s.confirmDecision({ reason: 'Bản đang nhập' })
    expect(service.rejectCandidate).toHaveBeenLastCalledWith(item.id, { reason: 'Bản đang nhập', expected_version: 'draft-v2', expected_review_version: 'review-v2' })
  })

  it('reconciles an uncertain POST through GET without sending another approval', async () => {
    const s = setup()

    await s.openReview(item)
    s.requestDecision('approve')
    service.approveCandidate.mockRejectedValueOnce(new Error('Mất kết nối'))
    await s.confirmDecision({ fields: ['title'] })
    expect(s.state.value.decisionBlocked).toBe(true)
    service.review.mockResolvedValueOnce(detail({ review: { status: 'approved', post_id: 8 }, applied_target_id: 8, can_review: false, can_edit: false }))
    await s.loadReview()
    expect(s.items.value[0].status).toBe('applied')
    expect(s.state.value.decision).toBeNull()
    expect(service.approveCandidate).toHaveBeenCalledOnce()
  })

  it('blocks a decision after detail GET fails and retries only that read', async () => {
    const s = setup()

    service.review.mockRejectedValueOnce({ status: 503, data: { message: 'Không đọc được bài' } })
    await s.openReview(item)
    expect(s.state.value.error).toContain('Không đọc được bài')
    s.requestDecision('approve')
    expect(s.state.value.decision).toBeNull()
    await s.loadReview()
    expect(s.state.value.detail.job_id).toBe(item.id)
    expect(service.approveCandidate).not.toHaveBeenCalled()
  })

  it('replaces an in-flight history after approval and ignores its older response', async () => {
    const old = deferred()
    const s = setup()

    service.reviewHistory.mockReturnValueOnce(old.promise)
    await s.openReview(item)
    s.requestDecision('approve')
    service.approveCandidate.mockResolvedValue({ post_id: 9, review: { status: 'approved', post_id: 9 } })
    service.reviewHistory.mockResolvedValueOnce({ data: [{ id: 2, event: 'candidate.approved' }], meta: { pagination: { current_page: 1, last_page: 1, total: 1 } } })
    await s.confirmDecision({ fields: ['title'] })
    await flushPromises()
    old.resolve({ data: [{ id: 1, event: 'candidate.edited' }], meta: { pagination: { current_page: 1, last_page: 1, total: 1 } } })
    await flushPromises()
    expect(s.state.value.history).toEqual([{ id: 2, event: 'candidate.approved' }])
  })

  it('ignores POST completion after scope disposal', async () => {
    const s = setup()
    const result = deferred()

    await s.openReview(item)
    s.requestDecision('approve')
    service.approveCandidate.mockReturnValueOnce(result.promise)

    const pending = s.confirmDecision({ fields: ['title'] })

    scope.stop()
    result.resolve({ post_id: 10, review: { status: 'approved' } })
    await pending
    expect(s.items.value[0].status).toBe('review')
    expect(s.notice.value).toBeNull()
  })
})
