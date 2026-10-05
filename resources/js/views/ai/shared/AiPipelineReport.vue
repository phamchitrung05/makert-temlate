<!--
  =====================================================================
  CHỨC NĂNG FILE: Trình bày checkpoint/usage/gates/profile của một run đã lưu.
  =====================================================================
  Nhánh ngắn đọc reported_model/usage thực của adapter, không đòi key model giả.
  CÁC HÀM/METHOD TRONG FILE: formatTime(), usageText(), statusLabel(), checkLabel(), validationLabel().
  INPUT/OUTPUT CỦA CLASS (tổng thể): public session -> báo cáo escaped; không render
  raw source, intermediate model output, secret hoặc số liệu model giả.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Quality check key backend. */
import { pipelineStepLabel } from '@/utils/aiArticleOptions'

const props = defineProps({ session: { type: Object, default: null } })

/**
 * =====================================================================
 * Input: timestamp server. Output: ngày/giờ vi-VN hoặc —, không dựng mốc giả.
 * =====================================================================
 */
const formatTime = value => value && !Number.isNaN(new Date(value).getTime()) ? new Date(value).toLocaleString('vi-VN') : '—'


/**
 * =====================================================================
 * Input: usage allowlist của step. Output: token thật nếu có, nếu thiếu ghi rõ.
 * =====================================================================
 */
const usageText = diagnostics => {
  const usage = diagnostics?.usage
  if (!usage) return 'Model không trả usage'

  return Object.entries({ 'Đầu vào': usage.prompt_tokens ?? usage.input_tokens, 'Đầu ra': usage.completion_tokens ?? usage.output_tokens, 'Tổng': usage.total_tokens })
    .filter(([, value]) => Number.isFinite(value)).map(([key, value]) => `${key}: ${value} token`).join(' · ') || 'Model không trả usage'
}


/**
 * =====================================================================
 * Input: status checkpoint/gate. Output: nhãn tiếng Việt và fallback escaped.
 * =====================================================================
 */
const statusLabel = value => ({ completed: 'Đã xong', running: 'Đang chạy', processing: 'Đang chạy', failed: 'Lỗi', pending: 'Chờ', passed: 'Đạt', pass: 'Đạt', warning: 'Cần xem lại', undetermined: 'Chưa xác định', skipped: 'Bỏ qua' }[value] ?? value)

/**
 * =====================================================================
 * Input: check key. Output: nhãn rõ mục kiểm, không khẳng định fact-check hoàn chỉnh.
 * =====================================================================
 */
const checkLabel = value => ({ copy: 'Sao chép nguyên văn', exact_copy: 'Sao chép nguyên văn', language: 'Ngôn ngữ', similarity: 'Độ giống từ vựng', grounding: 'Giữ dữ kiện nguồn', editor: 'Biên tập', numbers: 'Số liệu', important_numbers: 'Số liệu quan trọng', unanchored_numbers: 'Số liệu cần đối chiếu', source_code_links: 'Code và liên kết nguồn', semantic_grounding: 'Đối chiếu ngữ nghĩa', rewrite: 'Viết lại', code: 'Code', links: 'Liên kết' }[value] ?? value)

/**
 * =====================================================================
 * Input: mã lý do allowlist backend. Output: giải thích lỗi không chứa excerpt/raw output.
 * =====================================================================
 */
const validationLabel = value => ({ evidence_not_in_source: 'Dẫn chứng không khớp văn bản của vùng nguồn đã chỉ định.', unknown_source_block: 'Tham chiếu vùng nguồn không tồn tại.', duplicate_fact_id: 'ID dữ kiện bị trùng.', unknown_fact_reference: 'Tham chiếu dữ kiện không có trong phân tích.', missing_important_fact_reference: 'Thiếu tham chiếu dữ kiện quan trọng.', important_number_missing: 'Số liệu hoặc phiên bản quan trọng chưa đối chiếu được trong bài.', source_code_changed: 'Code nguồn bị thay đổi hoặc thiếu.', source_link_missing: 'Thiếu liên kết tham khảo nguồn.', exact_copy: 'Bài sao chép nguyên văn phần văn xuôi nguồn.', dominant_language_mismatch: 'Ngôn ngữ bài không khớp lựa chọn.' }[value] ?? value)
</script>

