/* eslint-disable camelcase -- DTO dùng contract Laravel AI Agent. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: State form tạo mới và danh sách tác vụ Ai Content độc lập.
 * =====================================================================
 * CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
 * - toListItem(): chuẩn hóa summary/detail thành run record có session identity.
 * - groupSessionItems(): gom run thành một dòng hiện tại và history cùng session.
 * - useAiContentWorkspace(): tạo state source/list độc lập theo scope.
 * - loadItems(): đọc toàn bộ summary pagination và giữ live update mới.
 * - updateSession(): upsert run accepted/poll mà không sửa form nguồn.
 * - removeItem(): ẩn run đã xóa và chặn callback/GET cũ phục hồi; các run history còn lại vẫn được gom.
 * - resetSource(): reset nguồn để enqueue liên tiếp, giữ target/provider/model.
 * - items: computed projection session; scope cleanup: bỏ GET đến trễ.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : summary API và lifecycle tác vụ vừa tạo.
 * - OUTPUT: form nguồn mới và list reactive; không có bài đang chọn/đang sửa.
 * - SIDE EFFECT: GET toàn bộ trang summary, giữ cập nhật mới khi GET đang chạy.
 * =====================================================================
 */
import { computed, onScopeDispose, shallowRef } from 'vue'
import { aiAgentService } from '@/services/aiAgent'
import { createAiContentSource } from '@/utils/aiContentInput'
import { formatAiError, isAiSuccess } from '@/utils/aiErrors'

/**
 * =====================================================================
 * CHỨC NĂNG: Chuẩn hóa một summary AiImport thành run record dùng cho UI.
 * =====================================================================
 * INPUT: summary API và bản ghi cùng run trước đó tùy chọn.
 * OUTPUT: record có lifecycle, session/parent, lỗi public và dữ liệu hiển thị.
 * SIDE EFFECT: không mutate DTO, không gọi API hoặc ghi database.
 * EXCEPTION/TRANSACTION: không mở transaction.
 * =====================================================================
 */
function toListItem(summary, previous) {
  const runId = summary.id ?? summary.job_id
  const runStatus = summary.status ?? previous?.runStatus ?? 'queued'
  const status = summary.applied_target_id ? 'applied'
    : summary.review?.status === 'rejected' ? 'rejected'
      : ['ready', 'completed', 'succeeded'].includes(summary.status) ? 'review'
        : ['failed', 'cancelled', 'expired'].includes(summary.status) ? summary.status : 'generating'

  const createdAt = summary.created_at ?? previous?.createdAt ?? new Date().toISOString()
  const date = new Date(createdAt)
  const sessionId = summary.session_id ?? previous?.sessionId ?? summary.parent_id ?? runId

  return {
    id: runId, sessionId, status, runStatus, createdAt,
    generationNo: Number(summary.generation_no ?? previous?.generationNo ?? 1),
    model: summary.model ?? previous?.model ?? null,
    provider: summary.provider ?? previous?.provider ?? null,
    promptKey: summary.prompt_key ?? previous?.promptKey ?? null,
    promptVersion: summary.prompt_version ?? previous?.promptVersion ?? null,
    targetType: summary.target_type ?? previous?.targetType ?? 'post',
    parentId: summary.parent_id ?? previous?.parentId ?? null,
    operation: summary.operation ?? previous?.operation ?? 'create',
    title: summary.title || (isAiSuccess(summary) ? summary.draft?.title : '') || previous?.title || (status === 'generating' ? 'Đang tạo bài viết…' : 'Bài viết AI'),
    error: status === 'failed' ? formatAiError(summary) : '',
    errorCode: summary.error_code ?? null,
    validationErrors: summary.validation_errors ?? [],
    thumbnail: summary.thumbnail === undefined ? previous?.thumbnail ?? null : summary.thumbnail,
    thumbnailGeneration: summary.thumbnail_generation === undefined ? previous?.thumbnailGeneration ?? null : summary.thumbnail_generation,
    review: summary.review ?? previous?.review ?? null,
    qualityEvaluation: summary.quality_evaluation ?? previous?.qualityEvaluation ?? null,
    source: summary.source_host || previous?.source || (summary.source_type === 'url' ? 'Nguồn URL' : 'Nội dung văn bản'),
    date: date.toLocaleDateString('vi-VN'), time: date.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }),
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Gom các run cùng session thành một dòng content hiện tại.
 * =====================================================================
 * INPUT: danh sách run đã chuẩn hóa từ API/live polling.
 * OUTPUT: một dòng cho mỗi session, kèm versions/history và run đang active.
 * SIDE EFFECT: không mutate run hoặc gọi API; chỉ tạo projection cho UI.
 * EXCEPTION/TRANSACTION: không mở transaction.
 * =====================================================================
 */
