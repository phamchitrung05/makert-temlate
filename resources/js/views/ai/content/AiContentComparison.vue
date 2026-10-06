<!--
  =====================================================================
  CHỨC NĂNG FILE: So sánh nguồn đã lưu với bản nháp AI hiện tại.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: syncPanels(); sourceText/resultText/blocks/panels
  (computed), mode models và chế độ chỉ xem nội dung sạch.
  INPUT/OUTPUT CỦA CLASS (tổng thể): source/draft/loading/active -> hai panel,
  HTML gốc escaped, preview allowlist, metadata và dấu khác biệt theo văn bản.
  SIDE EFFECT: đồng bộ cuộn qua refs DOM; không gọi API hoặc sửa bản nháp.
  =====================================================================
-->
<script setup>
import { computed, shallowRef, useTemplateRef } from 'vue'
import AiContentComparisonPanel from './AiContentComparisonPanel.vue'
import { comparisonBlocks, previewOfHtml, textOfHtml } from '@/utils/aiContentComparison'

const props = defineProps({
  source: { type: Object, default: null },
  draft: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  active: { type: Boolean, default: true },
})

const syncScroll = shallowRef(true)
const highlightDiff = shallowRef(true)
const cleanOnly = shallowRef(false)
const sourceMode = shallowRef('text')
const resultMode = shallowRef('preview')
const sourcePanelRef = useTemplateRef('sourcePanelElement')
const resultPanelRef = useTemplateRef('resultPanelElement')
const sourceView = computed(() => cleanOnly.value ? 'text' : sourceMode.value)
const resultView = computed(() => cleanOnly.value ? 'text' : resultMode.value)
const sourceHtml = computed(() => props.source?.content_html || '')
const resultHtml = computed(() => props.draft?.content_html ?? props.draft?.content ?? '')
const sourceText = computed(() => props.active ? textOfHtml(sourceHtml.value) : '')
const resultText = computed(() => props.active ? textOfHtml(resultHtml.value) : '')
const canCompare = computed(() => props.active && !props.loading && props.source?.available && Boolean(resultText.value))
const sourceBlocks = computed(() => comparisonBlocks(sourceText.value, resultText.value))
const resultBlocks = computed(() => comparisonBlocks(resultText.value, sourceText.value))
const disabled = computed(() => props.loading || !props.active)

const sourcePanel = computed(() => ({
  title: 'Nguồn đã lưu', icon: 'tabler-file-description', badge: 'Nguồn gốc',
  description: 'Bản chụp nội dung đầu vào lúc tạo bài.',
  articleTitle: props.active ? props.source?.title : '',
  modes: [
    { title: 'HTML gốc', value: 'html', icon: 'tabler-code' },
    { title: 'Nội dung sạch', value: 'text', icon: 'tabler-align-left' },
  ],
  ariaLabel: 'Văn bản nguồn', loading: disabled.value, loadingMessage: 'Đang tải nguồn đã lưu…',
  missing: !props.source?.available,
  missingMessage: 'Bản này chưa có nguồn để đối chiếu. Hệ thống không tự đọc lại URL.',
  html: sourceHtml.value, blocks: sourceBlocks.value, diffKind: 'removed',
  metadata: [
    { label: 'Tiêu đề nguồn', icon: 'tabler-heading', value: props.source?.title },
    { label: 'Số từ trong nguồn', icon: 'tabler-text-size', value: disabled.value ? 'Đang tải…' : props.source?.available ? sourceText.value.split(/\s+/).filter(Boolean).length.toLocaleString('vi-VN') + ' từ' : 'Chưa có' },
  ],
}))

