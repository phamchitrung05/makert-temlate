<!--
  =====================================================================
  CHỨC NĂNG FILE: Dialog xem các phiên bản AI trong cùng một content session.
  =====================================================================
  Danh sách chính chỉ hiển thị bản hiện tại; dialog này hiển thị lịch sử,
  quality và metadata theo bố cục ba cột; đối chiếu nội dung nằm toàn chiều ngang.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - watcher item/finishLeave(): giữ snapshot trong transition đóng và tránh tải lặp.
  - loadVersionDetail(): tải source/draft/quality theo phiên bản đang chọn.
  - cancelVersionDetail(): hủy request detail khi đổi phiên bản hoặc rời dialog.
  - openVersion()/close(): chuyển thao tác về page cha.
  - versionNumber()/versionLabel()/versionColor()/dateLabel(): định dạng dữ liệu bounded.
  - objectValue()/textValue()/qualityScore()/qualityCriterionLabel(): chuẩn hóa dữ liệu API.
  - selectVersion(): đổi phiên bản và bắt đầu tải detail mới.
  - qualityRows/evidenceRows: chuẩn hóa quality và dẫn chứng từ API.
  - visible/versions/selectedVersion: điều phối trạng thái dialog và phiên bản.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : item đã gom theo session, gồm versions/history và currentVersionId.
  - OUTPUT: dialog lịch sử và emit open/close/afterLeave.
  - SIDE EFFECT: gọi GET detail/review/quality khi chọn phiên bản; không mutate props, không tạo run mới.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Quality criterion keys follow the Laravel API contract. */
import { computed, onBeforeUnmount, shallowRef, watch } from 'vue'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import AiContentComparison from './AiContentComparison.vue'
import { aiAgentService } from '@/services/aiAgent'
import { formatAiError } from '@/utils/aiErrors'

const props = defineProps({
  item: { type: Object, default: null },
})

const emit = defineEmits(['close', 'open', 'afterLeave'])

const visible = shallowRef(false)
const displayItem = shallowRef(null)
const selectedVersionId = shallowRef(null)
const selectedDetail = shallowRef(null)
const detailLoading = shallowRef(false)
const detailError = shallowRef('')
let detailAbortController = null
let detailRequestSequence = 0

const versions = computed(() => displayItem.value?.versions ?? [])
const selectedVersion = computed(() => versions.value.find(version => version.id === selectedVersionId.value) ?? versions.value[0] ?? null)
const selectedIsCurrent = computed(() => selectedVersion.value?.id === displayItem.value?.currentVersionId)
const pageTitle = computed(() => displayItem.value?.title || selectedVersion.value?.title || 'Content AI')
const selectedDetailForVersion = computed(() => selectedDetail.value?.id === selectedVersion.value?.id ? selectedDetail.value : null)

const selectedQuality = computed(() => selectedDetailForVersion.value?.quality_evaluation
  ?? selectedDetailForVersion.value?.qualityEvaluation
  ?? selectedVersion.value?.qualityEvaluation
  ?? selectedVersion.value?.quality_evaluation
  ?? null)

const selectedScore = computed(() => qualityScore(selectedQuality.value))
const scorePercent = computed(() => selectedScore.value === null ? 0 : Math.max(0, Math.min(100, selectedScore.value * 20)))

const selectedSource = computed(() => {
  const source = objectValue(selectedDetailForVersion.value?.sourceSnapshot
    ?? selectedDetailForVersion.value?.source
    ?? selectedVersion.value?.sourceSnapshot
    ?? selectedVersion.value?.source)

  if (!source) return null

  const contentHtml = source.content_html ?? source.content_text ?? source.text ?? ''

  return {
    ...source,
    content_html: contentHtml,
    available: source.available ?? Boolean(contentHtml),
  }
})

const selectedDraft = computed(() => objectValue(selectedDetailForVersion.value?.draft ?? selectedVersion.value?.draft))
const selectedTargetType = computed(() => selectedDetailForVersion.value?.target_type ?? selectedVersion.value?.target_type ?? displayItem.value?.targetType ?? 'post')

const selectedOutputGroups = computed(() => selectedDetailForVersion.value?.output_groups
  ?? selectedVersion.value?.output_groups
  ?? [])

