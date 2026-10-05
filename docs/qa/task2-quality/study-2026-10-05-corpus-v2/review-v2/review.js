/**
 * =====================================================================
 * CHỨC NĂNG FILE: Form chấm người offline; lưu nháp riêng và xuất CSV an toàn.
 * CÁC HÀM: readReviewRows(), encodeReviewCsv(), validateCompletedReviews(),
 * initializeReview(); handler chọn nguồn/lưu/xuất.
 * INPUT/OUTPUT: field người đọc -> localStorage/CSV; không tự điền điểm.
 * SIDE EFFECT: lưu trình duyệt và tải file chủ động, không network hoặc Apply.
 * =====================================================================
 */
const scoreFields = ['naturalness_1_5', 'usefulness_1_5', 'structure_1_5', 'repetition_1_5', 'brief_style_1_5']
const countFields = ['critical_errors', 'major_errors', 'minor_errors', 'important_facts_preserved', 'important_facts_expected', 'fact_edits', 'expression_edits', 'paragraphs_added', 'paragraphs_removed']

function readReviewRows(root) {
  const reviewer = root.querySelector('#reviewer-name').value.trim()
  const bundleHash = JSON.parse(root.querySelector('#review-config').textContent).bundleHash

  return [...root.querySelectorAll('form[data-row]')].map(form => {
    const row = { 'case_id': form.dataset.case, label: form.dataset.label, 'bundle_sha256': bundleHash, reviewer }

    for (const input of form.querySelectorAll('[data-field]')) {
      row[input.dataset.field] = input.type === 'checkbox'
        ? (input.dataset.field === 'review_state' ? (input.checked ? 'completed' : 'pending') : (input.checked ? 'yes' : ''))
        : input.value
    }
    row['paired_preference'] = root.querySelector(`[data-preference="${form.dataset.case}"]`).value

    return row
  })
}

function encodeReviewCsv(fields, rows) {
  const cell = value => {
    const text = String(value ?? '')
    const safe = /^\s*[=+\-@]/u.test(text) ? `'${text}` : text

    return `"${safe.replaceAll('"', '""')}"`
  }

  return `\uFEFF${[fields, ...rows.map(row => fields.map(field => row[field]))].map(row => row.map(cell).join(',')).join('\r\n')}\r\n`
}

function validateCompletedReviews(rows) {
  for (const row of rows) {
    if (row.review_state !== 'completed') continue
    const name = `${row.case_id}-${row.label}`
    const required = [...scoreFields, ...countFields, 'editing_minutes', 'reviewer', 'accuracy_gate', 'paired_preference']

    if (required.some(field => !String(row[field] ?? '').trim()) || row.source_facts_confirmed !== 'yes')
      return `${name}: còn thiếu thông tin. Điền đủ hoặc bỏ chọn Hoàn thành để lưu nháp.`
    if (scoreFields.some(field => !/^[1-5]$/u.test(row[field])))
      return `${name}: điểm phải từ 1 đến 5.`
    if (countFields.some(field => !/^\d+$/u.test(row[field]) || Number(row[field]) > 1000000) || !Number.isFinite(Number(row.editing_minutes)) || Number(row.editing_minutes) < 0)
      return `${name}: số lỗi/công sửa không hợp lệ.`
    if (Number(row.important_facts_preserved) > Number(row.important_facts_expected))
      return `${name}: số dữ kiện giữ đúng không thể lớn hơn số dữ kiện cần giữ.`
    if (Number(row.important_facts_expected) > 0 && !row.important_fact_evidence.trim())
      return `${name}: cần ghi dữ kiện và mã đoạn dẫn chứng nguồn.`
    const errors = Number(row.critical_errors) + Number(row.major_errors) + Number(row.minor_errors) > 0
    const missingFacts = Number(row.important_facts_preserved) < Number(row.important_facts_expected)

    if ((errors || missingFacts) && !row.facts_errors_and_severity.trim())
      return `${name}: cần ghi lỗi, câu output và dẫn chứng nguồn.`
    if (row.accuracy_gate === 'pass' && (Number(row.critical_errors) > 0 || Number(row.major_errors) > 0 || missingFacts))
      return `${name}: chưa thể đạt gate chính xác khi còn lỗi lớn/nghiêm trọng hoặc thiếu dữ kiện.`
  }

  return ''
}

