/* eslint-disable camelcase */
import { HttpResponse, http } from 'msw'
import { db } from '@db/apps/resourceVersions/db'
import { db as mediaDb } from '@db/apps/media/db'

const error = (errors, status = 422) => HttpResponse.json({ success: false, message: 'Dữ liệu version không hợp lệ.', data: null, errors, meta: {} }, { status })
const find = id => db.resourceVersions.find(item => item.id === Number(id))

const toResource = version => ({
  ...structuredClone(version),
  media: {
    package: version.media.package_id ? mediaDb.mediaAssets.find(asset => asset.id === version.media.package_id) ?? null : null,
    documentation: version.media.documentation_ids.map(id => mediaDb.mediaAssets.find(asset => asset.id === id)).filter(Boolean),
  },
})

const parse = async request => {
  try { return await request.json() } catch { return {} }
}

const applyMedia = (version, payload) => {
  if (payload.media === undefined)
    return
  version.media = {
    package_id: payload.media?.package_id ?? null,
    documentation_ids: payload.media?.documentation_ids ?? [],
  }
}

export const handlerAppsResourceVersions = [
  http.get('/api/admin/resource-versions', ({ request }) => {
    const url = new URL(request.url)
    const resourceId = Number(url.searchParams.get('resource_id')) || null
    const items = db.resourceVersions.filter(item => !resourceId || item.resource_id === resourceId)

    return HttpResponse.json({ success: true, message: 'Danh sách resource version giả lập.', data: items.map(toResource), errors: [], meta: { pagination: { current_page: 1, per_page: items.length || 15, total: items.length, last_page: 1 } } })
  }),
  http.get('/api/admin/resource-versions/:resourceVersion', ({ params }) => {
    const version = find(params.resourceVersion)
    
    return version
      ? HttpResponse.json({ success: true, message: 'Chi tiết version giả lập.', data: toResource(version), errors: [], meta: {} })
      : error({ resource_version: ['Không tìm thấy version.'] }, 404)
  }),
  http.post('/api/admin/resource-versions', async ({ request }) => {
    const payload = await parse(request)
    if (!payload.resource_id || !payload.version)
      return error({ version: ['Resource và version là bắt buộc.'] })
    const now = new Date().toISOString()
    const version = { id: Math.max(0, ...db.resourceVersions.map(item => item.id)) + 1, resource_id: Number(payload.resource_id), version: payload.version, changelog: payload.changelog ?? '', requirements: payload.requirements ?? null, status: 'draft', is_default: Boolean(payload.is_default), released_at: null, media: { package_id: payload.media?.package_id ?? null, documentation_ids: payload.media?.documentation_ids ?? [] }, created_at: now, updated_at: now }

    db.resourceVersions.unshift(version)
    
    return HttpResponse.json({ success: true, message: 'Tạo version giả lập thành công.', data: toResource(version), errors: [], meta: {} }, { status: 201 })
  }),
  http.put('/api/admin/resource-versions/:resourceVersion', async ({ params, request }) => {
    const version = find(params.resourceVersion)
    if (!version)
      return error({ resource_version: ['Không tìm thấy version.'] }, 404)
    const payload = await parse(request)

    Object.assign(version, { version: payload.version ?? version.version, changelog: payload.changelog ?? version.changelog, requirements: payload.requirements ?? version.requirements, is_default: payload.is_default ?? version.is_default, updated_at: new Date().toISOString() })
    applyMedia(version, payload)
    
    return HttpResponse.json({ success: true, message: 'Cập nhật version giả lập thành công.', data: toResource(version), errors: [], meta: {} })
  }),
  http.delete('/api/admin/resource-versions/:resourceVersion', ({ params }) => {
    const index = db.resourceVersions.findIndex(item => item.id === Number(params.resourceVersion))
    if (index < 0)
      return error({ resource_version: ['Không tìm thấy version.'] }, 404)
    db.resourceVersions.splice(index, 1)
    
    return new HttpResponse(null, { status: 204 })
  }),
  http.post('/api/admin/resource-versions/:resourceVersion/ready', ({ params }) => {
    const version = find(params.resourceVersion)
    if (!version)
      return error({ resource_version: ['Không tìm thấy version.'] }, 404)
    const packageAsset = version.media.package_id ? mediaDb.mediaAssets.find(asset => asset.id === version.media.package_id) : null
    if (!packageAsset || packageAsset.kind !== 'archive' || packageAsset.visibility !== 'private' || packageAsset.file?.scan_status !== 'clean')
      return error({ package: ['Package phải là archive private và scan clean.'] })
    version.status = 'ready'
    version.released_at = new Date().toISOString()
    
    return HttpResponse.json({ success: true, message: 'Version ready giả lập.', data: toResource(version), errors: [], meta: {} })
  }),
]