const selectedTitle = computed(() => selectedDraft.value?.title || selectedVersion.value?.title || pageTitle.value || 'Chưa có tiêu đề')

const resultText = computed(() => textValue(selectedDraft.value?.content ?? selectedDraft.value?.content_html))
const sourceReferences = computed(() => Array.isArray(selectedQuality.value?.source_references) ? selectedQuality.value.source_references : [])

const evidenceRows = computed(() => {
  const evidence = Array.isArray(selectedQuality.value?.evidence) ? selectedQuality.value.evidence : []

  if (evidence.length) {
    return evidence.map((item, index) => ({
      id: item.source_block_id || `S${String(index + 1).padStart(3, '0')}`,
      text: item.excerpt || 'Chưa có trích đoạn.',
      reason: item.reason || '',
    }))
  }

  return sourceReferences.value.map(reference => ({
    id: reference,
    text: 'Block tham chiếu trong kết quả đánh giá chất lượng.',
    reason: '',
  }))
})

const qualityRows = computed(() => {
  const scores = selectedQuality.value?.scores
  if (!scores || typeof scores !== 'object' || Array.isArray(scores)) return []

  return Object.entries(scores).map(([key, rawScore]) => {
    const score = Number(rawScore)

    return {
      key,
      name: qualityCriterionLabel(key),
      score: Number.isFinite(score) ? score : null,
      percent: Number.isFinite(score) ? Math.max(0, Math.min(100, score * 20)) : 0,
      color: Number.isFinite(score) && score >= 4.5 ? 'success' : 'primary',
    }
  })
})

const selectedWordCount = computed(() => {
  const explicit = Number(selectedVersion.value?.wordCount ?? selectedDraft.value?.word_count)
  if (Number.isFinite(explicit) && explicit >= 0) return explicit.toLocaleString('vi-VN')

  const words = resultText.value.split(/\s+/).filter(Boolean).length

  return words ? words.toLocaleString('vi-VN') : null
})

const selectedModel = computed(() => selectedDetailForVersion.value?.model || selectedVersion.value?.model || 'Model mặc định')
const selectedDate = computed(() => dateLabel(selectedVersion.value?.createdAt ?? selectedVersion.value?.created_at))

const selectedExpiry = computed(() => selectedDetailForVersion.value?.expires_at
  ?? selectedDetailForVersion.value?.expiresAt
  ?? selectedVersion.value?.expiresAt
  ?? selectedVersion.value?.expires_at
  ?? displayItem.value?.expiresAt)

const headerStatus = computed(() => selectedIsCurrent.value ? 'Hiện tại' : versionLabel(selectedVersion.value))
const headerStatusColor = computed(() => selectedIsCurrent.value ? 'success' : versionColor(selectedVersion.value))

const canOpenSelected = computed(() => {
  const versionId = selectedVersion.value?.id ?? selectedVersion.value?.job_id

  return Boolean(versionId) && !['failed', 'cancelled', 'expired', 'generating'].includes(selectedVersion.value?.status)
})

const qualityStatusLabel = computed(() => {
  if (selectedScore.value !== null) return `${selectedScore.value.toFixed(2)}/5`

  return ({ pending: 'Đang chờ đánh giá', queued: 'Đang xếp hàng đánh giá', running: 'Đang đánh giá', failed: 'Đánh giá lỗi', expired: 'Điểm đã hết hạn' }[selectedQuality.value?.status] ?? 'Chưa có đánh giá')
})

const eligibilityLabel = computed(() => selectedQuality.value?.status === 'ready'
  ? (selectedQuality.value?.eligibility?.eligible ? 'Đủ điều kiện duyệt' : 'Chưa đủ điều kiện duyệt')
  : 'Chưa thể kết luận')

/** Input: giá trị có thể là object hoặc primitive. Output: object snapshot hoặc null. */
function objectValue(value) {
  return value && typeof value === 'object' && !Array.isArray(value) ? value : null
}

/** Input: nội dung có thể có HTML. Output: chuỗi text dùng cho vùng đọc. */
function textValue(value) {
  return typeof value === 'string' ? value.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() : ''
}

/** Input: item session. Output: khóa ổn định để nhận diện cùng một lịch sử. */
function itemIdentity(item) {
  return item?.sessionId ?? item?.id ?? null
}

