<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị candidate AI và chọn field để áp dụng.
  =====================================================================

  Component chỉ render dữ liệu đã được backend validate/sanitize và emit danh
  sách field được chọn. Không gọi API, không thay đổi candidate và không dùng
  v-html cho nội dung AI chưa được kiểm duyệt.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - fields: chuẩn hóa outputs/draft thành danh sách field hiển thị.
  - toggleField(): thêm hoặc bỏ field khỏi selection.
  - displayValue(): chuyển giá trị thành text an toàn.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : candidate và selectedFields từ AiAgentDialog.
  - OUTPUT: event update:selectedFields; không có side effect hoặc request mạng.
  =====================================================================
-->
<script setup>
import { computed } from 'vue'

const props = defineProps({
  candidate: { type: Object, default: null },
  selectedFields: { type: Array, default: () => [] },
})

const emit = defineEmits(['update:selectedFields'])

const fields = computed(() => Object.entries(props.candidate?.outputs ?? props.candidate?.draft ?? {}).map(([key, field]) => ({
  key,
  value: field && typeof field === 'object' && 'value' in field ? field.value : field,
})))

/** Input: field key/check state. Output: danh sách field mới qua emit. */
const toggleField = (key, checked) => emit('update:selectedFields', checked ? [...new Set([...props.selectedFields, key])] : props.selectedFields.filter(field => field !== key))

/** Input: giá trị output. Output: text an toàn, không chạy HTML/script. */
const displayValue = value => typeof value === 'string' ? value : JSON.stringify(value, null, 2)
</script>

<template>
  <VCard variant="outlined">
    <VCardTitle class="text-subtitle-1">
      {{ props.candidate?.model || props.candidate?.provider || 'Kết quả AI' }}
    </VCardTitle>
    <VCardText>
      <VAlert
        v-if="!fields.length"
        type="info"
        variant="tonal"
      >
        Chưa có kết quả.
      </VAlert>
      <VExpansionPanels
        v-else
        multiple
      >
        <VExpansionPanel
          v-for="field in fields"
          :key="field.key"
        >
          <VExpansionPanelTitle>
            <VCheckbox
              :model-value="props.selectedFields.includes(field.key)"
              :label="field.key"
              hide-details
              @click.stop
              @update:model-value="toggleField(field.key, $event)"
            />
          </VExpansionPanelTitle>
          <VExpansionPanelText>
            <pre class="ai-output-preview">{{ displayValue(field.value) }}</pre>
          </VExpansionPanelText>
        </VExpansionPanel>
      </VExpansionPanels>
    </VCardText>
  </VCard>
</template>

<style scoped>
.ai-output-preview { white-space: pre-wrap; overflow-wrap: anywhere; max-block-size: 360px; overflow: auto; font: inherit; }
</style>
