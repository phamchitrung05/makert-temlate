/* eslint-disable camelcase */

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Mô phỏng CRUD API cho Resource admin trong local
 * =====================================================================
 *
 * MSW handler này giữ cùng route, permission-independent payload và response
 * contract với ResourceController Laravel. Dataset chỉ sống trong memory của
 * browser; reload trang sẽ nạp lại db mẫu ban đầu.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - normalizeSortBy(): chuẩn hóa khóa sort từ query
 * - compareValues(): so sánh hai dòng theo chiều sort
 * - buildPagination(): tạo metadata phân trang
 * - toResourceItem(): chuyển record fake thành ResourceItem detail
 * - findResource(): tìm record theo id
 * - parseBody(): đọc JSON body an toàn
 * - validationError(): tạo response lỗi validation
 * - createRecord(): thêm record mới vào memory db
 * - updateRecord(): cập nhật record trong memory db
 * - handlerAppsResources: xử lý list/detail/CRUD/publish/archive
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : request HTTP từ resourceService
 * - OUTPUT: HttpResponse theo BaseResponse contract; không ghi database thật
 * =====================================================================
 */
import is from '@sindresorhus/is'
import { destr } from 'destr'
import { HttpResponse, http } from 'msw'
import { paginateArray } from '@api-utils/paginateArray'
import { db } from '@db/apps/resources/db'

/**
 * Chuẩn hóa khóa sort từ các dạng query mà VDataTableServer có thể phát ra.
 *
 * Input: sortBy dạng string, JSON string hoặc array descriptor.
 * Output: tên field dùng để sort hoặc chuỗi rỗng nếu không hợp lệ.
 */
const normalizeSortBy = value => {
  const parsed = destr(value)

  if (Array.isArray(parsed)) {
    const first = parsed[0]

    return typeof first === 'string' ? first : first?.key ?? ''
  }

  return is.string(parsed) ? parsed : ''
}

/**
 * So sánh hai resource theo field được bảng yêu cầu.
 *
 * Input: hai dòng resource, sort key và chiều sort.
 * Output: số âm, 0 hoặc số dương theo Array.prototype.sort().
 */
const compareValues = (left, right, sortBy, orderBy) => {
  const valueFor = resource => {
    if (sortBy === 'views')
      return resource.counters.views

    if (sortBy === 'downloads')
      return resource.counters.downloads

    return resource[sortBy] ?? ''
  }

  const leftValue = valueFor(left)
  const rightValue = valueFor(right)
  const multiplier = orderBy === 'desc' ? -1 : 1

  if (typeof leftValue === 'number' && typeof rightValue === 'number')
    return (leftValue - rightValue) * multiplier

  return String(leftValue).localeCompare(String(rightValue)) * multiplier
}

/**
 * Tạo metadata phân trang theo shape mà BaseResponse trả về.
 *
 * Input: page, perPage, total và request URL hiện tại.
 * Output: object pagination gồm current_page, last_page, total và path.
 */
const buildPagination = (page, perPage, total, requestUrl) => {
  const lastPage = Math.max(1, Math.ceil(total / perPage))
  const from = total === 0 ? null : (page - 1) * perPage + 1
  const to = total === 0 ? null : Math.min(page * perPage, total)

  return {
    current_page: page,
    per_page: perPage,
    total,
    last_page: lastPage,
    from,
    to,
    path: new URL(requestUrl).pathname,
  }
}

/**
 * Chuyển record fake sang shape ResourceItem của endpoint detail/mutation.
 *
 * Input: record trong memory db.
 * Output: payload detail có field snake_case giống Laravel JsonResource.
 */
const toResourceItem = resource => ({
  id: resource.id,
  type: resource.type,
  title: resource.title,
  code: resource.code,
  slug: resource.slug ?? resource.title?.toLowerCase().replaceAll(/[^a-z0-9]+/g, '-'),
  short_description: resource.short_description ?? '',
  description: resource.description ?? '',
  status: resource.status,
  visibility: resource.visibility,
  is_featured: Boolean(resource.is_featured),
  demo_url: resource.demo_url ?? '',
  documentation_url: resource.documentation_url ?? '',
  seo: {
    title: resource.seo_title ?? null,
    description: resource.seo_description ?? null,
    canonical_url: resource.canonical_url ?? null,
  },
  counters: resource.counters ?? { views: 0, downloads: 0 },
  taxonomy: {
    categories: (resource.categories ?? []).map((name, index) => ({ id: index + 1, name })),
    tags: (resource.tags ?? []).map((name, index) => ({ id: index + 1, name })),
    technologies: (resource.technologies ?? []).map((name, index) => ({ id: index + 1, name })),
  },
  author: { id: 1, name: resource.author_name ?? 'Development Admin' },
  published_at: resource.published_at,
  created_at: resource.created_at ?? resource.updated_at,
  updated_at: resource.updated_at,
})

