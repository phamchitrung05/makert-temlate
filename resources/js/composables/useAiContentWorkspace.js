/* eslint-disable camelcase -- DTO dùng contract Laravel AI Agent. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: State form tạo mới và danh sách tác vụ Ai Content độc lập.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: toListItem(), useAiContentWorkspace(), loadItems(),
 * updateSession(), removeItem(), resetSource(), cleanup scope.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : summary API và lifecycle tác vụ vừa tạo.
 * - OUTPUT: form nguồn mới và list reactive; không có bài đang chọn/đang sửa.
 * - SIDE EFFECT: GET toàn bộ trang summary, giữ cập nhật mới khi GET đang chạy.
 * =====================================================================
 */
import { computed, onScopeDispose, shallowRef } from 'vue'
import { aiAgentService } from '@/services/aiAgent'
import { createAiContentSource } from '@/utils/aiContentInput'

/** Input: summary và bản ghi cũ tùy chọn. Output: dòng UI; không mutate DTO. */
function toListItem(summary, previous) {
  const status = summary.applied_target_id ? 'applied'
    : ['ready', 'completed', 'succeeded'].includes(summary.status) ? 'review'
      : ['failed', 'cancelled', 'expired'].includes(summary.status) ? summary.status : 'generating'

  const createdAt = summary.created_at ?? previous?.createdAt ?? new Date().toISOString()
  const date = new Date(createdAt)

  return {
    id: summary.id ?? summary.job_id, status, createdAt,
    targetType: summary.target_type ?? previous?.targetType ?? 'post',
    parentId: summary.parent_id ?? previous?.parentId ?? null,
    operation: summary.operation ?? previous?.operation ?? 'create',
    title: summary.title || summary.draft?.title || previous?.title || (status === 'generating' ? 'Đang tạo bài viết…' : 'Bài viết AI'),
    source: summary.source_host || previous?.source || (summary.source_type === 'url' ? 'Nguồn URL' : 'Nội dung văn bản'),
    date: date.toLocaleDateString('vi-VN'), time: date.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }),
  }
}

/** Input: không có. Output: state độc lập theo scope; không lưu Post hoặc provider key cục bộ. */
export function useAiContentWorkspace() {
  const source = shallowRef(createAiContentSource())
  const listedItems = shallowRef([])
  const liveItems = shallowRef({})
  const removedIds = new Set()
  const listLoading = shallowRef(false)
  const listError = shallowRef('')
  let loadVersion = 0

  const items = computed(() => {
    const records = new Map(listedItems.value.map(item => [item.id, item]))

    Object.values(liveItems.value).forEach(item => records.set(item.id, item))

    return [...records.values()].filter(item => !removedIds.has(item.id)).sort((a, b) => b.createdAt.localeCompare(a.createdAt))
  })

  /** Input: mở trang/tải lại. Output: toàn bộ summary còn hạn, lỗi có thể retry; chỉ GET Admin API. */
  async function loadItems() {
    const version = ++loadVersion
    const liveBeforeLoad = liveItems.value

    listLoading.value = true
    listError.value = ''
    try {
      const summaries = []
      let page = 1
      let lastPage = 1
      do {
        const response = await aiAgentService.listSessions({ page, per_page: 100 })
        if (version !== loadVersion) return
        summaries.push(...response.data)
        lastPage = response.meta.pagination.last_page
        page += 1
      } while (page <= lastPage)
      listedItems.value = summaries.map(summary => toListItem(summary))
      liveItems.value = Object.fromEntries(Object.entries(liveItems.value).filter(([id, item]) => item !== liveBeforeLoad[id]))
    }
    catch (error) {
      if (version === loadVersion) listError.value = error?.data?.message || 'Không tải được danh sách bài AI. Hãy thử lại.'
    }
    finally {
      if (version === loadVersion) listLoading.value = false
    }
  }

  /** Input: response create/poll. Output: upsert đúng tác vụ; không ảnh hưởng form nguồn. */
  function updateSession(session) {
    const id = session.job_id
    if (removedIds.has(id)) return
    const previous = liveItems.value[id] ?? listedItems.value.find(item => item.id === id)

    liveItems.value = { ...liveItems.value, [id]: toListItem(session, previous) }
  }

  /** Input: UUID đã xóa thành công. Output: bỏ item và chặn callback/GET cũ phục hồi item. */
  function removeItem(id) {
    removedIds.add(id)
    listedItems.value = listedItems.value.filter(item => item.id !== id)
    liveItems.value = Object.fromEntries(Object.entries(liveItems.value).filter(([key]) => key !== id))
  }

  /** Input: không có. Output: nguồn trống giữ provider/model; không thêm bản ghi giả vào list. */
  function resetSource() {
    source.value = { ...createAiContentSource(), targetType: source.value.targetType, provider: source.value.provider, model: source.value.model }
  }

  onScopeDispose(() => { loadVersion += 1 })

  return { source, items, listLoading, listError, loadItems, updateSession, removeItem, resetSource }
}
