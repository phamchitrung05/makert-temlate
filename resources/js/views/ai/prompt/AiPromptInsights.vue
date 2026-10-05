<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị các card tổng quan và gợi ý văn phong của Ai Prompt.
  =====================================================================
  Ghép thống kê nguồn cục bộ và văn phong thật từ API vào bố cục đã custom.
  Điểm số/phần trăm chưa có API được gắn nhãn minh họa riêng; không gọi HTTP.

  CÁC HÀM/METHOD TRONG FILE:
  - overviewMetrics (computed): ghép số liệu nguồn và tên văn phong thành sáu chỉ số.
  - hasResult (computed): xác định đang hiển thị kết quả văn phong từ API.
  - displayedPrompt (computed): lấy hướng dẫn đã duyệt hoặc prompt minh họa.
  - observedRules (computed): lấy các đặc điểm văn phong thật đã nhận được.
  - requestCopy(): yêu cầu page sao chép hướng dẫn văn phong đang hiển thị.
  - requestExport(): yêu cầu page tải báo cáo kết quả thật về máy.

  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : report gồm score/styleAnalysis/styleTone/readability/promptText/topic;
  - INPUT : metrics gồm wordCount/paragraphCount/imageCount/linkCount/readingMinutes.
  - INPUT : result/status/provider/model/name/promptText từ lượt phân tích hiện tại.
  - OUTPUT: card tổng quan, đánh giá minh họa, văn phong và prompt; copy/export.
  - SIDE EFFECT: không thay đổi props, clipboard hoặc dữ liệu database.
  =====================================================================
-->
<script setup>
import { computed } from 'vue'

const props = defineProps({
  report: { type: Object, required: true },
  metrics: { type: Object, required: true },
  result: { type: Object, default: null },
  status: { type: String, default: '' },
  provider: { type: String, default: '' },
  model: { type: String, default: '' },
  analysisName: { type: String, default: '' },
  promptText: { type: String, default: '' },
})

const emit = defineEmits(['copy', 'export'])
const numberFormatter = new Intl.NumberFormat('vi-VN')

/**
 * =====================================================================
 * CHỨC NĂNG: Ghép dữ liệu nguồn thành các chỉ số tổng quan.
 * =====================================================================
 * Input: số liệu nguồn reactive và tên văn phong từ page.
 * Output: sáu giá trị thật của nguồn/name; không sử dụng chủ đề minh họa.
 * =====================================================================
 */
const overviewMetrics = computed(() => [
  { label: 'Số từ', value: numberFormatter.format(props.metrics.wordCount ?? 0) },
  { label: 'Đoạn văn', value: numberFormatter.format(props.metrics.paragraphCount ?? 0) },
  { label: 'Hình ảnh', value: numberFormatter.format(props.metrics.imageCount ?? 0) },
  { label: 'Liên kết', value: numberFormatter.format(props.metrics.linkCount ?? 0) },
  { label: 'Thời gian đọc', value: `${props.metrics.readingMinutes ?? 0} phút` },
  { label: 'Tên văn phong', value: props.analysisName || 'Chưa đặt tên' },
])

/**
 * =====================================================================
 * CHỨC NĂNG: Đánh dấu hướng dẫn/quy tắc đang đọc là dữ liệu backend.
 * =====================================================================
 * Input: result của lượt phân tích hiện tại.
 * Output: boolean để hiển thị nhãn chính xác cho từng phần.
 * =====================================================================
 */
const hasResult = computed(() => Boolean(props.result))

/**
 * =====================================================================
 * CHỨC NĂNG: Chọn nội dung hướng dẫn đang được duyệt hoặc mẫu minh họa.
 * =====================================================================
 * Input: promptText của form, result.style_instructions hoặc fixture khi chưa có result.
 * Output: chuỗi hướng dẫn hiển thị; không thay thế chuỗi người dùng đã xóa bằng bản AI.
 * =====================================================================
 */
const displayedPrompt = computed(() => hasResult.value ? props.promptText : props.report.promptText)

/**
 * =====================================================================
 * CHỨC NĂNG: Lấy những đặc điểm văn phong thật để hiển thị bên cạnh biểu đồ mẫu.
 * =====================================================================
 * Input: result.rules.tone/pronouns/emotion/sentence_rhythm.
 * Output: danh sách nhãn/nội dung có quan sát thật, không thêm phần trăm.
 * =====================================================================
 */
const observedRules = computed(() => [
  { label: 'Giọng văn', value: props.result?.rules?.tone },
  { label: 'Cách xưng hô', value: props.result?.rules?.pronouns },
  { label: 'Sắc thái cảm xúc', value: props.result?.rules?.emotion },
  { label: 'Nhịp câu', value: props.result?.rules?.sentence_rhythm },
].filter(item => item.value))

