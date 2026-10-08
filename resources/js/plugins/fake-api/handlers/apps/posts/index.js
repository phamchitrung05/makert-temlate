/* eslint-disable camelcase */
import { HttpResponse, http } from 'msw'
import { db } from '@db/apps/posts/db'
import { db as mediaDb } from '@db/apps/media/db'
import { db as usersDb } from '@db/apps/users/db'

const error = (errors, status = 422) => HttpResponse.json({ success: false, message: 'Dữ liệu bài viết không hợp lệ.', data: null, errors, meta: {} }, { status })
const find = id => db.posts.find(item => item.id === Number(id))
const findUser = id => usersDb.users.find(user => user.id === Number(id))

const toPost = post => {
  const user = findUser(post.created_by)

  return {
    ...structuredClone(post),
    author: user ? { id: user.id, name: user.fullName, email: user.email } : null,
    media: { thumbnail: post.media.thumbnail_id ? mediaDb.mediaAssets.find(asset => asset.id === post.media.thumbnail_id) ?? null : null, gallery_images: post.media.gallery_image_ids.map(id => mediaDb.mediaAssets.find(asset => asset.id === id)).filter(Boolean) },
  }
}

const parse = async request => { try { return await request.json() } catch { return {} } }

export const handlerAppsPosts = [
  http.get('/api/admin/posts', ({ request }) => {
    const url = new URL(request.url)
    const search = (url.searchParams.get('search') ?? '').toLowerCase()
    const status = url.searchParams.get('status')
    const authorId = url.searchParams.get('author_id')
    const createdFrom = url.searchParams.get('created_from')
    const createdTo = url.searchParams.get('created_to')

    const items = db.posts.filter(post => {
      const createdDate = post.created_at.slice(0, 10)

      return (!search || post.title.toLowerCase().includes(search))
        && (!status || post.status === status)
        && (!authorId || post.created_by === Number(authorId))
        && (!createdFrom || createdDate >= createdFrom)
        && (!createdTo || createdDate <= createdTo)
    })
    
    return HttpResponse.json({ success: true, message: 'Danh sách bài viết giả lập.', data: items.map(toPost), errors: [], meta: { pagination: { current_page: 1, per_page: items.length || 15, total: items.length, last_page: 1 } } })
  }),
  http.get('/api/admin/posts/authors', () => {
    const authorIds = [...new Set(db.posts.map(post => post.created_by).filter(Boolean))]

    const authors = authorIds
      .map(findUser)
      .filter(Boolean)
      .map(user => ({ id: user.id, name: user.fullName, email: user.email }))
      .sort((first, second) => first.name.localeCompare(second.name))

    return HttpResponse.json({ success: true, message: 'Danh sách tác giả giả lập.', data: authors, errors: [], meta: {} })
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
    const post = { id: Math.max(0, ...db.posts.map(item => item.id)) + 1, created_by: 1, title: payload.title, content: payload.content ?? '', status: 'draft', published_at: null, media: { thumbnail_id: payload.media?.thumbnail_id ?? null, gallery_image_ids: payload.media?.gallery_image_ids ?? [] }, created_at: now, updated_at: now }

    db.posts.unshift(post)
    
    return HttpResponse.json({ success: true, message: 'Tạo bài viết giả lập thành công.', data: toPost(post), errors: [], meta: {} }, { status: 201 })
  }),
  http.put('/api/admin/posts/:post', async ({ params, request }) => {
    const post = find(params.post)
    if (!post)
      return error({ post: ['Không tìm thấy bài viết.'] }, 404)
    const payload = await parse(request)

    Object.assign(post, { title: payload.title ?? post.title, content: payload.content ?? post.content, updated_at: new Date().toISOString() })
    if (payload.media !== undefined) {
      if (Object.hasOwn(payload.media, 'thumbnail_id')) post.media.thumbnail_id = payload.media.thumbnail_id
      if (Object.hasOwn(payload.media, 'gallery_image_ids')) post.media.gallery_image_ids = payload.media.gallery_image_ids
    }
    
    return HttpResponse.json({ success: true, message: 'Cập nhật bài viết giả lập thành công.', data: toPost(post), errors: [], meta: {} })
  }),
  http.post('/api/admin/posts/:post/submit-review', ({ params }) => {
    const post = find(params.post)
    if (!post)
      return error({ post: ['Không tìm thấy bài viết.'] }, 404)
    if (!['draft', 'rejected'].includes(post.status))
      return error({ status: ['Bài viết không ở trạng thái có thể gửi review.'] })
    post.status = 'pending_review'
    post.updated_at = new Date().toISOString()

    return HttpResponse.json({ success: true, message: 'Đã gửi bài viết để review.', data: toPost(post), errors: [], meta: {} })
  }),
  http.post('/api/admin/posts/:post/publish', ({ params }) => {
    const post = find(params.post)
    if (!post)
      return error({ post: ['Không tìm thấy bài viết.'] }, 404)
    if (!['draft', 'pending_review', 'rejected'].includes(post.status))
      return error({ status: ['Bài viết không ở trạng thái có thể publish.'] })
    post.status = 'published'
    post.published_at ??= new Date().toISOString()
    post.updated_at = new Date().toISOString()

    return HttpResponse.json({ success: true, message: 'Xuất bản bài viết thành công.', data: toPost(post), errors: [], meta: {} })
  }),
  http.post('/api/admin/posts/:post/reject', async ({ params, request }) => {
    const post = find(params.post)
    if (!post)
      return error({ post: ['Không tìm thấy bài viết.'] }, 404)
    const payload = await parse(request)
    if (!payload.reason || post.status !== 'pending_review')
      return error({ reason: ['Bài viết phải đang chờ review và cần lý do.'] })
    post.status = 'rejected'
    post.updated_at = new Date().toISOString()

    return HttpResponse.json({ success: true, message: 'Đã từ chối bài viết.', data: toPost(post), errors: [], meta: {} })
  }),
  http.post('/api/admin/posts/:post/archive', ({ params }) => {
    const post = find(params.post)
    if (!post)
      return error({ post: ['Không tìm thấy bài viết.'] }, 404)
    if (post.status === 'archived')
      return error({ status: ['Bài viết đã được archive.'] })
    post.status = 'archived'
    post.updated_at = new Date().toISOString()

    return HttpResponse.json({ success: true, message: 'Đã lưu trữ bài viết.', data: toPost(post), errors: [], meta: {} })
  }),
  http.delete('/api/admin/posts/:post', ({ params }) => {
    const index = db.posts.findIndex(item => item.id === Number(params.post))
    if (index < 0)
      return error({ post: ['Không tìm thấy bài viết.'] }, 404)
    db.posts.splice(index, 1)
    
    return new HttpResponse(null, { status: 204 })
  }),
]
