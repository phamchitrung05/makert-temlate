<!--
  =====================================================================
  CHỨC NĂNG FILE: Xem nội dung extractor đọc được trước khi tạo bài, không render
  =====================================================================
  HTML không tin cậy hoặc thực thi script từ file nguồn.
  CÁC HÀM/METHOD TRONG FILE: blockText/sourceImages (computed), emit read.
  INPUT/OUTPUT CỦA CLASS (tổng thể): snapshot/busy/error -> preview text/code/bảng,
  ảnh nguồn và yêu cầu read; không gửi snapshot preview như nguồn generation mới.
  =====================================================================
-->
<script setup>
import { computed, ref } from 'vue'

const props = defineProps({ snapshot: { type: Object, default: null }, busy: Boolean, disabled: Boolean, error: { type: String, default: '' } })
const emit = defineEmits(['read'])
const blockText = computed(() => (props.snapshot?.blocks ?? []).map(block => block.text).join('\n\n'))
const sourceImages = computed(() => props.snapshot?.source_images?.length ?? 0)

// Trạng thái mở/thu của khối preview (section phẳng, không dùng accordion lồng trong card).
const previewOpen = ref(true)
</script>

<template>
  <div class="mb-4">
    <VBtn
      variant="tonal"
      prepend-icon="tabler-file-search"
      :loading="props.busy"
      :disabled="props.disabled || props.busy"
      @click="emit('read')"
    >
      Xem nội dung nguồn
    </VBtn>
    <VAlert
      v-if="props.error"
      type="error"
      variant="tonal"
      class="mt-3"
    >
      {{ props.error }}
    </VAlert>
    <template v-if="props.snapshot">
      <VDivider class="mt-4 mb-4" />

      <div
        class="d-flex align-center justify-space-between cursor-pointer mb-3"
        role="button"
        @click="previewOpen = !previewOpen"
      >
        <span class="text-body-1 font-weight-medium text-high-emphasis">
          Nguồn đã trích xuất
        </span>
        <VIcon
          :icon="previewOpen ? 'tabler-chevron-up' : 'tabler-chevron-down'"
          color="secondary"
          size="20"
        />
      </div>

      <div v-show="previewOpen">
        <div class="font-weight-medium mb-2">
          {{ props.snapshot.title || 'Nguồn không có tiêu đề' }}
        </div>
        <div class="text-caption mb-3">
          {{ props.snapshot.blocks?.length || 0 }} khối nội dung · {{ sourceImages }} ảnh nguồn
        </div>
        <pre class="ai-source-preview__text text-body-2">{{ blockText }}</pre>
        <p class="text-caption mt-3 mb-0">
          Kiểm tra vùng bài trước khi gửi. Preview chỉ đọc nguồn; khi tạo bài backend chụp nguồn lại. Ảnh nguồn là thông tin tham khảo, chưa tự nhập vào MediaLibrary.
        </p>
      </div>
    </template>
  </div>
</template>

<style scoped>
.ai-source-preview__text { max-block-size: 360px; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.7; }
</style>
