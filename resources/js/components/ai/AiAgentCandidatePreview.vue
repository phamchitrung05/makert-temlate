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
  - providerMeta: resolve label/logo từ capability allowlist, không tin response.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : candidate, selectedFields và provider allowlist từ AiAgentDialog.
  - OUTPUT: event update:selectedFields; không có side effect hoặc request mạng.
  =====================================================================
-->
<script setup>
import { computed } from 'vue'

const props = defineProps({
  candidate: { type: Object, default: null },
  selectedFields: { type: Array, default: () => [] },
  providers: { type: Array, default: () => [] },
})

const emit = defineEmits(['update:selectedFields'])

const fields = computed(() => Object.entries(props.candidate?.outputs ?? props.candidate?.draft ?? {}).map(([key, field]) => ({
  key,
  value: field && typeof field === 'object' && 'value' in field ? field.value : field,
})))

/**
 * =====================================================================
 * CHỨC NĂNG: Tìm metadata provider từ capability allowlist
 * =====================================================================
 * INPUT: Provider key của candidate và provider options public.
 * OUTPUT: Label/logo đã được backend cho phép hoặc null.
 * SIDE EFFECT: Computed chỉ đọc props; không gọi API.
 * EXCEPTION/TRANSACTION: Không dùng metadata tùy ý từ nội dung AI.
 * =====================================================================
 */
const providerMeta = computed(() => props.providers.find(item => item.key === props.candidate?.provider) ?? null)

/**
 * =====================================================================
 * CHỨC NĂNG: Chuẩn hóa prompt và nguồn để review candidate
 * =====================================================================
 * INPUT: Provenance của candidate.
 * OUTPUT: Prompt key, version và source URL.
 * SIDE EFFECT: Computed chỉ đọc props.
 * EXCEPTION/TRANSACTION: Không gọi API hoặc ghi database.
 * =====================================================================
 */
const provenance = computed(() => ({
  prompt: props.candidate?.prompt_key || props.candidate?.promptKey,
  version: props.candidate?.prompt_version || props.candidate?.promptVersion,
  source: props.candidate?.source?.url || props.candidate?.source_url,
}))

/**
 * =====================================================================
 * CHỨC NĂNG: Thay đổi danh sách field được chọn qua event
 * =====================================================================
 * INPUT: Field key và trạng thái checked.
 * OUTPUT: Event update:selectedFields với danh sách distinct.
 * SIDE EFFECT: Emit lên parent; không mutate props.
 * EXCEPTION/TRANSACTION: Không gọi API hoặc ghi Post.
 * =====================================================================
 */
const toggleField = (key, checked) => emit('update:selectedFields', checked ? [...new Set([...props.selectedFields, key])] : props.selectedFields.filter(field => field !== key))

/**
 * =====================================================================
 * CHỨC NĂNG: Chuyển giá trị AI thành text hiển thị an toàn
 * =====================================================================
 * INPUT: String hoặc JSON-compatible output.
 * OUTPUT: Text hoặc JSON formatted; không thực thi HTML/script.
 * SIDE EFFECT: Hàm thuần; template hiển thị bằng text interpolation.
 * EXCEPTION/TRANSACTION: Giá trị API phải JSON-serializable.
 * =====================================================================
 */
const displayValue = value => typeof value === 'string' ? value : JSON.stringify(value, null, 2)
</script>

<template>
  <VCard variant="outlined">
    <VCardTitle class="text-subtitle-1">
      <div class="d-flex align-center gap-2">
        <VAvatar
          v-if="providerMeta?.logo"
          size="24"
          variant="tonal"
          color="primary"
        >
          <VIcon :icon="providerMeta.logo === 'openai' ? 'tabler-brand-openai' : 'tabler-sparkles'" />
        </VAvatar>
        <span>{{ providerMeta?.label || props.candidate?.provider || 'Kết quả AI' }}</span>
        <VChip
          v-if="props.candidate?.model"
          size="x-small"
          variant="tonal"
        >
          {{ props.candidate.model }}
        </VChip>
      </div>
    </VCardTitle>
    <VCardText>
      <div
        v-if="provenance.prompt || provenance.source"
        class="d-flex flex-wrap gap-1 mb-3"
      >
        <VChip
          v-if="provenance.prompt"
          size="x-small"
          color="secondary"
          variant="tonal"
        >
          Prompt {{ provenance.prompt }}<span v-if="provenance.version"> · {{ provenance.version }}</span>
        </VChip>
        <VChip
          v-if="provenance.source"
          size="x-small"
          color="info"
          variant="tonal"
          class="text-truncate"
          style="max-inline-size: 100%;"
        >
          Nguồn: {{ provenance.source }}
        </VChip>
      </div>
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
