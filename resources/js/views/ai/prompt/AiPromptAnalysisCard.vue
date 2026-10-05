<!--
  =====================================================================
  CHỨC NĂNG FILE: Trình bày kết quả phân tích văn phong và các tab minh họa.
  =====================================================================
  Hiển thị summary/rules/evidence từ API; các mục chưa có contract API được
  gắn nhãn Dữ liệu minh họa riêng, không dùng làm kết quả phân tích thật.

  CÁC HÀM/METHOD TRONG FILE:
  - hasResult (computed): xác định đã có kết quả backend hay chưa.
  - activeDetail (computed): lấy panel minh họa tương ứng tab hiện tại.
  - styleRules/structureRules (computed): nhóm các quy tắc thật theo chức năng.
  - uncertainties (computed): lấy nhận xét chưa đủ bằng chứng từ kết quả.
  - formatRule(value): chuyển chuỗi/danh sách quy tắc sang nội dung đọc được.
  - getRuleEntries(keys): lấy các quy tắc có nội dung trong nhóm được yêu cầu.
  - getFeatureLabel(feature): dịch tên quy tắc của bằng chứng sang tiếng Việt.

  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : report minh họa, result API, status/name và cờ nguồn đã thay đổi.
  - OUTPUT: các panel văn phong, cấu trúc, bằng chứng, giới hạn và minh họa.
  - SIDE EFFECT: chỉ đổi tab cục bộ; không gửi HTTP hoặc thay đổi props.
  =====================================================================
-->
<script setup>
import { computed, shallowRef } from 'vue'

const props = defineProps({
  report: { type: Object, required: true },
  result: { type: Object, default: null },
  status: { type: String, default: '' },
  analysisName: { type: String, default: '' },
  sourceChanged: { type: Boolean, default: false },
})

const detailTab = shallowRef('content')

const ruleLabels = {
  tone: 'Giọng văn',
  pronouns: 'Cách xưng hô',
  emotion: 'Sắc thái cảm xúc',
  opening: 'Cách mở bài',
  'sentence_rhythm': 'Nhịp câu',
  'paragraph_rhythm': 'Nhịp đoạn văn',
  transitions: 'Cách chuyển ý',
  vocabulary: 'Cách dùng từ',
  'technical_terms': 'Thuật ngữ chuyên môn',
  'structure_patterns': 'Mẫu bố cục',
  headings: 'Cách đặt tiêu đề',
  bullets: 'Cách dùng danh sách',
  examples: 'Cách đưa ví dụ',
  ending: 'Cách kết bài',
  avoid: 'Những cách viết cần tránh',
  uncertainties: 'Điểm chưa đủ bằng chứng',
}

const detailTabs = [
  { value: 'content', title: 'Tổng quan' },
  { value: 'style', title: 'Văn phong' },
  { value: 'structure', title: 'Cấu trúc' },
  { value: 'evidence', title: 'Dẫn chứng' },
  { value: 'uncertainties', title: 'Giới hạn' },
  { value: 'seo', title: 'SEO & Từ khóa' },
  { value: 'images', title: 'Hình ảnh' },
  { value: 'uniqueness', title: 'Tính độc đáo' },
]

const illustrationTabs = detailTabs.filter(tab => ['seo', 'images', 'uniqueness'].includes(tab.value))

/**
 * =====================================================================
 * CHỨC NĂNG: Xác định phần trình bày nào nhận dữ liệu thật từ backend.
 * =====================================================================
 * Input: props.result của lượt phân tích đang xem.
 * Output: boolean; không suy diễn trạng thái lỗi thành kết quả minh họa.
 * =====================================================================
 */
const hasResult = computed(() => Boolean(props.result))

/**
 * =====================================================================
 * CHỨC NĂNG: Lấy nhận xét minh họa của tab SEO/ảnh/tính độc đáo.
 * =====================================================================
 * Input: report fixture và tab được người dùng chọn.
 * Output: object panel hoặc undefined; không thay thế result của API.
 * =====================================================================
 */
