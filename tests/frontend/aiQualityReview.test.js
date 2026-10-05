/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm form chấm offline không tự điền điểm hoặc trộn người.
 * CÁC HÀM: reviewPage(), completedRow(); tests CSV/draft/gate/preference.
 * INPUT/OUTPUT: DOM/điểm fixture -> assertions, không ghi bảng chấm nghiệm thu.
 * =====================================================================
 */
import { beforeEach, describe, expect, it } from 'vitest'
import '../../scripts/ai-quality/review'

const review = globalThis.articleQualityReview
const scoreFields = ['naturalness_1_5', 'usefulness_1_5', 'structure_1_5', 'repetition_1_5', 'brief_style_1_5']
const countFields = ['critical_errors', 'major_errors', 'minor_errors', 'important_facts_preserved', 'important_facts_expected', 'fact_edits', 'expression_edits', 'paragraphs_added', 'paragraphs_removed']

function reviewPage(reader = 'reviewer-1') {
  const inputs = [...scoreFields, ...countFields, 'editing_minutes', 'accuracy_gate', 'important_fact_evidence', 'facts_errors_and_severity'].map(field => `<input data-field="${field}">`).join('')

  document.body.innerHTML = `<input id="reviewer-name"><select id="case-picker"><option value="Q01">Q01</option><option value="Q02">Q02</option></select><p id="progress"></p><p id="save-state"></p><p id="review-error"></p><button id="download-csv"></button><button id="preview-csv"></button><label id="csv-preview-label" hidden><textarea id="csv-preview" readonly></textarea></label><section data-case="Q01"><select data-preference="Q01"><option value="">Chưa chọn</option><option value="X">X</option></select><form data-row="Q01-X" data-case="Q01" data-label="X" data-available="yes">${inputs}<input type="checkbox" data-field="source_facts_confirmed"><input type="checkbox" data-field="review_state"></form></section><section data-case="Q02" hidden></section><script id="review-config" type="application/json">${JSON.stringify({ reader, bundleHash: 'qa-test-bundle', fields: ['case_id', 'label', 'bundle_sha256', 'reviewer'] })}</script>`
  review.initializeReview(document)
}

function completedRow() {
  return {
    ...Object.fromEntries(scoreFields.map(field => [field, '4'])),
    ...Object.fromEntries(countFields.map(field => [field, '0'])),
    'case_id': 'Q01', label: 'X', reviewer: 'Fixture person', 'review_state': 'completed',
    'source_facts_confirmed': 'yes', 'accuracy_gate': 'pass', 'editing_minutes': '0',
    'paired_preference': 'X', 'important_fact_evidence': '', 'facts_errors_and_severity': '',
  }
}

beforeEach(() => {
  document.body.innerHTML = ''
  localStorage.clear()
})

describe('quality review CSV and independent drafts', () => {
  it('supports C-only forms without inventing a paired preference', () => {
    reviewPage()

    const preference = document.querySelector('[data-preference]')

    preference.parentNode.removeChild(preference)
    expect(review.readReviewRows(document)[0].paired_preference).toBe('')

    const row = completedRow()

    row['paired_preference'] = ''
    expect(review.validateCompletedReviews([row])).toBe('')
  })

  it('exports quoted multiline CSV with BOM and blocks spreadsheet formulas', () => {
    const csv = review.encodeReviewCsv(['notes', 'minutes'], [{ notes: '"quoted"\nsecond line', minutes: '0' }, { notes: '  =HYPERLINK("https://example.test")', minutes: '' }])

    expect(csv.startsWith('\uFEFF')).toBe(true)
    expect(csv).toContain('""quoted""\nsecond line')
    expect(csv).toContain("'  =HYPERLINK")
    expect(csv).toContain('"0"')
    expect(csv).toContain('""\r\n')
  })

  it('keeps all initial score/count fields empty and binds rows to this study', () => {
    reviewPage()

    const row = review.readReviewRows(document)[0]

    expect(row.bundle_sha256).toBe('qa-test-bundle')
    expect(row.editing_minutes).toBe('')
    expect(row.naturalness_1_5).toBe('')
    expect(row.critical_errors).toBe('')
    expect(row.review_state).toBe('pending')
    expect(document.querySelector('#progress').textContent).toBe('0/1 bài đánh dấu hoàn thành')
  })

  it('provides a copyable CSV when the browser cannot save the download', () => {
    reviewPage()
    document.querySelector('#preview-csv').click()
    expect(document.querySelector('#csv-preview-label').hidden).toBe(false)
    expect(document.querySelector('#csv-preview').value).toContain('"Q01","X","qa-test-bundle",""')
    expect(document.body.classList.contains('csv-open')).toBe(true)
    document.querySelector('#preview-csv').click()
    expect(document.querySelector('#csv-preview-label').hidden).toBe(true)
  })

  it('keeps draft identities and ratings separate between reviewer slots', () => {
    reviewPage()
    document.querySelector('#reviewer-name').value = 'Fixture reader 1'
    document.querySelector('[data-field="naturalness_1_5"]').value = '4'
    document.querySelector('#reviewer-name').dispatchEvent(new Event('input', { bubbles: true }))
    reviewPage('reviewer-2')
    expect(document.querySelector('#reviewer-name').value).toBe('')
    expect(document.querySelector('[data-field="naturalness_1_5"]').value).toBe('')
    reviewPage('reviewer-1')
    expect(document.querySelector('#reviewer-name').value).toBe('Fixture reader 1')
    expect(document.querySelector('[data-field="naturalness_1_5"]').value).toBe('4')
  })

  it('switches sources without removing or resetting completed draft fields', () => {
    reviewPage()

    const input = document.querySelector('[data-field="naturalness_1_5"]')

    input.value = '3'
    document.querySelector('#case-picker').value = 'Q02'
    document.querySelector('#case-picker').dispatchEvent(new Event('change', { bubbles: true }))
    expect(document.querySelector('section[data-case="Q01"]').hidden).toBe(true)
    expect(input.value).toBe('3')
  })

  it('distinguishes explicit zero editing time from missing time', () => {
    const row = completedRow()

    expect(review.validateCompletedReviews([row])).toBe('')
    row['editing_minutes'] = ''
    expect(review.validateCompletedReviews([row])).toContain('còn thiếu')
  })

  it('requires error evidence and prevents a pass with major errors', () => {
    const row = completedRow()

    row['major_errors'] = '1'
    expect(review.validateCompletedReviews([row])).toContain('dẫn chứng')
    row['facts_errors_and_severity'] = 'S001 → output đổi điều kiện; major.'
    expect(review.validateCompletedReviews([row])).toContain('chưa thể đạt')
    row['accuracy_gate'] = 'fail'
    expect(review.validateCompletedReviews([row])).toBe('')
  })

  it('requires source-based important facts even when style scores are high', () => {
    const row = completedRow()

    row['important_facts_expected'] = row['important_facts_preserved'] = '2'
    expect(review.validateCompletedReviews([row])).toContain('mã đoạn')
    row['important_fact_evidence'] = 'S001 → giữ hai điều kiện nguồn.'
    expect(review.validateCompletedReviews([row])).toBe('')
  })

  it('allows pending drafts without fabricating missing scores', () => {
    const row = completedRow()

    row['review_state'] = 'pending'
    row['naturalness_1_5'] = ''
    expect(review.validateCompletedReviews([row])).toBe('')
    expect(row.naturalness_1_5).toBe('')
  })
})
