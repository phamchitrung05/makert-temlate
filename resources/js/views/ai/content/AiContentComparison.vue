<!--
  =====================================================================
  CHỨC NĂNG FILE: Đối chiếu nguồn đã lưu với bản nháp AI hiện tại.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: syncPanels(), scrollRatio(), firstValue(),
  sourceText/resultText/sourceBlocks/resultBlocks và metadata computed.
  INPUT/OUTPUT CỦA CLASS (tổng thể): source/draft/loading/active -> hai
  panel có viền, đồng bộ cuộn, đánh dấu đoạn khác nhau và metadata SEO.
  SIDE EFFECT: chỉ đọc DOM của hai vùng cuộn; không gọi API hoặc sửa draft.
  =====================================================================
-->
<script setup>
import { computed, shallowRef, useTemplateRef } from 'vue'
import { comparisonBlocks, textOfHtml } from '@/utils/aiContentComparison'

const props = defineProps({
  source: { type: Object, default: null },
  draft: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  active: { type: Boolean, default: true },
  original: Boolean,
  showSeo: { type: Boolean, default: true },
})

const syncScroll = shallowRef(true)
const highlightDiff = shallowRef(true)
const sourceViewer = useTemplateRef('sourceViewer')
const resultViewer = useTemplateRef('resultViewer')

const sourceHtml = computed(() => props.source?.content_html || '')
const resultHtml = computed(() => props.draft?.content_html ?? props.draft?.content ?? '')
const sourceText = computed(() => props.active ? textOfHtml(sourceHtml.value) : '')
const resultText = computed(() => props.active ? textOfHtml(resultHtml.value) : '')
const sourceAvailable = computed(() => props.source?.available !== false && Boolean(sourceHtml.value))
const canCompare = computed(() => props.active && !props.loading && sourceAvailable.value && Boolean(sourceText.value) && Boolean(resultText.value))
const sourceBlocks = computed(() => comparisonBlocks(sourceText.value, resultText.value))
const resultBlocks = computed(() => comparisonBlocks(resultText.value, sourceText.value))

/** Input: các giá trị API có thể rỗng. Output: giá trị text đầu tiên có nội dung. */
function firstValue(...values) {
  for (const value of values) {
    if (Array.isArray(value)) {
      const joined = value.filter(item => typeof item === 'string' && item.trim() !== '').join(', ')

      if (joined) return joined
    }
    if (typeof value === 'string' && value.trim() !== '') return value.trim()
  }

  return ''
}

const sourceSeo = computed(() => ({
  title: firstValue(props.source?.seo_title, props.source?.meta_title, props.source?.title),
  keyword: firstValue(props.source?.focus_keyword, props.source?.seo_keyword, props.source?.keywords),
  description: firstValue(props.source?.seo_description, props.source?.meta_description, props.source?.description),
}))

const draftSeo = computed(() => ({
  title: firstValue(props.draft?.seo_title),
  keyword: firstValue(props.draft?.focus_keyword),
  description: firstValue(props.draft?.seo_description),
}))

const sourceMetadata = computed(() => [
  { label: 'Tiêu đề SEO', icon: 'tabler-heading', value: sourceSeo.value.title, empty: 'Chưa có tiêu đề từ nguồn' },
  { label: 'Từ khóa SEO', icon: 'tabler-key', value: sourceSeo.value.keyword, empty: 'Không có dữ liệu từ nguồn' },
  { label: 'Mô tả SEO', icon: 'tabler-file-description', value: sourceSeo.value.description, empty: 'Chưa có mô tả từ nguồn' },
])

const draftMetadata = computed(() => [
  { label: 'Tiêu đề SEO', icon: 'tabler-heading', value: draftSeo.value.title, empty: 'Chưa có' },
  { label: 'Từ khóa SEO', icon: 'tabler-key', value: draftSeo.value.keyword, empty: 'Chưa có' },
  { label: 'Mô tả SEO', icon: 'tabler-file-description', value: draftSeo.value.description, empty: 'Chưa có' },
])

/** Input: phần tử đang cuộn. Output: tỷ lệ từ 0 đến 1, không chia cho 0. */
function scrollRatio(element) {
  const distance = element.scrollHeight - element.clientHeight

  return distance > 0 ? element.scrollTop / distance : 0
}

