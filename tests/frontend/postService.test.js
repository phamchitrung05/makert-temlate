import { beforeEach, describe, expect, it, vi } from 'vitest'
import { postService } from '@/services/post'
import { createSeo } from '@/composables/seoMetadata'

const mocks = vi.hoisted(() => ({ api: vi.fn() }))

vi.mock('@/utils/api', () => ({ $api: mocks.api }))

describe('Post API payload', () => {
  beforeEach(() => mocks.api.mockReset())

  it('maps SEO and ordered media IDs, excludes preview slug and client score', async () => {
    mocks.api.mockResolvedValue({ success: true, data: { id: 1, slug: 'real-slug' } })

    const result = await postService.create({
      title: 'Title', content: '<p>Content</p>', excerpt: 'Summary', status: 'draft',
      seo: { ...createSeo(), title: 'SEO', description: 'Description', focusKeyword: 'word', robotsIndex: false },
      thumbnail: { id: 7 }, contentImages: [{ id: 3 }, { id: 2 }], slug: 'preview', score: 100,
    })

    const body = mocks.api.mock.calls[0][1].body

    expect(body).toMatchObject({ 'seo_title': 'SEO', 'seo_description': 'Description', 'focus_keyword': 'word', 'robots_index': false, media: { 'thumbnail_id': 7, 'content_image_ids': [3, 2] } })
    expect(body).not.toHaveProperty('slug')
    expect(body).not.toHaveProperty('score')
    expect(result.slug).toBe('real-slug')
    expect(result.media.content_images).toEqual([])
  })

  it('sends only selected AI lineage metadata with the Post payload', async () => {
    mocks.api.mockResolvedValue({ success: true, data: { id: 2 } })

    await postService.create({
      title: 'AI title', content: 'AI content', status: 'draft',
      aiProvenance: { runId: 'run-uuid', fields: ['title', 'content'] },
    })

    const body = mocks.api.mock.calls[0][1].body

    expect(body).toMatchObject({ 'ai_run_id': 'run-uuid', 'ai_fields': ['title', 'content'] })
  })

})