/** Input: version summary. Output: chữ ký trạng thái ổn định để chỉ tải lại khi dữ liệu cần đồng bộ. */
function versionFingerprint(version) {
  if (!version) return ''

  return [
    version.id,
    version.status,
    version.createdAt ?? version.created_at,
    version.qualityEvaluation?.score_total ?? version.quality_evaluation?.score_total,
  ].join('|')
}

/**
 * =====================================================================
 * CHỨC NĂNG: Tải source, draft và quality đầy đủ cho phiên bản được chọn.
 * =====================================================================
 * INPUT: version record đã chuẩn hóa, có id và targetType từ API summary.
 * OUTPUT: snapshot detail cục bộ để vùng đối chiếu dùng dữ liệu thật.
 * SIDE EFFECT: GET review/quality cho Post hoặc GET session detail cho target khác.
 * EXCEPTION/TRANSACTION: lỗi được hiển thị bounded; request cũ bị bỏ qua khi đổi phiên bản.
 * =====================================================================
 */
async function loadVersionDetail(version) {
  const targetType = version?.targetType ?? version?.target_type

  detailAbortController?.abort()
  detailAbortController = null

  const requestSequence = ++detailRequestSequence

  if (!version?.id || !targetType) {
    selectedDetail.value = null
    detailLoading.value = false
    detailError.value = ''

    return
  }

  const controller = new AbortController()

  detailAbortController = controller
  selectedDetail.value = null
  detailLoading.value = true
  detailError.value = ''

  try {
    const reviewableTarget = ['post', 'social_post'].includes(targetType)

    const requests = [reviewableTarget
      ? aiAgentService.review(version.id, { signal: controller.signal })
      : aiAgentService.status(version.id, targetType)]

    if (targetType === 'post') {
      requests.push(aiAgentService.quality(version.id, { signal: controller.signal }))
    }

    const [detailResult, qualityResult] = await Promise.allSettled(requests)

    if (controller.signal.aborted || requestSequence !== detailRequestSequence) return
    if (detailResult.status === 'rejected') throw detailResult.reason

    const detail = objectValue(detailResult.value) ?? {}

    const quality = qualityResult?.status === 'fulfilled'
      ? objectValue(qualityResult.value?.quality_evaluation ?? qualityResult.value?.qualityEvaluation) ?? objectValue(qualityResult.value)
      : objectValue(detail.quality_evaluation ?? detail.qualityEvaluation)

    selectedDetail.value = {
      ...detail,
      id: version.id,
      quality_evaluation: quality ?? detail.quality_evaluation ?? detail.qualityEvaluation ?? version.qualityEvaluation ?? version.quality_evaluation,
    }

    if (qualityResult?.status === 'rejected' && !detail.quality_evaluation && !detail.qualityEvaluation) {
      detailError.value = formatAiError(qualityResult.reason, 'Không tải được điểm chất lượng của phiên bản.')
    }
  }
  catch (error) {
    if (controller.signal.aborted || requestSequence !== detailRequestSequence) return

    selectedDetail.value = null
    detailError.value = formatAiError(error, 'Không tải được toàn bộ nội dung của phiên bản.')
  }
  finally {
    if (requestSequence === detailRequestSequence) {
      detailLoading.value = false
      detailAbortController = null
    }
  }
}

/** Input: không có. Output: hủy request detail hiện tại khi rời dialog/component. */
function cancelVersionDetail() {
  detailRequestSequence += 1
  detailAbortController?.abort()
  detailAbortController = null
  detailLoading.value = false
}

/**
 * =====================================================================
 * CHỨC NĂNG: Giữ dữ liệu lịch sử ổn định trong transition đóng dialog.
 * =====================================================================
 * INPUT: item session từ page, có thể chuyển về null ngay sau click đóng.
 * OUTPUT: visible tắt trước; displayItem chỉ dọn sau after-leave.
 * SIDE EFFECT: cập nhật state cục bộ và gọi tải detail; không mutate props.
 * =====================================================================
 */
