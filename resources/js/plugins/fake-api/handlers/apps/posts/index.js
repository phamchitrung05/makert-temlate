/* eslint-disable camelcase */
import { HttpResponse, http } from 'msw'
import { db } from '@db/apps/posts/db'
import { db as mediaDb } from '@db/apps/media/db'

const error = (errors, status = 422) => HttpResponse.json({ success: false, message: 'Dữ liệu bài viết không hợp lệ.', data: null, errors, meta: {} }, { status })
const find = id => db.posts.find(item => item.id === Number(id))
const toPost = post => ({ ...structuredClone(post), media: { thumbnail: post.media.thumbnail_id ? mediaDb.mediaAssets.find(asset => asset.id === post.media.thumbnail_id) ?? null : null, content_images: post.media.content_image_ids.map(id => mediaDb.mediaAssets.find(asset => asset.id === id)).filter(Boolean) } })
const parse = async request => { try { return await request.json() } catch { return {} } }

export const handlerAppsPosts = [
  http.get('/api/admin/posts', ({ request }) => {
    const url = new URL(request.url)
    const search = (url.searchParams.get('search') ?? '').toLowerCase()
    const items = db.posts.filter(post => !search || post.title.toLowerCase().includes(search))
    
    return HttpResponse.json({ success: true, message: 'Danh sách bài viết giả lập.', data: items.map(toPost), errors: [], meta: { pagination: { current_page: 1, per_page: items.length || 15, total: items.length, last_page: 1 } } })
  }),
  http.get('/api/admin/posts/:post', ({ params }) => {
    const post = find(params.post)
    
    return post ? HttpResponse.json({ success: true, message: 'Chi tiết bài viết giả lập.', data: toPost(post), errors: [], meta: {} }) : error({ post: ['Không tìm thấy bài viết.'] }, 404)
  }),
  http.post('/api/admin/posts', async ({ request }) => {
    const payload = await parse(request)
    if (!payload.title)
      return error({ title: ['Title là bắt buộc.'] })
    const now = new Date().toISOString()
    const post = { id: Math.max(0, ...db.posts.map(item => item.id)) + 1, title: payload.title, content: payload.content ?? '', status: payload.status ?? 'draft', media: { thumbnail_id: payload.media?.thumbnail_id ?? null, content_image_ids: payload.media?.content_image_ids ?? [] }, created_at: now, updated_at: now }

    db.posts.unshift(post)
    
    return HttpResponse.json({ success: true, message: 'Tạo bài viết giả lập thành công.', data: toPost(post), errors: [], meta: {} }, { status: 201 })
  }),
  http.put('/api/admin/posts/:post', async ({ params, request }) => {
    const post = find(params.post)
    if (!post)
      return error({ post: ['Không tìm thấy bài viết.'] }, 404)
    const payload = await parse(request)

    Object.assign(post, { title: payload.title ?? post.title, content: payload.content ?? post.content, status: payload.status ?? post.status, updated_at: new Date().toISOString() })
    if (payload.media !== undefined)
      post.media = { thumbnail_id: payload.media?.thumbnail_id ?? null, content_image_ids: payload.media?.content_image_ids ?? [] }
    
    return HttpResponse.json({ success: true, message: 'Cập nhật bài viết giả lập thành công.', data: toPost(post), errors: [], meta: {} })
  }),
  http.delete('/api/admin/posts/:post', ({ params }) => {
    const index = db.posts.findIndex(item => item.id === Number(params.post))
    if (index < 0)
      return error({ post: ['Không tìm thấy bài viết.'] }, 404)
    db.posts.splice(index, 1)
    
    return new HttpResponse(null, { status: 204 })
  }),
]
