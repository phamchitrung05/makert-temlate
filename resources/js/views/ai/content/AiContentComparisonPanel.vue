<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị một phía so sánh nội dung và metadata đã lưu.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: scrollRatio(), scrollToRatio().
  INPUT/OUTPUT CỦA CLASS (tổng thể): panel/mode/highlight/disabled -> nội dung
  escaped hoặc HTML đã allowlist; phát update:mode/scroll về component cha.
  SIDE EFFECT: scrollToRatio() chỉ đồng bộ vị trí cuộn của panel hiện tại.
  =====================================================================
-->
<script setup>
import { useTemplateRef } from 'vue'

const props = defineProps({
  panel: { type: Object, required: true },
  mode: { type: String, default: 'text' },
  highlight: Boolean,
  disabled: Boolean,
  cleanOnly: Boolean,
})

const emit = defineEmits(['update:mode', 'scroll'])
const viewer = useTemplateRef('viewer')

/** Input: vùng cuộn DOM. Output: tỷ lệ từ 0 đến 1, không chia cho 0. */
const scrollRatio = element => element.scrollHeight > element.clientHeight
  ? element.scrollTop / (element.scrollHeight - element.clientHeight) : 0

/** Input: tỷ lệ cuộn phía đối chiếu. Output: cuộn panel; bỏ thay đổi dưới 1px để tránh lặp. */
function scrollToRatio(ratio) {
  const element = viewer.value
  if (!element) return
  const top = Math.max(0, element.scrollHeight - element.clientHeight) * Math.min(1, Math.max(0, ratio))
  if (Math.abs(element.scrollTop - top) > 1) element.scrollTop = top
}

defineExpose({ scrollToRatio })
</script>

<!-- eslint-disable vue/no-v-html -- previewHtml đã qua previewOfHtml() lọc tag/attribute/URL. -->
<template>
  <VCard
    class="ai-comparison-panel"
    variant="outlined"
  >
    <div class="ai-comparison-panel__header">
      <div class="d-flex align-center justify-space-between gap-2 mb-2">
        <h3 class="d-flex align-center gap-2 text-subtitle-1">
          <VIcon
            :icon="props.panel.icon"
            size="20"
            color="primary"
          />
          {{ props.panel.title }}
        </h3>
        <VChip
          size="x-small"
          color="primary"
          variant="tonal"
        >
          {{ props.panel.badge }}
        </VChip>
      </div>
      <p class="text-caption text-medium-emphasis mb-3">
        {{ props.panel.description }}
      </p>
      <div class="d-flex flex-wrap gap-2">
        <VBtn
          v-for="option in props.panel.modes"
          :key="option.value"
          size="small"
          :variant="props.mode === option.value ? 'tonal' : 'text'"
          :color="props.mode === option.value ? 'primary' : 'secondary'"
          :prepend-icon="option.icon"
          :disabled="props.disabled || (props.cleanOnly && option.value !== 'text')"
          :aria-pressed="props.mode === option.value"
          @click="emit('update:mode', option.value)"
        >
          {{ option.title }}
        </VBtn>
      </div>
    </div>
    <div
      ref="viewer"
      class="ai-comparison-panel__viewer"
      :aria-label="props.panel.ariaLabel"
      role="region"
      tabindex="0"
      @scroll="emit('scroll', scrollRatio($event.currentTarget))"
    >
      <p
        v-if="props.panel.loading"
        class="text-body-2 text-medium-emphasis"
      >
        {{ props.panel.loadingMessage }}
      </p>
      <VAlert
        v-else-if="props.panel.missing"
        type="info"
        variant="tonal"
      >
        {{ props.panel.missingMessage }}
      </VAlert>
      <template v-else>
        <h4
          v-if="props.panel.articleTitle"
          class="text-subtitle-1 mb-4 ai-comparison-panel__value"
        >
          {{ props.panel.articleTitle }}
        </h4>
        <pre
          v-if="props.mode === 'html'"
          class="ai-comparison-panel__code"
        >{{ props.panel.html }}</pre>
        <!-- previewHtml đã được lọc bằng DOM rời trước khi truyền vào panel. -->
        <div
          v-else-if="props.mode === 'preview' && props.panel.previewHtml"
          class="ai-comparison-panel__preview"
          v-html="props.panel.previewHtml"
        />
        <div v-else>
          <p
            v-for="block in props.panel.blocks"
            :key="block.id"
            class="ai-comparison-panel__block text-body-2"
            :class="{ [`ai-comparison-panel__block--${props.panel.diffKind}`]: props.highlight && block.changed }"
          >
            {{ block.text }}
          </p>
          <p
            v-if="!props.panel.blocks.length"
            class="text-body-2 text-medium-emphasis"
          >
            Chưa có nội dung sẵn sàng.
          </p>
        </div>
      </template>
    </div>
    <dl class="ai-comparison-panel__metadata">
      <div
        v-for="item in props.panel.metadata"
        :key="item.label"
        class="ai-comparison-panel__metadata-item"
      >
        <dt class="text-caption text-medium-emphasis d-flex align-center gap-2 mb-1">
          <VIcon
            :icon="item.icon"
            size="16"
            color="primary"
          />
          {{ item.label }}
        </dt>
        <dd class="text-body-2 ai-comparison-panel__value">
          {{ item.value || 'Chưa có' }}
        </dd>
      </div>
    </dl>
  </VCard>
