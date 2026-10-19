/* eslint-disable camelcase -- Error fixtures follow the Laravel API contract. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm tra thông báo lỗi AI theo lý do backend trả về.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - describe()/it(): kiểm ưu tiên lỗi, lỗi thiếu số liệu và thông báo theo lượt.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : run DTO/lỗi HTTP giả, nhãn output và trạng thái snackbar.
 * - OUTPUT: assertions về message cụ thể và số lần thông báo.
 * =====================================================================
 */
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

  it('explains the missing source numbers that blocked the current article', () => {
    const run = {
      job_id: 'missing-source-number-run', status: 'failed', error_code: 'AI_QUALITY_GROUNDING',
      error: 'Nội dung AI thiếu số liệu hoặc phiên bản trong bằng chứng quan trọng.',
      validation_errors: [{ group: 'content', field: 'content_html', reason: 'important_number_missing' }],
    }

    const state = useAiRunFeedback()

    state.observeRun(run, undefined, outputs)

    expect(state.snackbar.value.message).toBe('Nội dung thiếu số liệu hoặc phiên bản quan trọng từ nguồn.')
    expect(formatAiError({ data: run }, undefined, outputs)).toBe(state.snackbar.value.message)
  })

  it.each([
    ['AI_PROVIDER_HTTP_429', 'Dịch vụ AI đã hết quota hoặc đạt giới hạn yêu cầu. Hãy kiểm tra hạn mức và thử lại sau.'],
    ['AI_PROVIDER_INCOMPLETE', 'AI trả nội dung chưa đầy đủ. Hãy rút ngắn yêu cầu hoặc chọn model có giới hạn lớn hơn.'],
    ['AI_INPUT_BUDGET', 'Nguồn và yêu cầu vượt giới hạn của model. Hãy rút ngắn nguồn hoặc chọn model có giới hạn lớn hơn.'],
  ])('shows the public backend explanation for %s without exposing diagnostics', (code, message) => {
    const run = {
      job_id: code, status: 'failed', error_code: code, error: message,
      response_diagnostics: { raw: 'private-provider-response' },
    }

    const state = useAiRunFeedback()

    state.observeRun(run, undefined, outputs)
    expect(state.snackbar.value.message).toBe(message)
    expect(formatAiError({ data: run })).toBe(message)
    expect(formatAiError({ error_code: code })).toBe(message)
    expect(state.snackbar.value.message).not.toContain('private-provider-response')
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