/** Input: tỷ lệ và panel đích. Output: vị trí cuộn panel đích theo cùng tỷ lệ. */
function scrollToRatio(element, ratio) {
  if (!element) return

  const distance = Math.max(0, element.scrollHeight - element.clientHeight)
  const top = distance * Math.min(1, Math.max(0, ratio))

  if (Math.abs(element.scrollTop - top) > 1) element.scrollTop = top
}

/** Input: sự kiện cuộn và phía thao tác. Output: đồng bộ panel còn lại khi bật tùy chọn. */
function syncPanels(event, side) {
  if (!syncScroll.value || !canCompare.value) return

  const target = side === 'source' ? resultViewer.value : sourceViewer.value

  scrollToRatio(target, scrollRatio(event.currentTarget))
}
</script>

<template>
  <section class="ai-content-comparison">
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
      </div>
    </div>

    <div
      v-if="highlightDiff && canCompare"
      class="ai-content-comparison__legend d-flex flex-wrap gap-4 text-caption text-medium-emphasis"
    >
      <span class="d-flex align-center gap-1">
        <VIcon
          icon="tabler-square-filled"
          size="12"
          color="error"
        />
        Đoạn nguồn khác bài AI
      </span>
      <span class="d-flex align-center gap-1">
        <VIcon
          icon="tabler-square-filled"
          size="12"
          color="success"
        />
        Đoạn AI khác nguồn
      </span>
    </div>

    <VRow
      class="ai-content-comparison__row"
      dense
    >
      <VCol
        cols="12"
        md="6"
      >
        <VCard
          class="ai-content-comparison__panel"
          variant="outlined"
        >
          <VCardItem>
            <template #prepend>
              <VIcon
                icon="tabler-file-description"
                color="primary"
                size="20"
                class="me-2"
              />
            </template>
            <VCardTitle class="text-subtitle-1">
              Nguồn đã lưu
            </VCardTitle>
            <VCardSubtitle>
              Bản chụp nội dung đầu vào lúc tạo bài.
            </VCardSubtitle>
          </VCardItem>

          <VCardText class="pa-0">
            <div
              ref="sourceViewer"
              class="ai-content-comparison__viewer"
              aria-label="Văn bản nguồn"
              role="region"
              tabindex="0"
              @scroll.passive="syncPanels($event, 'source')"
            >
              <p
                v-if="props.loading"
                class="text-body-2 text-medium-emphasis"
              >
                Đang tải nguồn đã lưu…
              </p>
              <VAlert
                v-else-if="!sourceAvailable"
                type="info"
                variant="tonal"
              >
                Bản này chưa có nguồn để đối chiếu. Hệ thống không tự đọc lại URL.
              </VAlert>
              <template v-else>
                <h4
                  v-if="props.source?.title"
                  class="text-subtitle-2 mb-4 ai-content-comparison__article-title"
                >
                  {{ props.source.title }}
                </h4>
                <p
                  v-for="block in sourceBlocks"
                  :key="block.id"
                  class="ai-content-comparison__block text-body-2"
                  :class="{ 'ai-content-comparison__block--removed': highlightDiff && canCompare && block.changed }"
                >
                  {{ block.text }}
                </p>
                <p
                  v-if="!sourceBlocks.length"
                  class="text-body-2 text-medium-emphasis"
                >
                  Chưa có nội dung sẵn sàng.
                </p>
              </template>
            </div>

            <dl
              v-if="props.showSeo"
              class="ai-content-comparison__metadata"
            >
              <div
                v-for="item in sourceMetadata"
                :key="`source-${item.label}`"
                class="ai-content-comparison__metadata-item"
              >
                <dt class="text-caption text-medium-emphasis d-flex align-center gap-2 mb-1">
                  <VIcon
                    :icon="item.icon"
                    size="16"
                    color="primary"
                  />
                  {{ item.label }}
                </dt>
                <dd class="text-body-2">
                  {{ item.value || item.empty }}
                </dd>
              </div>
            </dl>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        md="6"
      >
        <VCard
          class="ai-content-comparison__panel"
          variant="outlined"
        >
          <VCardItem>
            <template #prepend>
              <VIcon
                icon="tabler-sparkles"
                color="primary"
                size="20"
                class="me-2"
              />
            </template>
            <VCardTitle class="text-subtitle-1">
              {{ props.original ? 'Bản AI gốc' : 'Nội dung AI sau biên tập' }}
            </VCardTitle>
            <VCardSubtitle>
              {{ props.original ? 'Snapshot trước khi Post được biên tập.' : 'Bản nháp hiện tại, gồm những chỉnh sửa đã lưu.' }}
            </VCardSubtitle>
          </VCardItem>

          <VCardText class="pa-0">
            <div
              ref="resultViewer"
              class="ai-content-comparison__viewer"
              aria-label="Văn bản AI"
              role="region"
              tabindex="0"
              @scroll.passive="syncPanels($event, 'result')"
            >
              <h4
                v-if="props.draft?.title"
                class="text-subtitle-2 mb-4 ai-content-comparison__article-title"
              >
                {{ props.draft.title }}
              </h4>
              <p
                v-if="props.loading"
                class="text-body-2 text-medium-emphasis"
              >
                Đang tải nội dung AI…
              </p>
              <VAlert
                v-else-if="!resultText"
                type="info"
                variant="tonal"
              >
                Chưa có nội dung AI sẵn sàng.
              </VAlert>
              <template v-else>
                <p
                  v-for="block in resultBlocks"
                  :key="block.id"
                  class="ai-content-comparison__block text-body-2"
                  :class="{ 'ai-content-comparison__block--added': highlightDiff && canCompare && block.changed }"
                >
                  {{ block.text }}
                </p>
              </template>
            </div>

            <dl
              v-if="props.showSeo"
              class="ai-content-comparison__metadata"
            >
              <div
                v-for="item in draftMetadata"
                :key="`draft-${item.label}`"
                class="ai-content-comparison__metadata-item"
              >
                <dt class="text-caption text-medium-emphasis d-flex align-center gap-2 mb-1">
                  <VIcon
                    :icon="item.icon"
                    size="16"
                    color="primary"
                  />
                  {{ item.label }}
                </dt>
                <dd class="text-body-2">
                  {{ item.value || item.empty }}
                </dd>
              </div>
            </dl>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </section>