</template>

<style scoped>
.ai-comparison-panel {
  display: flex;
  flex-direction: column;
  border-color: rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
  block-size: 100%;
}

.ai-comparison-panel__header {
  padding: 20px;
  border-block-end: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.ai-comparison-panel__viewer {
  overflow: auto;
  padding: 20px;
  background: rgb(var(--v-theme-background));
  block-size: clamp(280px, 42dvh, 460px);
  overscroll-behavior: contain;
}

.ai-comparison-panel__viewer:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}

.ai-comparison-panel__code {
  margin: 0;
  font-size: 0.8125rem;
  line-height: 1.7;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

.ai-comparison-panel__block {
  border-inline-start: 3px solid transparent;
  line-height: 1.7;
  margin-block-end: 12px;
  overflow-wrap: anywhere;
  padding-block: 8px;
  padding-inline: 12px;
  white-space: pre-wrap;
}

.ai-comparison-panel__block--removed {
  border-color: rgb(var(--v-theme-error));
  background: rgba(var(--v-theme-error), 0.08);
}

.ai-comparison-panel__block--added,
.ai-comparison-panel__preview :deep(.ai-comparison-added) {
  background: rgba(var(--v-theme-success), 0.1);
  border-inline-start: 3px solid rgb(var(--v-theme-success));
}

.ai-comparison-panel__preview {
  font-size: 0.875rem;
  line-height: 1.7;
  overflow-wrap: anywhere;
}

.ai-comparison-panel__preview :deep(p),
.ai-comparison-panel__preview :deep(pre),
.ai-comparison-panel__preview :deep(blockquote) {
  margin-block-end: 12px;
  padding-block: 8px;
  padding-inline: 12px;
}

.ai-comparison-panel__preview :deep(h1),
.ai-comparison-panel__preview :deep(h2),
.ai-comparison-panel__preview :deep(h3) {
  font-size: 1.1rem;
  margin-block: 16px 8px;
}

.ai-comparison-panel__preview :deep(img) {
  border-radius: 6px;
  block-size: auto;
  max-inline-size: 100%;
}

.ai-comparison-panel__preview :deep(table) {
  border-collapse: collapse;
  inline-size: 100%;
  margin-block: 12px;
}

.ai-comparison-panel__preview :deep(td),
.ai-comparison-panel__preview :deep(th) {
  border: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
  padding-block: 8px;
  padding-inline: 10px;
  text-align: start;
}

.ai-comparison-panel__preview :deep(pre) {
  overflow: auto;
  white-space: pre-wrap;
}

.ai-comparison-panel__preview :deep(a) {
  color: rgb(var(--v-theme-primary));
}

.ai-comparison-panel__metadata {
  display: grid;
  flex: 1 1 auto;
  align-content: start;
  border-block-start: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
  gap: 12px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  padding-block: 16px;
  padding-inline: 20px;
}

.ai-comparison-panel__metadata-item {
  padding: 12px;
  border: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
}

.ai-comparison-panel__value {
  margin: 0;
  overflow-wrap: anywhere;
}

@media (max-width: 599.98px) {
  .ai-comparison-panel__header,
  .ai-comparison-panel__viewer {
    padding: 16px;
  }

  .ai-comparison-panel__metadata {
    padding: 16px;
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
