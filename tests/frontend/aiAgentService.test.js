/* eslint-disable camelcase */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiMocks = vi.hoisted(() => ({
  $api: vi.fn(),
}))

vi.mock('@/utils/api', () => apiMocks)

import { aiAgentService } from '@/services/aiAgent'

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử API boundary của AI Agent dùng chung.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: beforeEach(), các test capability/session/envelope.
 * INPUT: capability/session và lỗi API generic.
 * OUTPUT: payload unwrap đúng; lỗi được giữ nguyên, không tự đổi endpoint.
 * SIDE EFFECT: chỉ mock $api; không gọi mạng và không ghi database.
 * =====================================================================
 */
describe('aiAgentService', () => {
  beforeEach(() => {
    apiMocks.$api.mockReset()
  })

  it('honors backend outputs and removes only taxonomy generation choices', async () => {
    const capability = {
      target_type: 'post', outputs: ['title', 'summary', 'taxonomy', 'suggested_tag_ids'],
      output_options: [{ value: 'title', title: 'Tiêu đề' }, { value: 'summary', title: 'Tóm tắt' }, { value: 'taxonomy', title: 'Taxonomy' }],
    }

    apiMocks.$api.mockResolvedValueOnce({ success: true, data: capability })
    await expect(aiAgentService.capabilities('post')).resolves.toEqual({
      ...capability,
      outputs: ['title', 'summary'],
      output_options: [{ value: 'title', title: 'Tiêu đề' }, { value: 'summary', title: 'Tóm tắt' }],
    })
    apiMocks.$api.mockResolvedValueOnce({ success: true, data: [capability] })
    expect((await aiAgentService.targets())[0].outputs).toEqual(['title', 'summary'])
    expect(capability.outputs).toContain('taxonomy')
  })

  it('uses the backend capability registry and unwraps its envelope', async () => {
    apiMocks.$api.mockResolvedValue({
      success: true,
      data: { target_type: 'post', providers: [{ key: 'deterministic' }] },
    })

    await expect(aiAgentService.capabilities('post')).resolves.toEqual({
      target_type: 'post',
      providers: [{ key: 'deterministic' }],
    })
    expect(apiMocks.$api).toHaveBeenCalledWith('/admin/ai-agent/capabilities/post')
  })

  it('creates a session on the single generic endpoint and keeps the original error', async () => {
    apiMocks.$api.mockResolvedValueOnce({ success: true, data: { job_id: 'job-1', status: 'queued' } })

    await expect(aiAgentService.createSession({
      target_type: 'post',
      output_language: 'vi',
      instructions: 'Giữ code',
      requested_outputs: ['content'],
      input: { type: 'url', url: 'https://example.test/article' },
    })).resolves.toEqual({ job_id: 'job-1', status: 'queued' })
    expect(apiMocks.$api).toHaveBeenCalledTimes(1)
    expect(apiMocks.$api).toHaveBeenCalledWith('/admin/ai-agent/sessions', {
      method: 'POST',
      body: {
        target_type: 'post',
        output_language: 'vi',
        instructions: 'Giữ code',
        requested_outputs: ['content'],
        input: { type: 'url', url: 'https://example.test/article' },
      },
    })

    const failure = Object.assign(new Error('invalid provider'), { status: 422 })

    apiMocks.$api.mockReset()
    apiMocks.$api.mockRejectedValueOnce(failure)
    await expect(aiAgentService.createSession({ target_type: 'social_post' })).rejects.toBe(failure)
    expect(apiMocks.$api).toHaveBeenCalledTimes(1)
  })

  it('reads review/history with abort and sends decisions once', async () => {
    const controller = new AbortController()
    const record = { job_id: 'run-1', review: { status: 'pending_review' } }
    const pagination = { current_page: 1, last_page: 2, total: 3 }

    apiMocks.$api.mockResolvedValueOnce({ success: true, data: record })
    await expect(aiAgentService.review('run-1', { signal: controller.signal })).resolves.toEqual(record)
    expect(apiMocks.$api).toHaveBeenLastCalledWith('/admin/ai-agent/candidates/run-1/review', { signal: controller.signal, retry: 0 })
    apiMocks.$api.mockResolvedValueOnce({ success: true, data: [], meta: { pagination } })
    await expect(aiAgentService.reviewHistory('run-1', { page: 2 })).resolves.toMatchObject({ meta: { pagination } })
    expect(apiMocks.$api).toHaveBeenLastCalledWith('/admin/ai-agent/candidates/run-1/review/history', { query: { page: 2 }, retry: 0 })

    const payload = { fields: ['title'], expected_version: 'draft', expected_review_version: 'review' }

    apiMocks.$api.mockResolvedValueOnce({ success: true, data: { post_id: 5 } })
    await expect(aiAgentService.applyCandidate('run-1', payload)).resolves.toEqual({ post_id: 5 })
    expect(apiMocks.$api).toHaveBeenLastCalledWith('/admin/ai-agent/candidates/run-1/approve', { method: 'POST', body: payload, retry: 0 })
    apiMocks.$api.mockRejectedValueOnce({ status: 404 })
    await expect(aiAgentService.rejectCandidate('run-1', { reason: 'Sai nguồn' })).rejects.toEqual({ status: 404 })
    expect(apiMocks.$api).toHaveBeenCalledTimes(4)
    expect(apiMocks.$api).toHaveBeenLastCalledWith('/admin/ai-agent/candidates/run-1/reject', { method: 'POST', body: { reason: 'Sai nguồn' }, retry: 0 })
  })
})
