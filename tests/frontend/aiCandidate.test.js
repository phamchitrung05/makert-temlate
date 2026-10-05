/* eslint-disable camelcase */
import { describe, expect, it } from 'vitest'
import { mergePostCandidate, overwrittenFields, toPostPayload } from '@/composables/aiCandidate'

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử merge candidate AI chọn lọc vào Post form.
 * =====================================================================
 *
 * CÁC HÀM/METHOD TRONG FILE: các test mapping canonical field, giữ field
 * không chọn và phát hiện dữ liệu cần xác nhận ghi đè.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : canonical AI output, selected fields và Post form hiện tại.
 * - OUTPUT: payload partial/merged và danh sách field ghi đè; không gọi API.
 * =====================================================================
 */
describe('AI Post candidate mapping', () => {
  it('maps only selected canonical fields and groups SEO/taxonomy', () => {
    const output = {
      title: { value: 'Tiêu đề AI' },
      content_html: '<h2>Nội dung AI</h2>',
      seo_title: 'SEO AI',
      category_ids: [2],
      tag_ids: [3],
      excerpt: 'Không chọn field này',
    }

    expect(toPostPayload(output, ['title', 'content', 'seo_title', 'taxonomy'])).toEqual({
      title: 'Tiêu đề AI',
      content: '<h2>Nội dung AI</h2>',
      seo: { title: 'SEO AI' },
      categories: [2],
      tags: [3],
    })
  })

  it('ignores legacy AI taxonomy suggestions even when their fields or group are selected', () => {
    const output = { title: 'AI', suggested_category_ids: [2], suggested_tag_ids: [3] }
    const payload = toPostPayload(output, ['title', 'taxonomy', 'suggested_category_ids', 'suggested_tag_ids'])

    expect(payload).toEqual({ title: 'AI' })
    expect(mergePostCandidate({ categories: [1], tags: [4] }, payload)).toMatchObject({ categories: [1], tags: [4] })
  })

  it('preserves the unprovided manual taxonomy and accepts explicit manual clearing', () => {
    const payload = toPostPayload({ category_ids: [] }, ['taxonomy'])

    expect(payload).toEqual({ categories: [] })
    expect(mergePostCandidate({ categories: [1], tags: [4] }, payload)).toMatchObject({ categories: [], tags: [4] })
  })

  it('keeps unselected form fields while merging selected SEO values', () => {
    const form = {
      title: 'Tiêu đề cũ',
      content: '<p>Nội dung người dùng</p>',
      excerpt: 'Mô tả giữ nguyên',
      seo: { title: 'SEO cũ', description: 'Mô tả SEO giữ nguyên' },
      categories: [1],
      tags: [4],
    }

    const result = mergePostCandidate(form, { title: 'Tiêu đề AI', seo: { title: 'SEO AI' } })

    expect(result.title).toBe('Tiêu đề AI')
    expect(result.content).toBe(form.content)
    expect(result.excerpt).toBe(form.excerpt)
    expect(result.seo).toEqual({ title: 'SEO AI', description: 'Mô tả SEO giữ nguyên' })
    expect(result.categories).toEqual([1])
  })

  it('reports only populated fields whose value will change', () => {
    const form = { title: 'Người dùng', excerpt: '', seo: { title: 'SEO người dùng', description: '' } }

    expect(overwrittenFields(form, {
      title: 'AI',
      excerpt: 'AI excerpt',
      seo: { title: 'SEO AI', description: 'SEO description AI' },
    })).toEqual(['title', 'SEO title'])
  })
})