const activeDetail = computed(() => props.report.details?.[detailTab.value])

/**
 * =====================================================================
 * CHỨC NĂNG: Định dạng quy tắc chuỗi hoặc danh sách thành văn bản.
 * =====================================================================
 * Input: value chuỗi/danh sách từ contract rules.
 * Output: chuỗi dùng interpolation an toàn, không render HTML từ model.
 * =====================================================================
 */
function formatRule(value) {
  return Array.isArray(value) ? value.join('\n') : String(value ?? '')
}

/**
 * =====================================================================
 * CHỨC NĂNG: Nhóm các quy tắc có nội dung để trình bày trong từng tab.
 * =====================================================================
 * Input: keys danh sách field được cho phép; result.rules do backend trả.
 * Output: danh sách key/label/value; không thêm quy tắc chưa có trong kết quả.
 * =====================================================================
 */
function getRuleEntries(keys) {
  return keys.map(key => ({ key, label: ruleLabels[key], value: formatRule(props.result?.rules?.[key]) }))
    .filter(entry => entry.value.trim())
}

/**
 * =====================================================================
 * CHỨC NĂNG: Lấy các quy tắc thật về giọng văn và cách diễn đạt.
 * =====================================================================
 * Input: result.rules reactive từ parent.
 * Output: danh sách quy tắc cho tab Văn phong, không có điểm/phần trăm giả.
 * =====================================================================
 */
const styleRules = computed(() => getRuleEntries(['tone', 'pronouns', 'emotion', 'sentence_rhythm', 'paragraph_rhythm', 'transitions', 'vocabulary', 'technical_terms', 'avoid']))

/**
 * =====================================================================
 * CHỨC NĂNG: Lấy các quy tắc thật về bố cục và cách triển khai bài.
 * =====================================================================
 * Input: result.rules reactive từ parent.
 * Output: danh sách quy tắc cho tab Cấu trúc.
 * =====================================================================
 */
const structureRules = computed(() => getRuleEntries(['opening', 'structure_patterns', 'headings', 'bullets', 'examples', 'ending']))

/**
 * =====================================================================
 * CHỨC NĂNG: Đọc các đặc điểm văn phong chưa được chứng minh chắc chắn.
 * =====================================================================
 * Input: result.rules.uncertainties có thể là danh sách hoặc chưa có.
 * Output: danh sách chuỗi; không biến việc thiếu dữ liệu thành đánh giá tốt.
 * =====================================================================
 */
const uncertainties = computed(() => {
  const value = props.result?.rules?.uncertainties

  return Array.isArray(value) ? value : value ? [String(value)] : []
})

/**
 * =====================================================================
 * CHỨC NĂNG: Dịch mã đặc điểm của dẫn chứng để người dùng đọc được.
 * =====================================================================
 * Input: feature do backend trả theo whitelist rules.
 * Output: nhãn tiếng Việt hoặc mã gốc nếu chưa được hỗ trợ.
 * =====================================================================
 */
function getFeatureLabel(feature) {
  return ruleLabels[feature] || feature
}
</script>