const resultPanel = computed(() => ({
  title: 'Nội dung AI sau biên tập', icon: 'tabler-sparkles', badge: 'Bản đã lưu',
  description: 'Bản nháp hiện tại, gồm những chỉnh sửa đã lưu.',
  articleTitle: props.active ? props.draft?.title : '',
  modes: [
    { title: 'Xem trước bài viết', value: 'preview', icon: 'tabler-eye' },
    { title: 'Văn bản AI', value: 'text', icon: 'tabler-align-left' },
  ],
  ariaLabel: 'Văn bản AI', loading: disabled.value, loadingMessage: 'Đang tải nội dung AI…',
  missing: false, html: resultHtml.value, blocks: resultBlocks.value, diffKind: 'added',
  previewHtml: disabled.value || resultView.value !== 'preview' ? '' : previewOfHtml(resultHtml.value, highlightDiff.value && canCompare.value ? sourceText.value : null),
  metadata: [
    { label: 'Tóm tắt', icon: 'tabler-text-caption', value: props.draft?.excerpt },
    { label: 'Tiêu đề SEO', icon: 'tabler-heading', value: props.draft?.seo_title },
    { label: 'Mô tả SEO', icon: 'tabler-file-description', value: props.draft?.seo_description },
    { label: 'Từ khóa chính', icon: 'tabler-key', value: props.draft?.focus_keyword },
  ],
}))

/** Input: tỷ lệ cuộn và phía đang thao tác. Output: đồng bộ phía còn lại khi được bật. */
function syncPanels(ratio, side) {
  if (!syncScroll.value || !canCompare.value) return
  const panel = side === 'source' ? resultPanelRef.value : sourcePanelRef.value

  panel?.scrollToRatio(ratio)
}
</script>

<template>
  <div class="ai-content-comparison">
    <div class="ai-content-comparison__toolbar">
      <div class="d-flex align-center gap-2">
        <VIcon
          icon="tabler-columns-2"
          size="20"
          color="primary"
        />
        <span class="text-subtitle-2">Đối chiếu nội dung</span>
      </div>
      <div class="d-flex flex-wrap align-center gap-4">
        <VSwitch
          v-model="syncScroll"
          label="Đồng bộ cuộn"
          color="primary"
          density="compact"
          hide-details
          inset
          :disabled="!canCompare"
        />
        <VSwitch
          v-model="highlightDiff"
          label="Tô khác biệt"
          color="primary"
          density="compact"
          hide-details
          inset
          :disabled="!canCompare"
        />
        <VSwitch
          v-model="cleanOnly"
          label="Chỉ xem nội dung sạch"
          color="primary"
          density="compact"
          hide-details
          inset
          :disabled="disabled"
        />
      </div>
    </div>
    <div
      v-if="highlightDiff && canCompare"
      class="d-flex flex-wrap gap-4 text-caption text-medium-emphasis mb-4"
    >
      <span class="d-flex align-center gap-1"><VIcon
        icon="tabler-square-filled"
        size="12"
        color="error"
      /> Đoạn nguồn khác bài AI</span>
      <span class="d-flex align-center gap-1"><VIcon
        icon="tabler-square-filled"
        size="12"
        color="success"
      /> Đoạn AI khác nguồn</span>
    </div>
    <VRow>
      <VCol
        cols="12"
        md="6"
      >
        <AiContentComparisonPanel
          ref="sourcePanelElement"
          :mode="sourceView"
          :panel="sourcePanel"
          :highlight="highlightDiff && canCompare"
          :disabled="disabled"
          :clean-only="cleanOnly"
          @update:mode="sourceMode = $event"
          @scroll="syncPanels($event, 'source')"
        />
      </VCol>
      <VCol
        cols="12"
        md="6"
      >
        <AiContentComparisonPanel
          ref="resultPanelElement"
          :mode="resultView"
          :panel="resultPanel"
          :highlight="highlightDiff && canCompare"
          :disabled="disabled"
          :clean-only="cleanOnly"
          @update:mode="resultMode = $event"
          @scroll="syncPanels($event, 'result')"
        />
      </VCol>
    </VRow>
  </div>
</template>

<style scoped>
.ai-content-comparison__toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-block-end: 16px;
}

@media (max-width: 599.98px) {
  .ai-content-comparison__toolbar {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>
