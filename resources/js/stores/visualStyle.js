/**
 * =====================================================================
 * CHỨC NĂNG FILE: Pinia store cho Visual Style Engine (Style DNA + Shots).
 * =====================================================================
 * Store giữ list/detail style, meta DNA (enums/labels), shot recipes và
 * generation run. Mọi request đi qua visualStylesService — page/dialog chỉ
 * đọc state readonly và gọi action; không gọi $api trực tiếp. Revision
 * counter của useCrudCore bỏ response cũ khi fetch nối tiếp.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - fetchStyles/fetchStyle/createStyle/updateStyle/removeStyle: alias CRUD
 *   chuẩn từ createCrudStore (fetchItems/fetchItem/createItem/updateItem/removeItem).
 * - loadDnaMeta(): tải enums/labels/defaults của Style DNA (cache 1 lần).
 * - analyzeStyle(payload): queue phân tích ảnh → style draft (multipart/JSON).
 * - updateDna(id, payload): lưu DNA chỉnh sửa (optimistic lock expected_version).
 * - activateStyle/archiveStyle/duplicateStyle: lifecycle style.
 * - fetchShots/createShot/updateShot/deleteShot/reorderShots: shot recipes.
 * - generate(payload)/pollGeneration(importId): queue generation + poll run.
 * - stopGenerationPolling/resetGeneration: dọn timer và run khi rời trang.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : query/id/payload từ pages và dialogs Visual Styles.
 * - OUTPUT: state readonly (styles/shots/dnaMeta/generation) + action Promise.
 * - SIDE EFFECT: gọi Admin API qua visualStylesService; polling generation
 *   tự dừng khi run đạt trạng thái terminal hoặc khi resetGeneration() chạy.
 * =====================================================================
 */
/* eslint-disable camelcase -- Laravel Visual Style API fields keep snake_case. */
import { readonly, shallowRef } from 'vue'
import { visualStylesService } from '@/services/visualStyles'
import { createCrudStore } from '@/stores/createCrudStore'

const TERMINAL = ['ready', 'failed', 'cancelled', 'expired']
const POLL_INTERVAL = 4000

export const useVisualStyleStore = createCrudStore('visualStyle', visualStylesService, {
  listDefaults: { per_page: 12 },

  extraActions(service, core) {
    const dnaMeta = shallowRef(null)
    const dnaMetaLoading = shallowRef(false)
    const shots = shallowRef([])
    const generation = shallowRef(null)
    const generationError = shallowRef(null)
    const isGenerating = shallowRef(false)
    let pollTimer = null
    let pollRequestId = 0

    /** Output: meta DNA (enums/labels); chỉ fetch một lần. */
    const loadDnaMeta = async () => {
      if (dnaMeta.value) return dnaMeta.value
      if (dnaMetaLoading.value) return null
      dnaMetaLoading.value = true
      try {
        dnaMeta.value = await service.dnaMeta()

        return dnaMeta.value
      }
      catch {
        return null
      }
      finally {
        dnaMetaLoading.value = false
      }
    }

    /** Input: payload analyze (multipart hoặc media_asset_id). Output: style queued. */
    const analyzeStyle = payload => core.actions.runMutation(() => service.analyze(payload))

    /** Input: id + `{ dna, expected_version }`. Output: style version++. */
    const updateDna = (id, payload) => core.actions.runMutation(() => service.updateDna(id, payload))

    const activateStyle = id => core.actions.runMutation(() => service.activate(id))
    const archiveStyle = id => core.actions.runMutation(() => service.archive(id))
    const duplicateStyle = id => core.actions.runMutation(() => service.duplicate(id))

    /** Input: style id. Output: danh sách shots theo sort_order. */
    const fetchShots = async styleId => {
      if (!styleId) {
        shots.value = []

        return []
      }
      const result = await service.listShots(styleId)

      shots.value = Array.isArray(result) ? result : result?.items ?? []

      return shots.value
    }

    /** Input: mảng shots từ detail.generate page. Output: đồng bộ state shots. */
    const setShots = list => {
      shots.value = Array.isArray(list) ? list : []
    }

    const createShot = (styleId, payload) => core.actions.runMutation(async () => {
      const response = await service.createShot(styleId, payload)

      shots.value = [...shots.value, response?.data ?? response]

      return response
    }, { syncCurrent: false, syncItems: false })

    const updateShot = (styleId, shotId, payload) => core.actions.runMutation(async () => {
      const response = await service.updateShot(styleId, shotId, payload)
      const updated = response?.data ?? response

      shots.value = shots.value.map(shot => shot.id === shotId ? updated : shot)

      return response
    }, { syncCurrent: false, syncItems: false })

    const deleteShot = (styleId, shotId) => core.actions.runMutation(async () => {
      const response = await service.deleteShot(styleId, shotId)

      shots.value = shots.value.filter(shot => shot.id !== shotId)

      return response
    }, { syncCurrent: false, syncItems: false })

    const reorderShots = (styleId, orderedIds) => core.actions.runMutation(async () => {
      const response = await service.reorderShots(styleId, orderedIds)

      shots.value = Array.isArray(response?.data) ? response.data : shots.value

      return response
    }, { syncCurrent: false, syncItems: false })

    /** Input: generate payload. Output: queued run + bắt đầu polling. */
    const generate = async payload => {
      isGenerating.value = true
      generationError.value = null
      try {
        const result = await service.generate(payload)

        generation.value = result?.id ? { id: result.id, status: result.status ?? 'queued', outputs: result.outputs } : null
        if (generation.value?.id && !TERMINAL.includes(generation.value.status))
          schedulePoll()

        return result
      }
      catch (requestError) {
        generationError.value = requestError
        throw requestError
      }
      finally {
        isGenerating.value = false
      }
    }

    const pollGeneration = async () => {
      const run = generation.value
      if (!run?.id) return
      const requestId = ++pollRequestId
      try {
        const data = await service.getGeneration(run.id)
        if (requestId !== pollRequestId) return
        generation.value = { ...run, ...data }
        if (TERMINAL.includes(data?.status))
          clearTimeout(pollTimer)
        else
          schedulePoll()
      }
      catch (requestError) {
        if (requestId === pollRequestId) {
          clearTimeout(pollTimer)
          generationError.value = requestError
        }
      }
    }

    function schedulePoll() {
      clearTimeout(pollTimer)
      pollTimer = setTimeout(pollGeneration, POLL_INTERVAL)
    }

    /** Output: dừng polling và bỏ run hiện tại. */
    const resetGeneration = () => {
      pollRequestId++
      clearTimeout(pollTimer)
      generation.value = null
      generationError.value = null
      isGenerating.value = false
    }

    return {
      // State riêng của domain (không có trong CRUD core)
      dnaMeta: readonly(dnaMeta),
      dnaMetaLoading: readonly(dnaMetaLoading),
      shots: readonly(shots),
      generation: readonly(generation),
      generationError: readonly(generationError),
      isGenerating: readonly(isGenerating),

      // Actions domain
      loadDnaMeta,
      analyzeStyle,
      updateDna,
      activateStyle,
      archiveStyle,
      duplicateStyle,
      fetchShots,
      setShots,
      createShot,
      updateShot,
      deleteShot,
      reorderShots,
      generate,
      resetGeneration,

      // Alias rõ nghĩa cho page (giữ tương thích convention store hiện tại)
      fetchStyles: core.actions.fetchItems,
      fetchStyle: core.actions.fetchItem,
      createStyle: core.actions.createItem,
      updateStyle: core.actions.updateItem,
      removeStyle: core.actions.removeItem,
    }
  },
})
