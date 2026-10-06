/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm nội dung preview không chạy HTML nguy hiểm và diff thật.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: test cases cho textOfHtml/comparisonBlocks/previewOfHtml.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): HTML nguồn/model -> text/HTML allowlist.
 * SIDE EFFECT: chỉ parse DOM trong môi trường test, không network hoặc database.
 * =====================================================================
 */
import { describe, expect, it } from 'vitest'
import { comparisonBlocks, previewOfHtml, textOfHtml } from '@/utils/aiContentComparison'

describe('AI content comparison data', () => {
  it('highlights different paragraphs while retaining code and shared text', () => {
    const source = textOfHtml('<p>Đoạn chung.</p><pre>&lt;script&gt;code mẫu&lt;/script&gt;</pre><p>Đoạn nguồn.</p>')
    const result = textOfHtml('<p>Đoạn chung.</p><p>Đoạn AI.</p>')

    expect(comparisonBlocks(source, result)).toEqual([
      { id: 0, text: 'Đoạn chung.', changed: false },
      { id: 1, text: '<script>code mẫu</script>', changed: true },
      { id: 2, text: 'Đoạn nguồn.', changed: true },
    ])
  })

  it('preserves article tables while stripping active tags, event/style attributes and unsafe URLs', () => {
    const html = previewOfHtml('<script>bad()</script><svg onload="bad()"></svg><iframe src="https://bad.test"></iframe><p style="color:red" onclick="bad()">Bài viết</p><table><tr><td colspan="2">Bảng thật</td></tr></table><img src="javascript:bad()" onerror="bad()" alt="Ảnh lỗi"><a href="jav&#x61;script:bad()">Liên kết lỗi</a><img src="https://media.test/photo.jpg" onerror="bad()" alt="Ảnh thật">')
    const template = document.createElement('template')

    template.innerHTML = html
    expect(template.content.querySelector('script,svg,iframe,[style],[onclick],[onerror],a')).toBeNull()
    expect(template.content.querySelector('td').getAttribute('colspan')).toBe('2')
    expect(template.content.querySelectorAll('img')).toHaveLength(1)
    expect(template.content.querySelector('img').getAttribute('src')).toBe('https://media.test/photo.jpg')
    expect(template.content.textContent).toContain('Liên kết lỗi')
    expect(template.content.textContent).not.toContain('bad()')
  })
})