/**
 * Tìm resource theo id trong memory db.
 *
 * Input: id dạng number hoặc string từ route param.
 * Output: record hoặc undefined nếu không tồn tại.
 */
const findResource = id => db.resources.find(resource => resource.id === Number(id))

/**
 * Đọc body JSON của request mà không làm handler lỗi ngoài ý muốn.
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
 * Tạo response lỗi validation theo envelope frontend đang xử lý.
 *
 * Input: errors theo field và HTTP status.
 * Output: HttpResponse JSON lỗi.
 */
const validationError = (errors, status = 422) => HttpResponse.json({
  success: false,
  message: 'Dữ liệu resource không hợp lệ.',
  data: null,
  errors,
  meta: {},
}, { status })

/**
 * Tạo record resource mới trong memory db.
 *
 * Input: payload snake_case từ resourceService.
 * Output: record vừa thêm.
 * Side effect: push một record vào db.resources.
 */
const createRecord = payload => {
  const now = new Date().toISOString()
  const id = Math.max(0, ...db.resources.map(resource => resource.id)) + 1

  const record = {
    id,
    type: payload.type,
    title: payload.title,
    code: payload.code || `RESOURCE-${String(id).padStart(3, '0')}`,
    status: payload.status ?? 'draft',
    visibility: payload.visibility ?? 'private',
    is_featured: Boolean(payload.is_featured),
    short_description: payload.short_description ?? '',
    description: payload.description ?? '',
    demo_url: payload.demo_url ?? '',
    documentation_url: payload.documentation_url ?? '',
    counters: { views: 0, downloads: 0 },
    author_name: 'Development Admin',
    published_at: payload.status === 'published' ? now : null,
    updated_at: now,
    created_at: now,
    categories: [],
    tags: [],
    technologies: [],
  }

  db.resources.unshift(record)

  return record
}

/**
 * Cập nhật các field được phép của record trong memory db.
 *
 * Input: record hiện tại và payload snake_case.
 * Output: record sau cập nhật.
 * Side effect: thay đổi record trong db.resources.
 */
const updateRecord = (resource, payload) => {
  const editableFields = [
    'type',
    'title',
    'code',
    'status',
    'visibility',
    'is_featured',
    'short_description',
    'description',
    'demo_url',
    'documentation_url',
  ]

  editableFields.forEach(field => {
    if (payload[field] !== undefined)
      resource[field] = payload[field]
  })

  resource.updated_at = new Date().toISOString()

  if (resource.status === 'published')
    resource.published_at ??= resource.updated_at

  if (resource.status !== 'published')
    resource.published_at = null

  return resource
}

const requiredFields = ['type', 'title', 'status', 'visibility']