</template>

<style scoped>
.ai-content-comparison__toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-block-end: 12px;
}

.ai-content-comparison__legend {
  margin-block-end: 16px;
}

.ai-content-comparison__row > :deep(.v-col) {
  display: flex;
}

.ai-content-comparison__panel {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  min-inline-size: 0;
  border-color: rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
}

.ai-content-comparison__panel :deep(.v-card-text) {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  min-block-size: 0;
}

.ai-content-comparison__viewer {
  flex: 1 1 auto;
  min-block-size: 18rem;
  max-block-size: min(56vh, 620px);
  overflow: auto;
  padding: 20px;
  background: rgb(var(--v-theme-background));
  border-block-start: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-block-end: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
  overscroll-behavior: contain;
}

.ai-content-comparison__viewer:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}

.ai-content-comparison__article-title {
  overflow-wrap: anywhere;
}

.ai-content-comparison__block {
  border-inline-start: 3px solid transparent;
  line-height: 1.7;
  margin-block-end: 12px;
  overflow-wrap: anywhere;
  padding-block: 8px;
  padding-inline: 12px;
  white-space: pre-wrap;
}

.ai-content-comparison__block:last-child {
  margin-block-end: 0;
}

.ai-content-comparison__block--removed {
  border-inline-start-color: rgb(var(--v-theme-error));
  background: rgba(var(--v-theme-error), 0.08);
}

.ai-content-comparison__block--added {
  border-inline-start-color: rgb(var(--v-theme-success));
  background: rgba(var(--v-theme-success), 0.1);
}

.ai-content-comparison__metadata {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  margin: 0;
  padding: 16px 20px 20px;
}

.ai-content-comparison__metadata-item {
  min-inline-size: 0;
  padding: 12px;
  border: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
}

.ai-content-comparison__metadata dd {
  margin: 0;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

@media (max-width: 959.98px) {
  .ai-content-comparison__metadata {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 599.98px) {
  .ai-content-comparison__toolbar {
    align-items: flex-start;
    flex-direction: column;
  }

  .ai-content-comparison__viewer {
    padding: 16px;
  }

  .ai-content-comparison__metadata {
    grid-template-columns: minmax(0, 1fr);
    padding: 16px;
  }
}
</style>