watch(() => props.item, value => {
  if (value) {
    const previousItem = displayItem.value
    const sessionChanged = itemIdentity(previousItem) !== itemIdentity(value)
    const nextCurrentVersionId = value.currentVersionId ?? value.versions?.[0]?.id ?? null
    const selectedStillExists = Boolean(value.versions?.some(version => version.id === selectedVersionId.value))
    const selectionChanged = sessionChanged || !selectedStillExists
    const previousSelectedVersion = previousItem?.versions?.find(version => version.id === selectedVersionId.value)

    const nextSelectedVersion = value.versions?.find(version => version.id === selectedVersionId.value)
      ?? value.versions?.find(version => version.id === nextCurrentVersionId)
      ?? value.versions?.[0]

    displayItem.value = value
    visible.value = true

    if (selectionChanged) {
      selectedVersionId.value = nextSelectedVersion?.id ?? nextCurrentVersionId
    }

    const selectedVersionChanged = versionFingerprint(previousSelectedVersion) !== versionFingerprint(nextSelectedVersion)

    if (sessionChanged || selectionChanged || selectedVersionChanged) {
      void loadVersionDetail(nextSelectedVersion)
    }

    return
  }

  visible.value = false
  cancelVersionDetail()
}, { immediate: true })

/** Input: phiên bản và index trong list. Output: số phiên bản bounded. */
function versionNumber(version, index) {
  return version?.versionNo ?? version?.generationNo ?? versions.value.length - index
}

/**
 * =====================================================================
 * CHỨC NĂNG: Chọn nhãn trạng thái tiếng Việt cho một phiên bản.
 * =====================================================================
 * INPUT: version record đã chuẩn hóa.
 * OUTPUT: nhãn allowlist; trạng thái lạ dùng Đang xử lý.
 * SIDE EFFECT: hàm thuần, không gọi API hoặc mutate props.
 * =====================================================================
 */
function versionLabel(version) {
  return {
    review: 'Chờ duyệt',
    applied: 'Đã áp dụng',
    rejected: 'Đã từ chối',
    generating: 'Đang tạo',
    failed: 'Tạo thất bại',
    cancelled: 'Đã hủy',
    expired: 'Hết hạn',
  }[version?.status] ?? 'Đang xử lý'
}

/** Input: version record. Output: màu Vuetify từ allowlist. */
function versionColor(version) {
  return {
    review: 'warning', applied: 'success', rejected: 'error', failed: 'error',
    generating: 'primary', cancelled: 'secondary', expired: 'secondary',
  }[version?.status] ?? 'secondary'
}

/** Input: timestamp ISO tùy chọn. Output: ngày giờ local tiếng Việt. */
function dateLabel(value) {
  if (!value) return 'Chưa có thời điểm'
  const date = new Date(value)

  return Number.isNaN(date.getTime()) ? 'Chưa có thời điểm' : date.toLocaleString('vi-VN')
}

/** Input: version hoặc quality record. Output: điểm chất lượng hữu hạn hoặc null. */
function qualityScore(version) {
  const raw = version?.score_total ?? version?.qualityEvaluation?.score_total ?? version?.quality_evaluation?.score_total
  if (raw === null || raw === undefined || raw === '') return null

  return Number.isFinite(Number(raw)) ? Number(raw) : null
}

/** Input: mã tiêu chí quality. Output: nhãn tiếng Việt theo allowlist. */
function qualityCriterionLabel(criterion) {
  return {
    accuracy: 'Độ chính xác',
    source_grounding: 'Bám sát nguồn',
    clarity: 'Độ rõ ràng',
    structure: 'Cấu trúc',
    style: 'Văn phong',
  }[criterion] ?? 'Tiêu chí chất lượng'
}

/**
 * =====================================================================
 * CHỨC NĂNG: Kết thúc transition đóng và dọn snapshot lịch sử.
 * =====================================================================
 * INPUT: lifecycle after-leave của VDialog.
 * OUTPUT: displayItem rỗng nếu page chưa mở lại lịch sử.
 * SIDE EFFECT: hủy request detail và emit afterLeave để page đồng bộ state.
 * =====================================================================
 */
function finishLeave() {
  if (!props.item) {
    displayItem.value = null
    selectedVersionId.value = null
    selectedDetail.value = null
  }
  cancelVersionDetail()
  emit('afterLeave')
}

