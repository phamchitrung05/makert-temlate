<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị preview nguồn URL/text trước khi AI tạo candidate.
  =====================================================================

  Card giữ presentation của dialog Post cũ; nguồn chỉ được hiển thị dạng text,
  không render HTML không tin cậy và không gọi provider trực tiếp.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - sourceTitle/sourceExcerpt/displaySource: chuẩn hóa preview URL/text.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : sourceValue và sourceType từ dialog.
  - OUTPUT: card preview nguồn; không emit và không có side effect mạng.
  =====================================================================
-->
<script setup>
import { computed } from 'vue'

const props = defineProps({
  sourceValue: {
    type: String,
    default: '',
  },
  sourceType: {
    type: String,
    default: 'url',
  },
})

/** Input: source value/type. Output: title ngắn để render preview an toàn. */
const sourceTitle = computed(() => {
  const firstLine = props.sourceValue.trim().split(/\r?\n/)[0] || ''

  return props.sourceType === 'text' ? firstLine.slice(0, 120) || 'Nội dung nhập tay' : 'Nguồn URL cho AI Agent'
})

/** Input: source value/type. Output: excerpt text không render HTML. */
const sourceExcerpt = computed(() => {
  if (props.sourceType === 'text')
    return props.sourceValue.trim().slice(0, 280) || 'Chưa có nội dung nguồn.'

  return 'URL sẽ được fetch, sanitize và chuyển thành candidate sau khi bạn bắt đầu tạo.'
})

/** Input: source value. Output: chuỗi hiển thị đã giới hạn độ dài. */
const displaySource = computed(() => props.sourceValue.trim() || 'Chưa nhập nguồn')
</script>

<template>
  <VCard
    border
    elevation="0"
    class="h-100"
  >
    <VCardItem class="pb-0">
      <template #prepend>
        <VIcon
          icon="tabler-link"
          size="18"
          color="primary"
          class="me-2"
        />
      </template>
      <VCardTitle class="text-subtitle-2">
        Xem trước nguồn
      </VCardTitle>
    </VCardItem>

    <VCardText>
      <VImg
        height="140"
        cover
        rounded="lg"
        class="bg-primary"
      >
        <div class="d-flex align-center justify-center h-100 banner-overlay">
          <div class="text-center text-white">
            <VIcon
              :icon="props.sourceType === 'text' ? 'tabler-file-text' : 'tabler-link'"
              size="44"
            />
            <div class="text-subtitle-2 font-weight-bold mt-2">
              {{ props.sourceType === 'text' ? 'Text source' : 'URL source' }}
            </div>
          </div>
        </div>
      </VImg>

      <VCardTitle class="px-0 pb-0 pt-3 text-subtitle-2">
        {{ sourceTitle }}
      </VCardTitle>

      <div
        class="text-caption text-primary text-truncate mb-2"
        :title="displaySource"
      >
        {{ displaySource }}
      </div>

      <VCardText class="pa-0 mb-3 text-caption text-medium-emphasis source-excerpt">
        {{ sourceExcerpt }}
      </VCardText>

      <div class="d-flex flex-wrap gap-1">
        <VChip
          size="x-small"
          variant="tonal"
          color="primary"
        >
          {{ props.sourceType === 'text' ? 'Inline source' : 'HTTP source' }}
        </VChip>
        <VChip
          size="x-small"
          variant="tonal"
          color="secondary"
        >
          Sanitize trước khi preview
        </VChip>
      </div>
    </VCardText>
  </VCard>
</template>

<style scoped>
.banner-overlay {
  background: rgba(18, 14, 38, 0.7);
}

.source-excerpt {
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
  line-height: 1.4;
}
</style>