function initializeReview(root) {
  const configElement = root.querySelector('#review-config')
  if (!configElement) return
  const config = JSON.parse(configElement.textContent)
  const draftKey = `article-quality-v2:${config.bundleHash}:${config.reader}`
  const status = root.querySelector('#save-state')
  const error = root.querySelector('#review-error')
  const forms = [...root.querySelectorAll('form[data-row]')]
  const footer = root.querySelector('footer')

  // Footer tăng chiều cao khi xuống mobile hoặc hiện CSV; không che field cuối.
  if (footer && typeof ResizeObserver !== 'undefined') {
    const footerObserver = new ResizeObserver(() => {
      root.body.style.paddingBottom = `${footer.getBoundingClientRect().height + 24}px`
    })

    footerObserver.observe(footer)
  }

  const updateProgress = () => {
    const completed = readReviewRows(root).filter(row => row.review_state === 'completed').length
    const available = forms.filter(form => form.dataset.available === 'yes').length

    root.querySelector('#progress').textContent = `${completed}/${available} bài đánh dấu hoàn thành`
  }

  try {
    const saved = JSON.parse(localStorage.getItem(draftKey) || 'null')

    if (saved) {
      root.querySelector('#reviewer-name').value = saved[0]?.reviewer ?? ''
      for (const row of saved) {
        const form = forms.find(item => item.dataset.case === row.case_id && item.dataset.label === row.label)
        if (!form) continue
        for (const input of form.querySelectorAll('[data-field]')) {
          const value = row[input.dataset.field] ?? ''

          if (input.type === 'checkbox') input.checked = value === (input.dataset.field === 'review_state' ? 'completed' : 'yes')
          else input.value = value
        }
        root.querySelector(`[data-preference="${row.case_id}"]`).value = row.paired_preference ?? ''
      }
      status.textContent = 'Đã khôi phục bản nháp riêng của bộ chấm này.'
    }
  } catch {
    status.textContent = 'Trình duyệt không hỗ trợ lưu nháp ở đây. Tải CSV trước khi đóng trang.'
  }
  root.querySelector('#case-picker').addEventListener('change', event => {
    for (const section of root.querySelectorAll('section[data-case]'))
      section.hidden = section.dataset.case !== event.target.value
  })
  root.addEventListener('input', () => {
    try {
      localStorage.setItem(draftKey, JSON.stringify(readReviewRows(root)))
      status.textContent = 'Đã lưu nháp trên trình duyệt. Tải CSV để gửi kết quả.'
    } catch {
      status.textContent = 'Chưa lưu được trên trình duyệt. Tải CSV trước khi đóng trang.'
    }
    error.textContent = ''
    updateProgress()
  })
  for (const form of forms) form.addEventListener('submit', event => event.preventDefault())
  root.querySelector('#preview-csv')?.addEventListener('click', () => {
    const rows = readReviewRows(root)
    const problem = validateCompletedReviews(rows)

    if (problem) {
      error.textContent = problem

      return
    }
    const preview = root.querySelector('#csv-preview')
    const previewLabel = root.querySelector('#csv-preview-label')

    preview.value = encodeReviewCsv(config.fields, rows)
    previewLabel.hidden = !previewLabel.hidden
    root.body.classList.toggle('csv-open', !previewLabel.hidden)
    if (!previewLabel.hidden) preview.select()
  })
  root.querySelector('#download-csv').addEventListener('click', () => {
    const rows = readReviewRows(root)
    const problem = validateCompletedReviews(rows)

    if (problem) {
      error.textContent = problem

      return
    }
    const blob = new Blob([encodeReviewCsv(config.fields, rows)], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const link = root.createElement('a')

    link.href = url
    link.download = `${config.reader}-completed.csv`
    root.body.append(link)
    link.click()
    link.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
    status.textContent = 'Đã tạo CSV. Nếu chưa thấy file tải về, dùng Xem CSV để sao chép và lưu UTF-8.'
  })
  updateProgress()
}

// Classic script dùng được khi mở file offline; Vitest đọc cùng module để kiểm CSV.
globalThis.articleQualityReview = { readReviewRows, encodeReviewCsv, validateCompletedReviews, initializeReview }
if (typeof document !== 'undefined') initializeReview(document)