function groupSessionItems(records) {
  const sessions = new Map()
  const byId = new Map(records.map(record => [record.id, record]))
  const depthOf = record => {
    const visited = new Set([record.id])
    let parent = byId.get(record.parentId)
    let depth = 0
    while (parent && !visited.has(parent.id)) {
      visited.add(parent.id)
      depth += 1
      parent = byId.get(parent.parentId)
    }

    return depth
  }
  const sortVersions = values => [...values].sort((left, right) =>
    right.createdAt.localeCompare(left.createdAt) || depthOf(right) - depthOf(left) || right.id.localeCompare(left.id))
  const activeStatuses = ['queued', 'fetching', 'extracting', 'rewriting', 'analyzing', 'planning', 'writing', 'editing', 'validating', 'seo', 'thumbnail']

  records.forEach(record => {
    const sessionId = record.sessionId ?? record.parentId ?? record.id
    const bucket = sessions.get(sessionId) ?? []
    bucket.push(record)
    sessions.set(sessionId, bucket)
  })

  return [...sessions.entries()].map(([sessionId, values]) => {
    const sorted = sortVersions(values)
    const versions = sorted.map((version, index) => ({ ...version, versionNo: sorted.length - index }))
    const active = versions.find(item => activeStatuses.includes(item.runStatus))
    const current = versions.find(item => ['review', 'applied', 'rejected'].includes(item.status))
    const latest = versions[0]
    const failedAttempt = !active && ['failed', 'cancelled', 'expired'].includes(latest?.runStatus) ? latest : null
    const visible = current ?? latest

    return {
      ...visible,
      id: sessionId,
      sessionId,
      runId: current?.id ?? latest?.id,
      activeRunId: active?.id ?? null,
      currentVersionId: current?.id ?? null,
      status: active ? 'generating' : visible.status,
      error: failedAttempt?.error ?? visible.error,
      errorCode: failedAttempt?.errorCode ?? visible.errorCode,
      validationErrors: failedAttempt?.validationErrors ?? visible.validationErrors,
      latestAttemptId: latest?.id ?? null,
      latestAttemptStatus: latest?.runStatus ?? null,
      latestAttempt: latest ?? null,
      versions,
      versionCount: versions.length,
      hasHistory: versions.length > 1,
      updatedAt: latest.createdAt,
    }
  }).sort((left, right) => right.updatedAt.localeCompare(left.updatedAt) || right.id.localeCompare(left.id))
}

/**
 * =====================================================================
 * CHỨC NĂNG: Quản lý nguồn form và projection một dòng mỗi content session.
 * =====================================================================
 * INPUT: không có; page truyền summary/detail qua updateSession.
 * OUTPUT: source/list reactive cùng các action load/update/remove/reset.
 * SIDE EFFECT: GET các trang summary; không lưu Post hoặc provider key cục bộ.
 * EXCEPTION/TRANSACTION: lỗi đọc giữ listError; không mở transaction frontend.
 * =====================================================================
 */
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

    return groupSessionItems([...records.values()].filter(item => !removedIds.has(item.id)))
  })

  /**
   * =====================================================================
   * CHỨC NĂNG: Đọc toàn bộ trang summary còn hạn và giữ live update mới hơn.
   * =====================================================================
   * INPUT: thao tác mở trang hoặc tải lại từ UI.
   * OUTPUT: listedItems được chuẩn hóa rồi gom theo session trong computed.
   * SIDE EFFECT: GET tuần tự theo pagination; không gọi provider hoặc mutation.
   * EXCEPTION/TRANSACTION: lỗi HTTP giữ listError; sequence bỏ response cũ.
   * =====================================================================
   */
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

  /**
   * =====================================================================
   * CHỨC NĂNG: Upsert một run từ response create/poll vào projection live.
   * =====================================================================
   * INPUT: session DTO có job_id/session_id từ server.
   * OUTPUT: live run mới; computed cập nhật current/history cùng một dòng.
   * SIDE EFFECT: chỉ đổi reactive state; không ảnh hưởng form nguồn.
   * EXCEPTION/TRANSACTION: bỏ response của session đã xóa; không mở transaction.
   * =====================================================================
   */
  function updateSession(session) {
    const id = session.job_id
    const previous = liveItems.value[id] ?? listedItems.value.find(item => item.id === id)
    if (removedIds.has(id)) return

    liveItems.value = { ...liveItems.value, [id]: toListItem(session, previous) }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Ẩn run sau khi API xác nhận xóa candidate đã chọn.
   * =====================================================================
   * INPUT: UUID run đã xóa thành công.
   * OUTPUT: bỏ run đã xóa khỏi projection UI; run khác cùng session vẫn hiển thị.
   * SIDE EFFECT: đánh dấu removed UUID để chặn callback/GET cũ phục hồi.
   * EXCEPTION/TRANSACTION: không gọi API hoặc xóa database; không mở transaction.
   * =====================================================================
   */
  function removeItem(id) {
    removedIds.add(id)
    listedItems.value = listedItems.value.filter(item => item.id !== id)
    liveItems.value = Object.fromEntries(Object.entries(liveItems.value).filter(([key]) => key !== id))
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Reset nguồn form sau enqueue và giữ target/provider/model hiện tại.
   * =====================================================================
   * INPUT: content defaults từ catalog.
   * OUTPUT: source reactive mới để nhập nguồn tiếp theo.
   * SIDE EFFECT: không thêm item giả vào list hoặc gọi API.
   * EXCEPTION/TRANSACTION: không có.
   * =====================================================================
   */
  function resetSource(defaults = {}) {
    source.value = { ...createAiContentSource(defaults), targetType: source.value.targetType, provider: source.value.provider, model: source.value.model, imageModelId: source.value.imageModelId }
  }

  onScopeDispose(() => { loadVersion += 1 })

  return { source, items, listLoading, listError, loadItems, updateSession, removeItem, resetSource }
}