<template>
  <VCard>
    <VCardItem title="Phân tích chi tiết">
      <template #prepend>
        <VAvatar
          color="primary"
          variant="tonal"
          rounded
          class="me-3"
        >
          <VIcon icon="tabler-file-search" />
        </VAvatar>
      </template>
      <template #append>
        <VChip
          :color="hasResult ? 'success' : 'secondary'"
          size="small"
          variant="tonal"
        >
          {{ hasResult ? 'Kết quả phân tích' : 'Chưa có kết quả API' }}
        </VChip>
      </template>
    </VCardItem>

    <VTabs
      v-model="detailTab"
      color="primary"
      show-arrows
      class="px-4"
      aria-label="Các phần phân tích bài tham khảo"
    >
      <VTab
        v-for="tab in detailTabs"
        :key="tab.value"
        :value="tab.value"
      >
        {{ tab.title }}
      </VTab>
    </VTabs>
    <VDivider />

    <VCardText>
      <VAlert
        v-if="props.sourceChanged && hasResult"
        type="warning"
        variant="tonal"
        class="mb-5"
      >
        Nguồn hiện tại đã thay đổi. Kết quả dưới đây thuộc bài mẫu của lần phân tích trước.
      </VAlert>
      <VWindow v-model="detailTab">
        <VWindowItem value="content">
          <template v-if="hasResult">
            <h6 class="text-subtitle-1 font-weight-medium mb-3">
              Tóm tắt văn phong
            </h6>
            <p class="text-body-2 text-medium-emphasis ai-prompt-analysis__text mb-4">
              {{ props.result.summary }}
            </p>
            <VChip
              v-if="props.analysisName"
              size="small"
              variant="tonal"
              color="primary"
            >
              {{ props.analysisName }}
            </VChip>
            <p class="text-body-2 text-medium-emphasis mt-4 mb-0">
              Xem các tab Văn phong, Cấu trúc, Dẫn chứng và Giới hạn để duyệt mẫu trước khi lưu.
            </p>
          </template>
          <template v-else>
            <VAlert
              type="info"
              variant="tonal"
              class="mb-5"
            >
              {{ ['queued', 'analyzing'].includes(props.status) ? 'Đang phân tích bài tham khảo. Kết quả văn phong sẽ xuất hiện khi hoàn tất.' : 'Nhập bài tham khảo và bấm Phân tích AI để nhận kết quả văn phong.' }}
            </VAlert>
            <div class="d-flex align-center flex-wrap gap-2 mb-3">
              <h6 class="text-subtitle-1 font-weight-medium">
                Nhận xét nội dung mẫu
              </h6>
              <VChip
                size="small"
                variant="tonal"
                color="warning"
              >
                Dữ liệu minh họa
              </VChip>
            </div>
            <p class="text-body-2 text-medium-emphasis ai-prompt-analysis__text mb-4">
              {{ props.report.summary }}
            </p>
            <div class="d-flex flex-wrap gap-2">
              <VChip
                v-for="topic in props.report.topics"
                :key="topic"
                color="primary"
                size="small"
                variant="tonal"
              >
                {{ topic }}
              </VChip>
            </div>
          </template>
        </VWindowItem>

        <VWindowItem
          v-for="tab in ['style', 'structure']"
          :key="tab"
          :value="tab"
        >
          <template v-if="hasResult">
            <div class="d-flex flex-column gap-5">
              <div
                v-for="rule in tab === 'style' ? styleRules : structureRules"
                :key="rule.key"
              >
                <h6 class="text-subtitle-1 font-weight-medium mb-2">
                  {{ rule.label }}
                </h6>
                <p class="text-body-2 text-medium-emphasis ai-prompt-analysis__text mb-0">
                  {{ rule.value }}
                </p>
              </div>
            </div>
            <p
              v-if="!(tab === 'style' ? styleRules : structureRules).length"
              class="text-body-2 text-medium-emphasis mb-0"
            >
              Kết quả không có quy tắc cho nhóm này.
            </p>
          </template>
          <template v-else>
            <VChip
              size="small"
              color="warning"
              variant="tonal"
              class="mb-4"
            >
              Dữ liệu minh họa
            </VChip>
            <h6 class="text-subtitle-1 font-weight-medium mb-3">
              {{ props.report.details?.[tab]?.title }}
            </h6>
            <p class="text-body-2 text-medium-emphasis ai-prompt-analysis__text mb-4">
              {{ props.report.details?.[tab]?.description }}
            </p>
            <div
              v-for="item in props.report.details?.[tab]?.items || []"
              :key="item"
              class="text-body-2 text-medium-emphasis mb-2"
            >
              {{ item }}
            </div>
          </template>
        </VWindowItem>

        <VWindowItem value="evidence">
          <div
            v-if="props.result?.evidence?.length"
            class="d-flex flex-column gap-5"
          >
            <div
              v-for="(item, index) in props.result.evidence"
              :key="`${item.feature}-${index}`"
            >
              <VChip
                color="primary"
                size="small"
                variant="tonal"
                class="mb-3"
              >
                {{ getFeatureLabel(item.feature) }}
              </VChip>
              <blockquote class="ai-prompt-analysis__excerpt text-body-2 pa-3 mb-3 rounded">
                {{ item.excerpt }}
              </blockquote>
              <p class="text-body-2 text-medium-emphasis ai-prompt-analysis__text mb-0">
                {{ item.explanation }}
              </p>
            </div>
          </div>
          <p
            v-else
            class="text-body-2 text-medium-emphasis mb-0"
          >
            {{ hasResult ? 'Kết quả hiện tại không có dẫn chứng.' : 'Dẫn chứng từ bài tham khảo sẽ xuất hiện sau khi phân tích thành công.' }}
          </p>
        </VWindowItem>

        <VWindowItem value="uncertainties">
          <p class="text-body-2 text-medium-emphasis mb-4">
            Những đặc điểm AI chưa có đủ bằng chứng từ bài mẫu để kết luận.
          </p>
          <div
            v-if="uncertainties.length"
            class="d-flex flex-column gap-3"
          >
            <div
              v-for="(item, index) in uncertainties"
              :key="index"
              class="d-flex align-start gap-2"
            >
              <VIcon
                icon="tabler-alert-circle"
                color="warning"
                size="18"
                class="mt-1 flex-shrink-0"
              />
              <span class="text-body-2 text-medium-emphasis">{{ item }}</span>
            </div>
          </div>
          <p
            v-else
            class="text-body-2 text-medium-emphasis mb-0"
          >
            {{ hasResult ? 'AI không trả về điểm chưa đủ bằng chứng trong lần phân tích này.' : 'Chưa có kết quả phân tích.' }}
          </p>
        </VWindowItem>

        <VWindowItem
          v-for="tab in illustrationTabs"
          :key="tab.value"
          :value="tab.value"
        >
          <template v-if="activeDetail && detailTab === tab.value">
            <VChip
              size="small"
              color="warning"
              variant="tonal"
              class="mb-4"
            >
              Dữ liệu minh họa
            </VChip>
            <div class="d-flex align-center gap-2 mb-3">
              <VIcon
                :icon="activeDetail.icon"
                color="primary"
                size="20"
              />
              <h6 class="text-subtitle-1 font-weight-medium">
                {{ activeDetail.title }}
              </h6>
            </div>
            <p class="text-body-2 text-medium-emphasis ai-prompt-analysis__text mb-4">
              {{ activeDetail.description }}
            </p>
            <div class="d-flex flex-column gap-3">
              <div
                v-for="item in activeDetail.items"
                :key="item"
                class="d-flex align-start gap-2"
              >
                <VIcon
                  icon="tabler-point"
                  color="primary"
                  size="18"
                  class="mt-1 flex-shrink-0"
                />
                <span class="text-body-2 text-medium-emphasis">{{ item }}</span>
              </div>
            </div>
            <p class="text-caption text-medium-emphasis mt-4 mb-0">
              Mục này chưa có API; nội dung mẫu được giữ để xem bố cục.
            </p>
          </template>
        </VWindowItem>
      </VWindow>
    </VCardText>
  </VCard>
</template>

<style scoped>
.ai-prompt-analysis__text,
.ai-prompt-analysis__excerpt {
  line-height: 1.7;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

.ai-prompt-analysis__excerpt {
  border-inline-start: 3px solid rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.04);
}
</style>