/**
 * =====================================================================
 * CHỨC NĂNG: Yêu cầu sao chép hướng dẫn đang hiển thị.
 * =====================================================================
 * Input: thao tác bấm Sao chép của người dùng.
 * Output: emit copy để page xử lý clipboard và thông báo; không tự ghi clipboard.
 * =====================================================================
 */
function requestCopy() {
  emit('copy')
}

/**
 * =====================================================================
 * CHỨC NĂNG: Yêu cầu tải báo cáo thật đang được duyệt.
 * =====================================================================
 * Input: thao tác bấm Tải báo cáo khi đã có result.
 * Output: emit export để page tạo file cục bộ; không tự ghi file hoặc gọi model.
 * =====================================================================
 */
function requestExport() {
  emit('export')
}
</script>

<template>
  <div class="ai-prompt-insights d-flex flex-column gap-6">
    <VCard>
      <VCardItem title="Thông tin tổng quan">
        <template #prepend>
          <VAvatar
            color="primary"
            variant="tonal"
            rounded
            class="me-3"
          >
            <VIcon icon="tabler-info-circle" />
          </VAvatar>
        </template>
      </VCardItem>
      <VCardText>
        <p class="text-caption text-medium-emphasis mb-4">
          Thống kê từ nguồn đang nhập; thời gian đọc là ước tính.
        </p>
        <div class="ai-prompt-insights__overview rounded">
          <div
            v-for="metric in overviewMetrics"
            :key="metric.label"
            class="ai-prompt-insights__overview-cell pa-3 text-center"
          >
            <div class="text-h6 font-weight-medium ai-prompt-insights__metric-value">
              {{ metric.value }}
            </div>
            <div class="text-caption text-medium-emphasis mt-1">
              {{ metric.label }}
            </div>
          </div>
        </div>
        <div
          v-if="props.model || props.provider"
          class="d-flex flex-wrap gap-2 mt-4"
        >
          <VChip
            v-if="props.provider"
            size="small"
            variant="tonal"
            color="secondary"
          >
            {{ props.provider }}
          </VChip>
          <VChip
            v-if="props.model"
            size="small"
            variant="tonal"
            color="primary"
          >
            {{ props.model }}
          </VChip>
        </div>
      </VCardText>
    </VCard>

    <VCard>
      <VCardItem title="Đánh giá tổng thể">
        <template #prepend>
          <VAvatar
            color="primary"
            variant="tonal"
            rounded
            class="me-3"
          >
            <VIcon icon="tabler-gauge" />
          </VAvatar>
        </template>
        <template #append>
          <VChip
            size="small"
            variant="tonal"
            color="warning"
          >
            Dữ liệu minh họa
          </VChip>
        </template>
      </VCardItem>
      <VCardText>
        <p class="text-caption text-medium-emphasis mb-4">
          Điểm số và nhận xét dưới đây đang dùng dữ liệu tĩnh vì chưa có API đánh giá.
        </p>
        <div class="d-flex flex-wrap align-center gap-6 mb-6">
          <VProgressCircular
            :model-value="props.report.score.total"
            :size="104"
            :width="8"
            color="info"
            class="flex-shrink-0"
            :aria-label="`Điểm minh họa ${props.report.score.total} trên 100`"
          >
            <div class="text-center">
              <div class="text-h5 font-weight-medium">
                {{ props.report.score.total }}<span class="text-caption text-medium-emphasis">/100</span>
              </div>
              <div class="text-caption font-weight-medium text-info">
                {{ props.report.score.label }}
              </div>
            </div>
          </VProgressCircular>
          <div class="ai-prompt-insights__checks d-flex flex-column gap-2">
            <div
              v-for="check in props.report.score.checks"
              :key="check.text"
              class="d-flex align-start gap-2 text-body-2"
            >
              <VIcon
                :icon="check.icon"
                :color="check.color"
                size="18"
                class="flex-shrink-0 mt-1"
              />
              <span>{{ check.text }}</span>
            </div>
          </div>
        </div>
        <VDivider class="mb-4" />
        <VRow>
          <VCol
            v-for="metric in props.report.score.metrics"
            :key="metric.title"
            cols="12"
            sm="4"
          >
            <div class="d-flex align-start gap-1 text-body-2 text-medium-emphasis mb-2">
              <VIcon
                :icon="metric.icon"
                :color="metric.color"
                size="18"
                class="flex-shrink-0"
              />
              <span>{{ metric.title }}</span>
            </div>
            <div class="text-h6 font-weight-medium mb-2">
              {{ metric.value }}<span class="text-caption text-medium-emphasis">/100</span>
            </div>
            <VProgressLinear
              :model-value="metric.value"
              :color="metric.color"
              :aria-label="`${metric.title}: ${metric.value} trên 100, minh họa`"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard>
      <VCardItem title="Phân tích văn phong">
        <template #prepend>
          <VAvatar
            color="primary"
            variant="tonal"
            rounded
            class="me-3"
          >
            <VIcon icon="tabler-feather" />
          </VAvatar>
        </template>
      </VCardItem>
      <VCardText>
        <template v-if="hasResult">
          <VChip
            size="small"
            variant="tonal"
            color="success"
            class="mb-4"
          >
            Kết quả phân tích
          </VChip>
          <div class="d-flex flex-column gap-4 mb-5">
            <div
              v-for="item in observedRules"
              :key="item.label"
            >
              <h6 class="text-subtitle-1 font-weight-medium mb-2">
                {{ item.label }}
              </h6>
              <p class="text-body-2 text-medium-emphasis ai-prompt-insights__rule mb-0">
                {{ item.value }}
              </p>
            </div>
          </div>
          <VDivider class="mb-5" />
        </template>
        <div class="d-flex align-center flex-wrap gap-2 mb-4">
          <VChip
            size="small"
            variant="tonal"
            color="warning"
          >
            Dữ liệu minh họa
          </VChip>
          <span class="text-caption text-medium-emphasis">Các tỷ lệ chưa có API phân tích.</span>
        </div>
        <div
          v-if="!hasResult"
          class="d-flex flex-wrap gap-2 mb-5"
        >
          <VChip
            size="small"
            variant="tonal"
            color="primary"
          >
            Văn phong: {{ props.report.styleTone }}
          </VChip>
          <VChip
            size="small"
            variant="tonal"
            color="info"
          >
            {{ props.report.readability }}
          </VChip>
        </div>
        <div class="d-flex flex-column gap-4">
          <div
            v-for="item in props.report.styleAnalysis"
            :key="item.name"
          >
            <div class="d-flex justify-space-between align-start gap-2 mb-2 text-body-2">
              <span class="font-weight-medium">{{ item.name }}</span>
              <span class="font-weight-medium flex-shrink-0">{{ item.percent }}%</span>
            </div>
            <VProgressLinear
              :model-value="item.percent"
              :color="item.color"
              :aria-label="`${item.name}: ${item.percent} phần trăm, minh họa`"
            />
            <div class="text-caption text-medium-emphasis mt-2">
              {{ item.desc }}
            </div>
          </div>
        </div>
      </VCardText>
    </VCard>

    <VCard>
      <VCardItem title="Hướng dẫn văn phong">
        <template #prepend>
          <VAvatar
            color="primary"
            variant="tonal"
            rounded
            class="me-3"
          >
            <VIcon icon="tabler-sparkles" />
          </VAvatar>
        </template>
        <template #append>
          <VChip
            :color="hasResult ? 'success' : 'warning'"
            size="small"
            variant="tonal"
          >
            {{ hasResult ? 'Đang duyệt' : 'Dữ liệu minh họa' }}
          </VChip>
        </template>
      </VCardItem>
      <VCardText>
        <div class="ai-prompt-insights__prompt rounded pa-4 text-body-2">
          {{ displayedPrompt || 'Nhập hướng dẫn văn phong trong phần duyệt và lưu mẫu.' }}
        </div>
        <div class="d-flex flex-wrap justify-end gap-2 mt-3">
          <VBtn
            variant="text"
            size="small"
            prepend-icon="tabler-download"
            :disabled="!hasResult"
            @click="requestExport"
          >
            Tải báo cáo
          </VBtn>
          <VBtn
            variant="text"
            size="small"
            prepend-icon="tabler-copy"
            :disabled="!displayedPrompt"
            @click="requestCopy"
          >
            Sao chép
          </VBtn>
        </div>
      </VCardText>
    </VCard>
  </div>
</template>

<style scoped>
.ai-prompt-insights,
.ai-prompt-insights__overview-cell,
.ai-prompt-insights__checks {
  min-inline-size: 0;
}

.ai-prompt-insights__overview {
  display: grid;
  overflow: hidden;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.ai-prompt-insights__overview-cell:not(:nth-child(3n)) {
  border-inline-end: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.ai-prompt-insights__overview-cell:nth-child(-n + 3) {
  border-block-end: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.ai-prompt-insights__metric-value {
  overflow-wrap: anywhere;
}

.ai-prompt-insights__checks {
  flex: 1 1 12rem;
}

.ai-prompt-insights__prompt {
  background: rgba(var(--v-theme-primary), 0.04);
  line-height: 1.7;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

.ai-prompt-insights__rule {
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
</style>