/** Input: version record từ list lịch sử. Output: event mở đúng run UUID. */
function openVersion(version = selectedVersion.value) {
  const versionId = version?.id ?? version?.job_id
  const targetType = version?.targetType ?? version?.target_type ?? displayItem.value?.targetType ?? 'post'

  if (!versionId || ['failed', 'cancelled', 'expired', 'generating'].includes(version?.status)) return

  emit('open', { ...version, id: versionId, targetType })
}

/** Input: phiên bản được chọn. Output: cập nhật lựa chọn và tải detail tương ứng. */
function selectVersion(version) {
  selectedVersionId.value = version.id
  void loadVersionDetail(version)
}

/** Input: thao tác đóng từ UI. Output: event close về page cha. */
function close() {
  emit('close')
}

onBeforeUnmount(cancelVersionDetail)
</script>

<template>
  <VDialog
    :model-value="visible"
    class="ai-content-version-history-dialog"
    width="calc(100vw - 48px)"
    height="min(1400px, calc(100dvh - 24px))"
    scrollable
    @update:model-value="!$event && close()"
    @after-leave="finishLeave"
  >
    <div
      id="view-moi"
      class="ai-content-version-history-dialog__root"
    >
      <AppDialogLayout
        :subtitle="`${pageTitle} · ${versions.length} phiên bản`"
        class="ai-content-version-history-dialog__layout"
        close-label="Đóng lịch sử tạo content"
        @close="close"
      >
        <template #header>
          <div class="ai-content-version-history-dialog__header px-6 py-4">
            <div class="d-flex align-center min-w-0">
              <VAvatar
                color="primary"
                rounded="md"
                size="44"
                variant="tonal"
                class="me-3 flex-shrink-0"
              >
                <VIcon
                  icon="tabler-file-text"
                  size="24"
                />
              </VAvatar>
              <div class="min-w-0">
                <div class="text-subtitle-1 font-weight-bold text-high-emphasis">
                  Lịch sử tạo content
                </div>
                <div class="text-caption text-medium-emphasis text-truncate">
                  {{ pageTitle }} · {{ versions.length }} phiên bản
                </div>
              </div>
            </div>
            <div class="d-flex align-center flex-wrap justify-end gap-3">
              <VChip
                :color="headerStatusColor"
                size="small"
                variant="tonal"
                prepend-icon="tabler-clock"
              >
                {{ headerStatus }}
              </VChip>
              <span
                v-if="selectedExpiry"
                class="d-flex align-center gap-1 text-caption text-medium-emphasis"
              >
                <VIcon
                  icon="tabler-clock-exclamation"
                  size="16"
                />
                Hạn đến {{ dateLabel(selectedExpiry) }}
              </span>
            </div>
          </div>
        </template>

        <VCardText
          class="ai-content-version-history-dialog__body pa-4"
          :aria-busy="detailLoading"
        >
          <VProgressLinear
            v-if="detailLoading"
            indeterminate
            color="primary"
            height="3"
            class="mb-4"
          />
          <VAlert
            v-if="detailError"
            type="warning"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            <div class="d-flex align-center justify-space-between flex-wrap gap-2">
              <span>{{ detailError }}</span>
              <VBtn
                v-if="selectedVersion?.id"
                size="small"
                variant="text"
                color="warning"
                :loading="detailLoading"
                @click="loadVersionDetail(selectedVersion)"
              >
                Tải lại
              </VBtn>
            </div>
          </VAlert>
          <VRow
            class="ai-version-history__columns"
            dense
          >
            <VCol
              cols="12"
              md="3"
              xl="3"
            >
              <VCard
                variant="outlined"
                rounded="lg"
                class="ai-version-history__panel"
              >
                <VCardItem title="Danh sách phiên bản">
                  <template #append>
                    <VChip
                      size="x-small"
                      color="primary"
                      variant="tonal"
                    >
                      {{ versions.length }}
                    </VChip>
                  </template>
                </VCardItem>
                <VCardText class="pt-0">
                  <div class="ai-version-history__version-list">
                    <VCard
                      v-for="(version, index) in versions"
                      :key="version.id"
                      variant="outlined"
                      rounded="md"
                      class="ai-version-history__version-card mb-2"
                      :class="{ 'ai-version-history__version-card--active': selectedVersion?.id === version.id }"
                      :aria-current="selectedVersion?.id === version.id ? 'true' : undefined"
                      role="button"
                      tabindex="0"
                      @click="selectVersion(version)"
                      @keydown.enter="selectVersion(version)"
                    >
                      <VCardText class="pa-3">
                        <div class="d-flex justify-space-between align-start gap-2 mb-1">
                          <div class="d-flex align-center min-w-0">
                            <VIcon
                              :icon="selectedVersion?.id === version.id ? 'tabler-circle-check' : 'tabler-file-text'"
                              :color="selectedVersion?.id === version.id ? 'primary' : versionColor(version)"
                              size="18"
                              class="me-2 flex-shrink-0"
                            />
                            <span class="text-body-2 font-weight-medium text-truncate">
                              Phiên bản {{ versionNumber(version, index) }}
                            </span>
                          </div>
                          <VChip
                            v-if="qualityScore(version) !== null"
                            size="x-small"
                            color="info"
                            variant="tonal"
                            class="flex-shrink-0"
                          >
                            Điểm {{ qualityScore(version).toFixed(1) }}/5
                          </VChip>
                        </div>
                        <div class="text-caption text-medium-emphasis text-truncate mb-2">
                          {{ version.title || pageTitle || 'Chưa có tiêu đề' }}
                        </div>
                        <div class="d-flex justify-space-between align-center gap-2">
                          <span class="text-caption text-disabled text-truncate">
                            {{ dateLabel(version.createdAt ?? version.created_at) }}
                          </span>
                          <VChip
                            size="x-small"
                            :color="version.id === displayItem?.currentVersionId ? 'success' : versionColor(version)"
                            variant="tonal"
                            class="flex-shrink-0"
                          >
                            {{ version.id === displayItem?.currentVersionId ? 'Hiện tại' : versionLabel(version) }}
                          </VChip>
                        </div>
                      </VCardText>
                    </VCard>
                  </div>
                </VCardText>
              </VCard>
            </VCol>

            <VCol
              cols="12"
              md="6"
              xl="6"
            >
              <div class="d-flex flex-column gap-4">
                <VCard
                  variant="outlined"
                  rounded="lg"
                >
                  <VCardText>
                    <div class="d-flex align-center flex-wrap gap-2 mb-2">
                      <VChip
                        size="x-small"
                        :color="headerStatusColor"
                        variant="tonal"
                        class="font-weight-bold"
                      >
                        {{ headerStatus }}
                      </VChip>
                      <span class="text-body-2 font-weight-bold">Phiên bản {{ versionNumber(selectedVersion, 0) }}</span>
                      <VChip
                        size="x-small"
                        color="secondary"
                        variant="outlined"
                      >
                        {{ selectedModel }}
                      </VChip>
                      <span class="text-caption text-medium-emphasis">• {{ selectedDate }}</span>
                      <span
                        v-if="selectedWordCount"
                        class="text-caption text-medium-emphasis"
                      >
                        • ~ {{ selectedWordCount }} từ
                      </span>
                    </div>
                    <h2 class="text-subtitle-1 font-weight-bold text-high-emphasis mb-3">
                      {{ selectedTitle }}
                    </h2>
                    <div
                      v-if="qualityRows.length"
                      class="d-flex flex-wrap gap-2"
                    >
                      <VChip
                        v-for="criterion in qualityRows"
                        :key="criterion.key"
                        size="x-small"
                        variant="tonal"
                        color="info"
                        prepend-icon="tabler-star"
                      >
                        {{ criterion.name }} {{ criterion.score === null ? '–' : `${criterion.score.toFixed(1)}/5` }}
                      </VChip>
                    </div>
                    <span
                      v-else
                      class="text-caption text-medium-emphasis"
                    >
                      Chưa có điểm chi tiết cho phiên bản này.
                    </span>
                  </VCardText>
                </VCard>

                <VCard
                  variant="outlined"
                  rounded="lg"
                >
                  <VCardItem>
                    <template #prepend>
                      <VIcon
                        icon="tabler-list-details"
                        color="primary"
                        size="20"
                        class="me-2"
                      />
                    </template>
                    <VCardTitle class="text-subtitle-2">
                      Dẫn chứng nguồn ({{ evidenceRows.length }})
                    </VCardTitle>
                  </VCardItem>
                  <VCardText class="pt-0">
                    <VAlert
                      v-if="!evidenceRows.length"
                      type="info"
                      variant="tonal"
                      density="compact"
                    >
                      Chưa có dẫn chứng quality cho phiên bản này.
                    </VAlert>
                    <div
                      v-if="evidenceRows.length"
                      class="ai-version-history__evidence-list"
                    >
                      <div
                        v-for="source in evidenceRows"
                        :key="`${source.id}-${source.text}`"
                        class="ai-version-history__evidence-row"
                      >
                        <VChip
                          size="x-small"
                          color="primary"
                          variant="flat"
                          class="flex-shrink-0"
                        >
                          {{ source.id }}
                        </VChip>
                        <div class="min-w-0">
                          <div class="text-caption text-high-emphasis">
                            “{{ source.text }}”
                          </div>
                          <div
                            v-if="source.reason"
                            class="text-caption text-medium-emphasis mt-1"
                          >
                            {{ source.reason }}
                          </div>
                        </div>
                      </div>
                    </div>
                  </VCardText>
                </VCard>
              </div>
            </VCol>

            <VCol
              cols="12"
              md="3"
              xl="3"
            >
              <div class="d-flex flex-column gap-4">
                <VCard
                  variant="outlined"
                  rounded="lg"
                >
                  <VCardText>
                    <div class="d-flex align-center mb-3">
                      <VProgressCircular
                        :model-value="scorePercent"
                        :size="58"
                        :width="6"
                        :color="selectedScore === null ? 'secondary' : 'primary'"
                        class="me-3"
                      >
                        <span class="text-caption font-weight-bold">
                          {{ selectedScore === null ? '–' : selectedScore.toFixed(1) }}
                        </span>
                      </VProgressCircular>
                      <div>
                        <div class="text-caption text-medium-emphasis">
                          Đánh giá chất lượng
                        </div>
                        <div class="text-subtitle-1 font-weight-bold">
                          {{ qualityStatusLabel }}
                        </div>
                        <div class="text-caption text-disabled">
                          Rubric {{ selectedQuality?.rubric_version || 'chưa xác định' }}
                        </div>
                      </div>
                    </div>
                    <div
                      v-if="qualityRows.length"
                      class="d-flex flex-column gap-2"
                    >
                      <div
                        v-for="criterion in qualityRows"
                        :key="criterion.key"
                      >
                        <div class="d-flex justify-space-between align-center text-caption mb-1">
                          <span>{{ criterion.name }}</span>
                          <span class="font-weight-medium">{{ criterion.score === null ? '–' : `${criterion.score.toFixed(1)}/5` }}</span>
                        </div>
                        <VProgressLinear
                          :model-value="criterion.percent"
                          :color="criterion.color"
                          height="5"
                          rounded
                        />
                      </div>
                    </div>
                    <VAlert
                      v-else
                      type="info"
                      variant="tonal"
                      density="compact"
                    >
                      {{ eligibilityLabel }}
                    </VAlert>
                  </VCardText>
                </VCard>

                <VCard
                  variant="outlined"
                  rounded="lg"
                >
                  <VCardItem title="Thông tin tạo nội dung" />
                  <VCardText class="pt-0">
                    <dl class="ai-version-history__metadata">
                      <div>
                        <dt>
                          <VIcon
                            icon="tabler-robot"
                            size="15"
                          /> Model
                        </dt>
                        <dd>{{ selectedModel }}</dd>
                      </div>
                      <div>
                        <dt>
                          <VIcon
                            icon="tabler-clock"
                            size="15"
                          /> Thời gian tạo
                        </dt>
                        <dd>{{ selectedDate }}</dd>
                      </div>
                      <div>
                        <dt>
                          <VIcon
                            icon="tabler-text-size"
                            size="15"
                          /> Số từ
                        </dt>
                        <dd>{{ selectedWordCount ? `~ ${selectedWordCount} từ` : 'Chưa có' }}</dd>
                      </div>
                      <div>
                        <dt>
                          <VIcon
                            icon="tabler-language"
                            size="15"
                          /> Ngôn ngữ
                        </dt>
                        <dd>Tiếng Việt</dd>
                      </div>
                      <div>
                        <dt>
                          <VIcon
                            icon="tabler-code"
                            size="15"
                          /> Prompt
                        </dt>
                        <dd>{{ selectedVersion?.promptKey || 'Theo cấu hình hệ thống' }}</dd>
                      </div>
                      <div>
                        <dt>
                          <VIcon
                            icon="tabler-bookmarks"
                            size="15"
                          /> Block tham chiếu
                        </dt>
                        <dd>{{ sourceReferences.length ? sourceReferences.join(', ') : 'Chưa có' }}</dd>
                      </div>
                    </dl>
                  </VCardText>
                </VCard>
              </div>
            </VCol>
          </VRow>

          <AiContentComparison
            :source="selectedSource"
            :draft="selectedDraft"
            :loading="detailLoading"
            :active="Boolean(selectedVersion)"
            :show-seo="selectedTargetType !== 'social_post' && (!selectedOutputGroups.length || selectedOutputGroups.includes('seo'))"
          />
        </VCardText>

        <template #footer>
          <VCardActions class="justify-space-between flex-wrap gap-3 px-6 py-3">
            <VBtn
              variant="tonal"
              color="secondary"
              @click="close"
            >
              Đóng
            </VBtn>
            <VBtn
              color="primary"
              variant="flat"
              prepend-icon="tabler-eye"
              :disabled="!canOpenSelected"
              @click="openVersion(selectedVersion)"
            >
              Xem phiên bản
            </VBtn>
          </VCardActions>
        </template>
      </AppDialogLayout>
    </div>
  </VDialog>
