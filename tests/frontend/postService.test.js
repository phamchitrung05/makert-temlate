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
      thumbnail: { id: 7 }, galleryImages: [{ id: 3 }, { id: 2 }], slug: 'preview', score: 100,
    })

    const body = mocks.api.mock.calls[0][1].body

    expect(body).toMatchObject({ 'seo_title': 'SEO', 'seo_description': 'Description', 'focus_keyword': 'word', 'robots_index': false, media: { 'thumbnail_id': 7, 'gallery_image_ids': [3, 2] } })
    expect(body.media).not.toHaveProperty('content_image_ids')
    expect(body).not.toHaveProperty('slug')
    expect(body).not.toHaveProperty('score')
    expect(result.slug).toBe('real-slug')
    expect(result.media.gallery_images).toEqual([])
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

  it('reads the gallery independently and submits HTML with repeated links unchanged', async () => {
    const html = '<img src="https://example.test/a.jpg" alt="A"><img src="https://example.test/a.jpg" alt="B">'
    const gallery = [{ id: 8 }, { id: 7 }]

    mocks.api.mockResolvedValue({ success: true, data: { id: 3, content: html, media: { 'content_images': [{ id: 99 }], 'gallery_images': gallery } } })

    const post = await postService.show(3)

    expect(post.media.gallery_images).toEqual(gallery)
    expect(post.media).not.toHaveProperty('content_images')
    await postService.update(3, { title: 'Post', content: html, galleryImages: gallery })
    expect(mocks.api.mock.calls[1][1].body).toMatchObject({ content: html, media: { 'gallery_image_ids': [8, 7] } })
  })

})