<template>
  <VExpansionPanels
    v-if="props.session"
    class="my-4"
  >
    <VExpansionPanel title="Tiến trình và kiểm tra bài viết">
      <VExpansionPanelText>
        <div class="d-flex flex-wrap gap-2 mb-3">
          <VChip
            size="small"
            color="primary"
          >
            {{ pipelineStepLabel(props.session.current_step || props.session.status) }}
          </VChip>
          <VChip
            v-if="props.session.writing_profile?.name"
            size="small"
          >
            {{ props.session.writing_profile.name }} · v{{ props.session.writing_profile.version }}
          </VChip>
          <VChip
            v-else
            size="small"
            color="secondary"
          >
            Không có mẫu văn phong
          </VChip>
          <VChip
            v-if="props.session.source_format"
            size="small"
          >
            Nguồn {{ props.session.source_format }}
          </VChip>
        </div>
        <p class="text-caption">
          Tiến độ là mốc xử lý. Bài sẵn sàng vẫn cần người biên tập đọc và duyệt.
        </p>
        <div
          v-for="step in props.session.steps || []"
          :key="step.key"
          class="rounded border pa-3 mb-3"
        >
          <div class="d-flex flex-wrap justify-space-between gap-2 mb-2">
            <span class="font-weight-medium">{{ pipelineStepLabel(step.key) }}</span>
            <VChip
              size="small"
              :color="step.status === 'failed' ? 'error' : step.status === 'completed' ? 'success' : 'primary'"
            >
              {{ statusLabel(step.status) }} · lần {{ step.attempt }}
            </VChip>
          </div>
          <div class="text-caption">
            {{ formatTime(step.started_at) }} → {{ formatTime(step.completed_at) }}
          </div>
          <div class="text-caption mt-1">
            {{ usageText(step.diagnostics) }}<span v-if="Number.isFinite(step.diagnostics?.latency_ms)"> · {{ (step.diagnostics.latency_ms / 1000).toFixed(1) }} giây</span>
          </div>
          <div
            v-if="step.diagnostics?.model"
            class="text-caption mt-1"
          >
            {{ step.diagnostics.provider }} / {{ step.diagnostics.model }}
          </div>
          <div
            v-if="step.diagnostics?.prompt_version || step.diagnostics?.schema_version"
            class="text-caption mt-1"
          >
            Prompt {{ step.diagnostics.prompt_version || '—' }} · {{ step.diagnostics.schema_version || '—' }}
          </div>
          <div
            v-if="step.diagnostics?.error_code"
            class="text-body-2 text-error mt-1"
          >
            {{ step.diagnostics.error_code }}
          </div>
          <p
            v-for="(validation, index) in (step.diagnostics?.validation_errors || []).slice(0, 5)"
            :key="index"
            class="text-body-2 text-error mt-1 mb-0"
          >
            {{ validationLabel(validation.reason) }}
          </p>
        </div>
        <p
          v-if="!props.session.steps?.length"
          class="text-caption"
        >
          Run này chưa có checkpoint ba bước. Nhánh ngắn hoặc chỉ trích xuất có thể không gọi đủ ba lượt.
        </p>
        <div
          v-if="!props.session.steps?.length && (props.session.response_diagnostics?.reported_model || props.session.response_diagnostics?.model || props.session.response_diagnostics?.usage)"
          class="rounded border pa-3 mb-3 text-caption"
        >
          <div>Một lượt AI · {{ usageText(props.session.response_diagnostics) }}</div>
          <div>{{ props.session.response_diagnostics.reported_model || props.session.response_diagnostics.model || props.session.model || 'Không rõ model' }} · Prompt {{ props.session.response_diagnostics.prompt_version || props.session.prompt_version || '—' }}</div>
        </div>
        <ul
          v-if="props.session.quality_checks?.length"
          class="ps-5 mb-3"
        >
          <li
            v-for="(check, index) in props.session.quality_checks"
            :key="index"
            class="text-body-2 mb-1"
          >
            {{ checkLabel(check.check) }}: {{ statusLabel(check.status) }}<span v-if="check.reason"> · {{ check.reason }}</span><span v-if="check.metric !== undefined"> · {{ check.metric }}</span>
          </li>
        </ul>
        <VAlert
          v-if="props.session.image_warnings?.includes('restored_unplaced_images')"
          type="warning"
          variant="tonal"
          class="mb-3"
        >
          AI chưa đặt đủ ảnh của bản gốc. Hệ thống đã giữ ảnh ở cuối bài; hãy kiểm tra và chuyển đến vị trí phù hợp.
        </VAlert>
        <VAlert
          v-if="props.session.editor_warning_count"
          type="warning"
          variant="tonal"
          class="mb-3"
        >
          Có {{ props.session.editor_warning_count }} nhận xét cần biên tập rà lại.
        </VAlert>
        <VAlert
          v-if="props.session.error || props.session.error_code"
          type="error"
          variant="tonal"
        >
          {{ props.session.error_code }} · {{ props.session.error }}
          <p
            v-for="(validation, index) in (props.session.validation_errors || []).slice(0, 5)"
            :key="index"
            class="mb-0 mt-1"
          >
            {{ validationLabel(validation.reason) }}
          </p>
        </VAlert>
      </VExpansionPanelText>
    </VExpansionPanel>
  </VExpansionPanels>
</template>
