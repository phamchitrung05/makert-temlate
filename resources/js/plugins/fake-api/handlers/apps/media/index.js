/* eslint-disable camelcase */

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Mô phỏng Media Library API cho admin local
 * =====================================================================
 *
 * Handler giữ route và BaseResponse contract giống MediaAssetController để
 * `mediaAssetService` có thể chuyển giữa MSW và Laravel mà không đổi page/store.
 * Dataset chỉ tồn tại trong memory của browser; reload sẽ khôi phục dữ liệu mẫu.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - parseBody()/toAsset(): đọc body và định hình response asset
 * - buildPagination(): tạo metadata phân trang
 * - findAsset()/findUsage()/nextId()/nextUsageId(): truy cập dataset trong memory
 * - validationError(): tạo response lỗi cùng envelope Laravel
 * - handlerAppsMedia: xử lý list/detail/upload/usage/mutation/download
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : request HTTP từ mediaAssetService.
 * - OUTPUT: HttpResponse theo BaseResponse contract; không ghi database thật.
 * =====================================================================
 */
import { HttpResponse, http } from 'msw'
import { db } from '@db/apps/media/db'

/**
 * Đọc JSON body an toàn cho các mutation metadata/usage.
 *
 * Input: Request MSW.
 * Output: object payload hoặc object rỗng khi body không hợp lệ.
 */
const parseBody = async request => {
  try {
    return await request.json()
  }
  catch {
    return {}
  }
}

/**
 * Tạo pagination metadata giống Laravel paginator.
 *
 * Input: page, perPage, total và URL request.
 * Output: object pagination dùng bởi service/store.
 */
const buildPagination = (page, perPage, total, requestUrl) => ({
  current_page: page,
  per_page: perPage,
  total,
  last_page: Math.max(1, Math.ceil(total / perPage)),
  from: total === 0 ? null : (page - 1) * perPage + 1,
  to: total === 0 ? null : Math.min(page * perPage, total),
  path: new URL(requestUrl).pathname,
})

/**
 * Tìm asset theo id trong dataset fake.
 *
 * Input: id route dạng string hoặc number.
 * Output: asset hoặc undefined nếu không tồn tại.
 */
const findAsset = id => db.mediaAssets.find(asset => asset.id === Number(id))

/**
 * Tìm usage thuộc một asset trong dataset fake.
 *
 * Input: asset và usage id từ route.
 * Output: usage hoặc undefined khi không thuộc asset.
 */
const findUsage = (asset, id) => asset?.usages?.find(usage => usage.id === Number(id))

/**
 * Tạo id mới cho asset và media item fake.
 *
 * Input: không có.
 * Output: số nguyên lớn hơn mọi id hiện tại.
 */
const nextId = () => Math.max(0, ...db.mediaAssets.map(asset => asset.id)) + 1

/**
 * Tạo id usage tăng dần trên toàn bộ dataset fake.
 *
 * Input: không có.
 * Output: số nguyên usage id mới.
 */
const nextUsageId = () => Math.max(0, ...db.mediaAssets.flatMap(asset => asset.usages?.map(usage => usage.id) ?? [])) + 1

/**
 * Định hình asset trước khi trả response, giữ contract list/detail nhất quán.
 *
 * Input: record trong memory db.
 * Output: bản sao asset không bị handler khác mutate ngoài ý muốn.
 */
const toAsset = asset => structuredClone(asset)

/**
 * Tạo response lỗi validation theo envelope Laravel.
 *
 * Input: errors theo field và HTTP status.
 * Output: HttpResponse JSON lỗi.
 */
const validationError = (errors, status = 422) => HttpResponse.json({
  success: false,
  message: 'Dữ liệu media không hợp lệ.',
  data: null,
  errors,
  meta: {},
}, { status })

/**
 * Cập nhật timestamp của asset sau mutation fake.
 *
 * Input: asset đang nằm trong memory db.
 * Output: asset sau khi cập nhật timestamp.
 * Side effect: thay đổi updated_at của record.
 */
