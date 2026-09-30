/* eslint-disable camelcase */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiMocks = vi.hoisted(() => ({
  $api: vi.fn(),
}))

vi.mock('@/utils/api', () => apiMocks)

import { aiAgentService, fallbackCapabilities } from '@/services/aiAgent'

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử API boundary của AI Agent dùng chung.
 * =====================================================================
 * INPUT: capability/session và lỗi API generic.
 * OUTPUT: payload unwrap đúng hoặc fallback Post giữ nguyên lỗi gốc.
 * SIDE EFFECT: chỉ mock $api; không gọi mạng và không ghi database.
 * =====================================================================
 */
describe('aiAgentService', () => {
  beforeEach(() => {
    apiMocks.$api.mockReset()
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

  it('falls back to the legacy Post import endpoint when the generic session API is unavailable', async () => {
    const genericError = new Error('not implemented')

    apiMocks.$api
      .mockRejectedValueOnce(genericError)
      .mockResolvedValueOnce({ success: true, data: { job_id: 'job-1', status: 'queued' } })

    await expect(aiAgentService.createSession({
      target_type: 'post',
      output_language: 'vi',
      instructions: 'Giữ code',
      requested_outputs: ['content'],
      input: { type: 'url', url: 'https://example.test/article' },
    })).resolves.toEqual({ job_id: 'job-1', status: 'queued' })

    expect(apiMocks.$api).toHaveBeenNthCalledWith(2, '/admin/posts/ai/import', {
      method: 'POST',
      body: {
        url: 'https://example.test/article',
        language: 'vi',
        generate_thumbnail: false,
        instructions: 'Giữ code',
      },
    })
  })

  it('preserves the original error for unsupported non-Post targets', async () => {
    const genericError = new Error('not implemented')

    apiMocks.$api.mockRejectedValue(genericError)

    await expect(aiAgentService.createSession({
      target_type: 'resource',
      input: { type: 'text', text: 'Documentation' },
    })).rejects.toBe(genericError)
  })

  it('provides a safe UI fallback while the target capability endpoint is unavailable', () => {
    const capability = fallbackCapabilities('sound')

    expect(capability.target_type).toBe('sound')
    expect(capability.providers.map(provider => provider.key)).toEqual([
      'deterministic',
      'openai',
      'gemini',
    ])
  })
})
