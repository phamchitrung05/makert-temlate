/* eslint-disable camelcase -- Error fixtures follow the Laravel API contract. */
import { describe, expect, it } from 'vitest'
import { formatAiError } from '@/utils/aiErrors'
import { useAiRunFeedback } from '@/composables/useAiRunFeedback'

const outputs = [{ value: 'content', title: 'Nội dung' }]

const failure = {
  job_id: 'failed-run', status: 'failed', error_code: 'AI_PROVIDER_EMPTY_CONTENT', error: 'Thông báo backend',
  validation_errors: [{ group: 'content', field: 'content_html', reason: 'empty' }],
}

describe('AI run error feedback', () => {
  it('prefers config-labelled validation errors, then HTTP field errors, then safe messages', () => {
    expect(formatAiError(failure, undefined, outputs)).toBe('Nội dung không có nội dung hợp lệ.')
    expect(formatAiError({ data: { errors: { model: ['Model đã tắt'] }, message: 'Validation failed' } })).toBe('Model đã tắt')
    expect(formatAiError({ error: 'Provider từ chối' })).toBe('Provider từ chối')
    expect(formatAiError({ validation_errors: [{ group: 'content', reason: 'new_reason' }] }, undefined, outputs)).toBe('Nội dung không hợp lệ.')
    expect(formatAiError({ error: { raw: 'do not render' } }, 'Lỗi an toàn')).toBe('Lỗi an toàn')
  })

  it('notifies once per failed transition and can notify again after a manual retry', () => {
    const state = useAiRunFeedback()

    state.observeRun(failure, undefined, outputs)
    expect(state.snackbar.value).toMatchObject({ visible: true, type: 'error', message: 'Nội dung không có nội dung hợp lệ.' })
    state.setSnackbarVisible(false)
    state.observeRun(failure, undefined, outputs)
    expect(state.snackbar.value.visible).toBe(false)
    state.observeRun({ job_id: failure.job_id, status: 'queued' })
    state.observeRun(failure, undefined, outputs)
    expect(state.snackbar.value.visible).toBe(true)
  })
})