export const handlerAppsResources = [
  http.get('/api/admin/resources', ({ request }) => {
    const url = new URL(request.url)
    const search = (url.searchParams.get('search') ?? url.searchParams.get('q') ?? '').toLowerCase()
    const status = url.searchParams.get('status')
    const type = url.searchParams.get('type')
    const categoryId = url.searchParams.get('category_id')
    const pageValue = destr(url.searchParams.get('page'))
    const perPageValue = destr(url.searchParams.get('per_page') ?? url.searchParams.get('itemsPerPage'))
    const page = is.number(pageValue) && pageValue > 0 ? pageValue : 1
    const perPage = is.number(perPageValue) && perPageValue > 0 ? perPageValue : 10
    const sortBy = normalizeSortBy(url.searchParams.get('sortBy'))

    const orderBy = url.searchParams.get('orderBy') === 'desc' || url.searchParams.get('sortDesc') === 'true'
      ? 'desc'
      : 'asc'

    let resources = db.resources.filter(resource => {
      const searchable = [resource.title, resource.code, resource.type, resource.status]
        .join(' ')
        .toLowerCase()

      const matchesSearch = !search || searchable.includes(search)
      const matchesStatus = !status || status === 'all' || resource.status === status
      const matchesType = !type || type === 'all' || resource.type === type
      const matchesCategory = !categoryId || resource.categories.some(category => category.toLowerCase().includes(categoryId.toLowerCase()))

      return matchesSearch && matchesStatus && matchesType && matchesCategory
    })

    if (sortBy)
      resources = [...resources].sort((left, right) => compareValues(left, right, sortBy, orderBy))

    const total = resources.length
    const lastPage = Math.max(1, Math.ceil(total / perPage))
    const currentPage = Math.min(page, lastPage)
    const items = paginateArray(resources, perPage, currentPage)

    return HttpResponse.json({
      success: true,
      message: 'Danh sách tài nguyên giả lập.',
      data: { items, itemsLength: total },
      errors: [],
      meta: { pagination: buildPagination(currentPage, perPage, total, request.url) },
    }, { status: 200 })
  }),

  http.get('/api/admin/resources/:resource', ({ params }) => {
    const resource = findResource(params.resource)

    if (!resource)
      return validationError({ resource: ['Không tìm thấy resource.'] }, 404)

    return HttpResponse.json({
      success: true,
      message: 'Chi tiết tài nguyên giả lập.',
      data: toResourceItem(resource),
      errors: [],
      meta: {},
    })
  }),

  http.post('/api/admin/resources', async ({ request }) => {
    const payload = await parseBody(request)

    const errors = Object.fromEntries(requiredFields
      .filter(field => !payload[field])
      .map(field => [field, ['Trường này là bắt buộc.']]))

    if (Object.keys(errors).length)
      return validationError(errors)

    if (db.resources.some(resource => payload.code && resource.code === payload.code))
      return validationError({ code: ['Code đã tồn tại.'] })

    return HttpResponse.json({
      success: true,
      message: 'Tạo tài nguyên giả lập thành công.',
      data: toResourceItem(createRecord(payload)),
      errors: [],
      meta: {},
    }, { status: 201 })
  }),

  http.put('/api/admin/resources/:resource', async ({ params, request }) => {
    const resource = findResource(params.resource)

    if (!resource)
      return validationError({ resource: ['Không tìm thấy resource.'] }, 404)

    const payload = await parseBody(request)

    if (db.resources.some(item => item.id !== resource.id && payload.code && item.code === payload.code))
      return validationError({ code: ['Code đã tồn tại.'] })

    return HttpResponse.json({
      success: true,
      message: 'Cập nhật tài nguyên giả lập thành công.',
      data: toResourceItem(updateRecord(resource, payload)),
      errors: [],
      meta: {},
    })
  }),

  http.delete('/api/admin/resources/:resource', ({ params }) => {
    const index = db.resources.findIndex(resource => resource.id === Number(params.resource))

    if (index === -1)
      return validationError({ resource: ['Không tìm thấy resource.'] }, 404)

    db.resources.splice(index, 1)

    return new HttpResponse(null, { status: 204 })
  }),

  http.post('/api/admin/resources/:resource/publish', ({ params }) => {
    const resource = findResource(params.resource)

    if (!resource)
      return validationError({ resource: ['Không tìm thấy resource.'] }, 404)

    if (!['draft', 'pending_review', 'rejected'].includes(resource.status))
      return validationError({ status: ['Resource không thể publish từ trạng thái hiện tại.'] })

    updateRecord(resource, { status: 'published' })

    return HttpResponse.json({
      success: true,
      message: 'Xuất bản tài nguyên giả lập thành công.',
      data: toResourceItem(resource),
      errors: [],
      meta: {},
    })
  }),

  http.post('/api/admin/resources/:resource/archive', ({ params }) => {
    const resource = findResource(params.resource)

    if (!resource)
      return validationError({ resource: ['Không tìm thấy resource.'] }, 404)

    updateRecord(resource, { status: 'archived' })

    return HttpResponse.json({
      success: true,
      message: 'Lưu trữ tài nguyên giả lập thành công.',
      data: toResourceItem(resource),
      errors: [],
      meta: {},
    })
  }),
]