const touchAsset = asset => {
  asset.updated_at = new Date().toISOString()

  return asset
}

export const handlerAppsMedia = [
  http.get('/api/admin/media-assets', ({ request }) => {
    const url = new URL(request.url)
    const search = (url.searchParams.get('search') ?? '').toLowerCase()
    const kind = url.searchParams.get('kind')
    const field = url.searchParams.get('field')
    const visibility = url.searchParams.get('visibility')
    const scanStatus = url.searchParams.get('scan_status')
    const sort = url.searchParams.get('sort') ?? 'created_at'
    const direction = url.searchParams.get('direction') === 'asc' ? 'asc' : 'desc'
    const pageValue = Number(url.searchParams.get('page') ?? 1)
    const perPageValue = Number(url.searchParams.get('per_page') ?? 20)
    const perPage = Number.isInteger(perPageValue) && perPageValue > 0 ? Math.min(perPageValue, 100) : 20
    const page = Number.isInteger(pageValue) && pageValue > 0 ? pageValue : 1

    const fieldKinds = {
      'post.thumbnail': 'image',
      'post.gallery': 'image',
      'resource.cover': 'image',
      'resource.preview': 'image',
      'resource_version.package': 'archive',
      'resource_version.documentation': 'document',
    }

    if (field && !fieldKinds[field])
      return validationError({ field: ['Field không hợp lệ.'] })
    if (field && kind && fieldKinds[field] !== kind)
      return validationError({ field: ['Field không tương thích với kind của asset.'] })

    const effectiveKind = kind || fieldKinds[field] || null

    let assets = db.mediaAssets.filter(asset => {
      const fileName = asset.file?.original_name ?? asset.file?.file_name ?? ''
      const matchesSearch = !search || `${asset.title} ${fileName}`.toLowerCase().includes(search)
      const matchesKind = !effectiveKind || asset.kind === effectiveKind
      const matchesVisibility = !visibility || asset.visibility === visibility
      const matchesScanStatus = !scanStatus || asset.file?.scan_status === scanStatus

      return matchesSearch && matchesKind && matchesVisibility && matchesScanStatus
    })

    assets = [...assets].sort((left, right) => {
      const leftValue = left[sort] ?? left.file?.[sort] ?? ''
      const rightValue = right[sort] ?? right.file?.[sort] ?? ''
      const comparison = String(leftValue).localeCompare(String(rightValue))

      return direction === 'asc' ? comparison : -comparison
    })

    const total = assets.length
    const lastPage = Math.max(1, Math.ceil(total / perPage))
    const currentPage = Math.min(page, lastPage)
    const start = (currentPage - 1) * perPage
    const items = assets.slice(start, start + perPage).map(toAsset)

    return HttpResponse.json({
      success: true,
      message: 'Danh sách media asset giả lập.',
      data: { items, itemsLength: total },
      errors: [],
      meta: { pagination: buildPagination(currentPage, perPage, total, request.url) },
    })
  }),

  http.get('/api/admin/media-assets/:mediaAsset', ({ params }) => {
    const asset = findAsset(params.mediaAsset)

    if (!asset)
      return validationError({ media_asset: ['Không tìm thấy file.'] }, 404)

    return HttpResponse.json({
      success: true,
      message: 'Chi tiết media asset giả lập.',
      data: toAsset(asset),
      errors: [],
      meta: {},
    })
  }),

  http.post('/api/admin/media-assets/:mediaAsset/usages', async ({ params, request }) => {
    const asset = findAsset(params.mediaAsset)

    if (!asset)
      return validationError({ media_asset: ['Không tìm thấy file.'] }, 404)

    const payload = await parseBody(request)
    const field = String(payload.field ?? '')
    const linkableType = String(payload.linkable_type ?? '')
    const linkableId = Number(payload.linkable_id)

    if (!field || !linkableType || !Number.isInteger(linkableId) || linkableId < 1)
      return validationError({ field: ['Usage cần field, linkable_type và linkable_id hợp lệ.'] })

    const duplicate = asset.usages?.some(usage => (
      usage.field === field
      && usage.linkable_type === linkableType
      && Number(usage.linkable_id) === linkableId
    ))

    if (duplicate)
      return validationError({ field: ['Usage đã tồn tại trong dữ liệu giả lập.'] })

    const usage = {
      id: nextUsageId(),
      media_asset_id: asset.id,
      linkable_type: linkableType,
      linkable_id: linkableId,
      field,
      sort_order: payload.sort_order == null ? null : Number(payload.sort_order),
    }

    asset.usages = [...(asset.usages ?? []), usage]
    touchAsset(asset)

    return HttpResponse.json({
      success: true,
      message: 'Attach usage giả lập thành công.',
      data: usage,
      errors: [],
      meta: {},
    }, { status: 201 })
  }),

  http.delete('/api/admin/media-assets/:mediaAsset/usages/:usage', ({ params }) => {
    const asset = findAsset(params.mediaAsset)

    if (!asset)
      return validationError({ media_asset: ['Không tìm thấy file.'] }, 404)

    const usage = findUsage(asset, params.usage)
    if (!usage)
      return validationError({ usage: ['Không tìm thấy usage thuộc asset.'] }, 404)

    asset.usages = asset.usages.filter(item => item.id !== usage.id)
    touchAsset(asset)

    return new HttpResponse(null, { status: 204 })
  }),

  http.post('/api/admin/media-assets/usages/reorder', async ({ request }) => {
    const payload = await parseBody(request)
    const field = String(payload.field ?? '')
    const linkableType = String(payload.linkable_type ?? '')
    const linkableId = Number(payload.linkable_id)
    const usageIds = Array.isArray(payload.usage_ids) ? payload.usage_ids.map(Number) : []

    const usages = db.mediaAssets.flatMap(asset => asset.usages ?? []).filter(usage => (
      usage.field === field
      && usage.linkable_type === linkableType
      && Number(usage.linkable_id) === linkableId
    ))

    if (!field || !linkableType || !Number.isInteger(linkableId) || !usageIds.length || usages.length !== usageIds.length)
      return validationError({ usage_ids: ['Thứ tự usage không hợp lệ.'] })

    const usageById = new Map(usages.map(usage => [usage.id, usage]))
    if (usageIds.some(id => !usageById.has(id)) || new Set(usageIds).size !== usageIds.length)
      return validationError({ usage_ids: ['Danh sách usage không thuộc cùng field.'] })

    usageIds.forEach((id, index) => { usageById.get(id).sort_order = index })

    return HttpResponse.json({
      success: true,
      message: 'Reorder usage giả lập thành công.',
      data: usageIds.map(id => usageById.get(id)),
      errors: [],
      meta: {},
    })
  }),

  http.post('/api/admin/media-assets', async ({ request }) => {
    const formData = await request.formData()
    const file = formData.get('file')
    const kind = String(formData.get('kind') ?? '')
    const title = String(formData.get('title') ?? '')
    const visibility = String(formData.get('visibility') ?? (kind === 'archive' ? 'private' : 'public'))

    if (!file || typeof file === 'string')
      return validationError({ file: ['File là bắt buộc.'] })
    if (!['image', 'document', 'archive', 'video'].includes(kind))
      return validationError({ kind: ['Kind không hợp lệ.'] })
    if (!title.trim())
      return validationError({ title: ['Title là bắt buộc.'] })
    if (kind === 'archive' && visibility === 'public')
      return validationError({ visibility: ['Archive/package bắt buộc ở private disk.'] })

    const id = nextId()
    const now = new Date().toISOString()
    const fileName = file.name || `media-${id}`
    const extension = fileName.includes('.') ? fileName.split('.').pop() : null
    const scanStatus = kind === 'archive' ? 'pending' : 'clean'
    const conversionStatus = kind === 'image' ? 'pending' : null

    const asset = {
      id,
      kind,
      title: title.trim(),
      alt_text: formData.get('alt_text') ? String(formData.get('alt_text')) : null,
      visibility: kind === 'archive' ? 'private' : visibility,
      created_by: 1,
      owner: { id: 1, name: 'Development Admin' },
      file: {
        id: id + 100,
        original_name: fileName,
        file_name: fileName,
        mime_type: file.type || 'application/octet-stream',
        extension,
        size: file.size,
        checksum_sha256: null,
        scan_status: scanStatus,
        conversion_status: conversionStatus,
        url: visibility === 'public' && kind !== 'archive' ? `/storage/media/${fileName}` : null,
        preview_url: null,
      },
      download_url: `/api/admin/media-assets/${id}/download`,
      usages: [],
      created_at: now,
      updated_at: now,
    }

    db.mediaAssets.unshift(asset)

    return HttpResponse.json({
      success: true,
      message: 'Upload media giả lập thành công.',
      data: toAsset(asset),
      errors: [],
      meta: {},
    }, { status: 201 })
  }),

  http.patch('/api/admin/media-assets/:mediaAsset', async ({ params, request }) => {
    const asset = findAsset(params.mediaAsset)

    if (!asset)
      return validationError({ media_asset: ['Không tìm thấy file.'] }, 404)

    const payload = await parseBody(request)

    if (payload.title !== undefined)
      asset.title = String(payload.title)
    if (payload.alt_text !== undefined)
      asset.alt_text = payload.alt_text
    if (payload.visibility !== undefined) {
      if (asset.kind === 'archive' && payload.visibility === 'public')
        return validationError({ visibility: ['Archive/package bắt buộc ở private disk.'] })

      asset.visibility = payload.visibility
    }

    return HttpResponse.json({
      success: true,
      message: 'Cập nhật metadata giả lập thành công.',
      data: toAsset(touchAsset(asset)),
      errors: [],
      meta: {},
    })
  }),

  http.delete('/api/admin/media-assets/:mediaAsset', ({ params }) => {
    const assetIndex = db.mediaAssets.findIndex(asset => asset.id === Number(params.mediaAsset))

    if (assetIndex === -1)
      return validationError({ media_asset: ['Không tìm thấy file.'] }, 404)
    if (db.mediaAssets[assetIndex].usages.length)
      return validationError({ media_asset: ['Không thể xóa file đang được sử dụng.'] })

    db.mediaAssets.splice(assetIndex, 1)

    return new HttpResponse(null, { status: 204 })
  }),

  http.post('/api/admin/media-assets/:mediaAsset/retry', ({ params }) => {
    const asset = findAsset(params.mediaAsset)

    if (!asset)
      return validationError({ media_asset: ['Không tìm thấy file.'] }, 404)

    if (asset.file.scan_status === 'error')
      asset.file.scan_status = 'pending'
    if (asset.file.conversion_status === 'failed')
      asset.file.conversion_status = 'pending'

    return HttpResponse.json({
      success: true,
      message: 'Media đã được đưa vào hàng đợi retry giả lập.',
      data: toAsset(touchAsset(asset)),
      errors: [],
      meta: {},
    }, { status: 202 })
  }),

  http.get('/api/admin/media-assets/:mediaAsset/download', ({ params }) => {
    const asset = findAsset(params.mediaAsset)

    if (!asset)
      return validationError({ media_asset: ['Không tìm thấy file.'] }, 404)

    return HttpResponse.json({
      success: true,
      message: 'Download URL giả lập.',
      data: {
        url: asset.file.url || `/mock-download/media-assets/${asset.id}`,
        expires_at: new Date(Date.now() + 300000).toISOString(),
      },
      errors: [],
      meta: {},
    })
  }),
]
