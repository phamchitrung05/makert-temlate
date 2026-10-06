<!--
  =====================================================================
  CHỨC NĂNG FILE: So sánh văn bản nguồn đã lưu và bài AI mà không chạy HTML nguồn.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: plainText(); sourceText/resultText (computed).
  INPUT/OUTPUT CỦA CLASS (tổng thể): source/draft -> hai panel text đã escape.
  SIDE EFFECT: chỉ parse DOM rời để lấy text; không fetch hoặc gọi API/AI.
  =====================================================================
-->
<script setup>
import { computed } from 'vue'

const props = defineProps({ source: { type: Object, default: null }, draft: { type: Object, default: null } })

/** Input: HTML đã lưu. Output: text giữ ranh giới đoạn/code, bỏ script/style; không attach DOM. */
function plainText(html) {
  const template = document.createElement('template')

  template.innerHTML = html || ''

  const parsed = template.content

  parsed.querySelectorAll('script, style, iframe, object').forEach(node => node.remove())
  parsed.querySelectorAll('br').forEach(node => node.replaceWith('\n'))
  parsed.querySelectorAll('p, div, h1, h2, h3, h4, h5, h6, li, pre, tr, blockquote').forEach(node => node.append('\n\n'))

  return (parsed.textContent || '').replace(/\n{3,}/g, '\n\n').trim()
}

const sourceText = computed(() => plainText(props.source?.content_html))
const resultText = computed(() => plainText(props.draft?.content_html ?? props.draft?.content))
</script>

<template>
  <VRow>
    <VCol
      cols="12"
      md="6"
    >
      <h3 class="text-subtitle-1 mb-2">
        Nguồn đã lưu
      </h3>
      <p
        v-if="props.source?.title"
        class="text-body-2 font-weight-medium"
      >
        {{ props.source.title }}
      </p>
      <pre
        v-if="props.source?.available"
        class="ai-content-comparison__text"
        aria-label="Văn bản nguồn"
      >{{ sourceText }}</pre>
      <VAlert
        v-else
        type="info"
        variant="tonal"
      >
        Bản này chưa có nguồn để đối chiếu. Hệ thống không tự đọc lại URL.
      </VAlert>
    </VCol>
    <VCol
      cols="12"
      md="6"
    >
      <h3 class="text-subtitle-1 mb-2">
        Nội dung AI sau biên tập
      </h3>
      <p
        v-if="props.draft?.title"
        class="text-body-2 font-weight-medium"
      >
        {{ props.draft.title }}
      </p>
      <pre
        class="ai-content-comparison__text"
        aria-label="Văn bản AI"
      >{{ resultText || 'Chưa có nội dung sẵn sàng.' }}</pre>
    </VCol>
  </VRow>
</template>

<style scoped>
.ai-content-comparison__text {
  overflow: auto;
  padding: 16px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
  font: inherit;
  line-height: 1.65;
  max-block-size: 45vh;
  min-block-size: 12rem;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
</style>