</template>

<style scoped>
.ai-content-version-history-dialog__root {
  block-size: 100%;
  min-inline-size: 0;
}

.ai-content-version-history-dialog__layout {
  block-size: 100%;
}

.ai-content-version-history-dialog__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  min-inline-size: 0;
}

.ai-content-version-history-dialog__body {
  background: rgb(var(--v-theme-background));
}

.ai-version-history__panel {
  display: flex;
  flex-direction: column;
  block-size: 100%;
  min-block-size: 100%;
}

.ai-version-history__columns > :deep(.v-col) {
  display: flex;
}

.ai-version-history__panel > :deep(.v-card-text) {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  min-block-size: 0;
  overflow: hidden;
}

.ai-version-history__version-list {
  flex: 1 1 auto;
  min-block-size: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding-inline-end: 4px;
}

.ai-version-history__evidence-list {
  max-block-size: 320px;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding-inline-end: 4px;
}

.ai-version-history__version-card {
  cursor: pointer;
  border-color: rgba(var(--v-border-color), var(--v-border-opacity));
  transition: border-color 0.2s ease, background-color 0.2s ease;
}

.ai-version-history__version-card:hover,
.ai-version-history__version-card--active {
  border-color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.06);
}

.ai-version-history__evidence-row {
  display: flex;
  align-items: flex-start;
  border: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
  gap: 10px;
  margin-block-end: 8px;
  padding: 10px;
}

.ai-version-history__evidence-row:last-child {
  margin-block-end: 0;
}

.ai-version-history__metadata {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin: 0;
}

.ai-version-history__metadata > div {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.ai-version-history__metadata dt,
.ai-version-history__metadata dd {
  display: flex;
  align-items: center;
  gap: 5px;
  margin: 0;
}

.ai-version-history__metadata dt {
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  flex: 0 0 auto;
}

.ai-version-history__metadata dd {
  max-inline-size: 58%;
  color: rgb(var(--v-theme-on-surface));
  overflow-wrap: anywhere;
  text-align: end;
}

@media (max-width: 959.98px) {
  .ai-content-version-history-dialog__header {
    align-items: flex-start;
    flex-direction: column;
  }
}

@media (max-width: 599.98px) {
  .ai-content-version-history-dialog__header {
    padding-inline: 16px !important;
  }

  .ai-version-history__metadata > div {
    align-items: flex-start;
    flex-direction: column;
    gap: 2px;
  }

  .ai-version-history__metadata dd {
    max-inline-size: 100%;
    text-align: start;
  }
}
</style>
