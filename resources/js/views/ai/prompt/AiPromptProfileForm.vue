<!--
  =====================================================================
  CHỨC NĂNG FILE: Form duyệt và lưu văn phong sau khi phân tích bài mẫu.
  =====================================================================
  Người dùng sửa tên/mô tả/quy tắc/hướng dẫn trước khi bấm lưu. Trích đoạn
  bằng chứng được giữ nguyên; giao tiếp qua props/events, không tự gọi API.

  CÁC HÀM/METHOD TRONG FILE:
  - formDisabled (computed): khóa thao tác khi đang gửi hoặc lưu dữ liệu.
  - ruleCount (computed): đếm quy tắc có trong bản đang duyệt.
  - isDefault (computed): xác định mẫu đã lưu đang là mặc định của hệ thống.
  - setField(key, value): gửi object form mới cho các field tổng thể.
  - getRuleValue(key): chuyển quy tắc thành chuỗi để nhập liệu.
  - setRule(key, value): cập nhật rule hoặc bỏ field đã xóa nội dung.
  - setEvidenceExplanation(index, value): sửa diễn giải, giữ nguyên trích đoạn.
  - removeEvidence(index): bỏ dẫn chứng người dùng không muốn dùng.
  - getErrors(key): đọc lỗi validation tương ứng field.
  - requestSave(): gửi sự kiện lưu khi trạng thái cho phép.
  - requestReload(): yêu cầu đọc phiên bản mới sau xung đột.

  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : modelValue gồm name/description/rules_json/evidence_json/hướng dẫn;
  - INPUT : busy/saving/errors/savedProfile/conflict/canSave/defaultProfileId.
  - OUTPUT: update:modelValue với object mới, save và reloadProfile.
  - SIDE EFFECT: không mutate props, gọi model hoặc lưu trực tiếp database.
  =====================================================================
-->
<script setup>
import { computed } from 'vue'

const props = defineProps({
  modelValue: { type: Object, required: true },
  saving: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  errors: { type: Object, default: () => ({}) },
  savedProfile: { type: Object, default: null },
  conflict: { type: Boolean, default: false },
  canSave: { type: Boolean, default: false },
  defaultProfileId: { type: Number, default: null },
  defaultError: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'save', 'reloadProfile'])

const ruleFields = [
  { key: 'tone', label: 'Giọng văn' },
  { key: 'pronouns', label: 'Cách xưng hô' },
  { key: 'emotion', label: 'Sắc thái cảm xúc' },
  { key: 'opening', label: 'Cách mở bài' },
  { key: 'sentence_rhythm', label: 'Nhịp câu' },
  { key: 'paragraph_rhythm', label: 'Nhịp đoạn văn' },
  { key: 'transitions', label: 'Cách chuyển ý' },
  { key: 'vocabulary', label: 'Cách dùng từ' },
  { key: 'technical_terms', label: 'Thuật ngữ chuyên môn' },
  { key: 'structure_patterns', label: 'Mẫu bố cục', isList: true },
  { key: 'headings', label: 'Cách đặt tiêu đề' },
  { key: 'bullets', label: 'Cách dùng danh sách' },
  { key: 'examples', label: 'Cách đưa ví dụ' },
  { key: 'ending', label: 'Cách kết bài' },
  { key: 'avoid', label: 'Những cách viết cần tránh', isList: true },
  { key: 'uncertainties', label: 'Điểm chưa đủ bằng chứng', isList: true },
]

const arrayRuleKeys = new Set(ruleFields.filter(field => field.isList).map(field => field.key))

/**
 * =====================================================================
 * CHỨC NĂNG: Khóa form khi parent đang xử lý request.
 * =====================================================================
 * Input: busy/saving từ orchestration của page.
 * Output: boolean dùng đồng nhất cho các input và thao tác.
 * =====================================================================
 */
const formDisabled = computed(() => props.busy || props.saving)

/**
 * =====================================================================
 * CHỨC NĂNG: Đếm những quy tắc thật có nội dung trong form hiện tại.
 * =====================================================================
 * Input: modelValue.rules_json từ parent.
 * Output: số lượng field có giá trị, không đánh giá chất lượng bài mẫu.
 * =====================================================================
 */
const ruleCount = computed(() => ruleFields.filter(field => getRuleValue(field.key).trim()).length)

/**
 * =====================================================================
 * CHỨC NĂNG: Đối chiếu mẫu đã lưu với cấu hình mặc định đã tải.
 * =====================================================================
 * Input: savedProfile.id và defaultProfileId do parent trả.
 * Output: boolean để hiển thị trạng thái mặc định đã được backend xác nhận.
 * =====================================================================
 */
const isDefault = computed(() => Boolean(props.savedProfile?.id) && props.savedProfile.id === props.defaultProfileId)

/**
 * =====================================================================
 * CHỨC NĂNG: Thay field tổng thể bằng object mới, giữ dữ liệu các field khác.
 * =====================================================================
 * Input: key và value của input; setAsDefault chỉ là lựa chọn giao diện.
 * Output: emit update:modelValue; tắt mẫu sẽ bỏ lựa chọn đặt mặc định.
 * =====================================================================
 */
function setField(key, value) {
  const next = { ...props.modelValue, [key]: value }

  if (key === 'is_enabled' && !value)
    next.setAsDefault = false

  emit('update:modelValue', next)
}

/**
 * =====================================================================
 * CHỨC NĂNG: Chuyển rules chuỗi/danh sách thành nội dung input.
 * =====================================================================
 * Input: key trong whitelist và modelValue.rules_json.
 * Output: chuỗi; danh sách giữ dấu xuống dòng để người dùng nhập từng ý.
 * =====================================================================
 */
function getRuleValue(key) {
  const value = props.modelValue.rules_json?.[key]

  return Array.isArray(value) ? value.join('\n') : String(value ?? '')
}

/**
 * =====================================================================
 * CHỨC NĂNG: Sửa rule theo field được phép và giữ dữ liệu gõ nhiều dòng.
 * =====================================================================
 * Input: key và chuỗi từ textarea; ba rule danh sách tách theo xuống dòng.
 * Output: object form mới; parent chuẩn hóa dòng trống trước khi gửi API.
 * =====================================================================
 */
function setRule(key, value) {
  const rules = { ...props.modelValue.rules_json }

  if (value === '')
    delete rules[key]
  else
    rules[key] = arrayRuleKeys.has(key) ? String(value).split('\n') : value

  setField('rules_json', rules)
}

/**
 * =====================================================================
 * CHỨC NĂNG: Sửa lời giải thích cho dẫn chứng mà không thay câu trích nguồn.
 * =====================================================================
 * Input: index bằng chứng và chuỗi giải thích do người dùng nhập.
 * Output: emit form mới với evidence_json mới; excerpt/feature được giữ nguyên.
 * =====================================================================
 */
function setEvidenceExplanation(index, value) {
  const evidence = (props.modelValue.evidence_json || []).map((item, itemIndex) => itemIndex === index ? { ...item, explanation: value } : item)

  setField('evidence_json', evidence)
}

/**
 * =====================================================================
 * CHỨC NĂNG: Bỏ một dẫn chứng khỏi bản người dùng đang duyệt.
 * =====================================================================
 * Input: index của dẫn chứng bị loại bỏ.
 * Output: emit form mới; không thay đổi kết quả phân tích gốc từ backend.
 * =====================================================================
 */
function removeEvidence(index) {
  setField('evidence_json', (props.modelValue.evidence_json || []).filter((item, itemIndex) => itemIndex !== index))
}

/**
 * =====================================================================
 * CHỨC NĂNG: Lấy các lỗi validation của một field từ response chuẩn.
 * =====================================================================
 * Input: key theo tên field API trong errors của parent.
 * Output: danh sách chuỗi để AppTextField/AppTextarea hiển thị dưới input.
 * =====================================================================
 */
function getErrors(key) {
  const value = props.errors[key]

  return Array.isArray(value) ? value : value ? [String(value)] : []
}

/**
 * =====================================================================
 * CHỨC NĂNG: Yêu cầu lưu mẫu đã duyệt khi parent cho phép.
 * =====================================================================
 * Input: thao tác submit và canSave/busy/saving/conflict.
 * Output: emit save; không gọi HTTP hoặc tự vượt qua xung đột phiên bản.
 * =====================================================================
 */
function requestSave() {
  if (props.canSave && !formDisabled.value && !props.conflict)
    emit('save')
}

/**
 * =====================================================================
 * CHỨC NĂNG: Yêu cầu parent tải lại profile sau khi có xung đột phiên bản.
 * =====================================================================
 * Input: thao tác Tải phiên bản mới; page xử lý cảnh báo dữ liệu chưa lưu.
 * Output: emit reloadProfile; không tự động ghi đè form.
 * =====================================================================
 */
function requestReload() {
  emit('reloadProfile')
}
</script>

<template>
  <VCard>
    <VCardItem
      title="Duyệt và lưu văn phong"
      subtitle="Bạn có thể chỉnh sửa hướng dẫn trước khi dùng cho các bài viết sau."
    >
      <template #prepend>
        <VAvatar
          color="primary"
          variant="tonal"
          rounded
          class="me-3"
        >
          <VIcon icon="tabler-device-floppy" />
        </VAvatar>
      </template>
    </VCardItem>
    <VCardText>
      <div
        v-if="props.savedProfile"
        class="d-flex flex-wrap gap-2 mb-5"
      >
        <VChip
          color="success"
          size="small"
          variant="tonal"
        >
          Đã lưu · #{{ props.savedProfile.id }} · Phiên bản {{ props.savedProfile.version }}
        </VChip>
        <VChip
          v-if="isDefault"
          color="primary"
          size="small"
          variant="tonal"
        >
          Văn phong mặc định
        </VChip>
      </div>
      <VAlert
        v-if="props.defaultError"
        type="warning"
        variant="tonal"
        class="mb-5"
      >
        Văn phong đã lưu nhưng chưa đặt được làm mặc định. {{ props.defaultError }}
      </VAlert>
      <VAlert
        v-if="props.conflict"
        type="warning"
        variant="tonal"
        class="mb-5"
      >
        Văn phong đã được cập nhật ở nơi khác. Bản chỉnh sửa của bạn vẫn được giữ; tải phiên bản mới để tiếp tục.
        <div class="mt-3">
          <VBtn
            variant="tonal"
            color="warning"
            size="small"
            :disabled="formDisabled"
            @click="requestReload"
          >
            Tải phiên bản mới
          </VBtn>
        </div>
      </VAlert>
      <VForm @submit.prevent="requestSave">
        <VRow>
          <VCol cols="12">
            <AppTextField
              :model-value="props.modelValue.name || ''"
              label="Tên văn phong"
              placeholder="Ví dụ: Giải thích dễ hiểu"
              maxlength="160"
              :counter="160"
              :disabled="formDisabled"
              :error-messages="getErrors('name')"
              @update:model-value="setField('name', $event)"
            />
          </VCol>
          <VCol cols="12">
            <AppTextarea
              :model-value="props.modelValue.description || ''"
              label="Mô tả"
              placeholder="Văn phong này phù hợp với nội dung nào?"
              maxlength="2000"
              :counter="2000"
              :rows="2"
              auto-grow
              :disabled="formDisabled"
              :error-messages="getErrors('description')"
              @update:model-value="setField('description', $event)"
            />
          </VCol>
          <VCol cols="12">
            <AppTextarea
              :model-value="props.modelValue.style_instructions || ''"
              label="Hướng dẫn văn phong"
              placeholder="Các hướng dẫn được áp dụng khi AI viết bài mới."
              maxlength="10000"
              :counter="10000"
              :rows="6"
              :max-rows="16"
              auto-grow
              :disabled="formDisabled"
              :error-messages="getErrors('style_instructions')"
              @update:model-value="setField('style_instructions', $event)"
            />
          </VCol>
          <VCol cols="12">
            <VExpansionPanels variant="accordion">
              <VExpansionPanel>
                <VExpansionPanelTitle>
                  Quy tắc văn phong · {{ ruleCount }} mục
                </VExpansionPanelTitle>
                <VExpansionPanelText>
                  <p class="text-body-2 text-medium-emphasis mb-4">
                    Giữ các đặc điểm có thể dùng lại cho bài mới. Các ô danh sách nhập mỗi ý trên một dòng.
                  </p>
                  <VAlert
                    v-if="getErrors('rules_json').length"
                    type="error"
                    variant="tonal"
                    class="mb-4"
                  >
                    {{ getErrors('rules_json').join(' ') }}
                  </VAlert>
                  <VRow>
                    <VCol
                      v-for="field in ruleFields"
                      :key="field.key"
                      cols="12"
                      md="6"
                    >
                      <AppTextarea
                        :model-value="getRuleValue(field.key)"
                        :label="field.label"
                        :hint="field.isList ? 'Mỗi ý trên một dòng; tối đa 20 ý.' : ''"
                        :rows="2"
                        :max-rows="8"
                        :maxlength="field.isList ? undefined : 2000"
                        auto-grow
                        :disabled="formDisabled"
                        :error-messages="getErrors(`rules_json.${field.key}`)"
                        @update:model-value="setRule(field.key, $event)"
                      />
                    </VCol>
                  </VRow>
                </VExpansionPanelText>
              </VExpansionPanel>
              <VExpansionPanel>
                <VExpansionPanelTitle>
                  Dẫn chứng · {{ props.modelValue.evidence_json?.length || 0 }} mục
                </VExpansionPanelTitle>
                <VExpansionPanelText>
                  <p class="text-body-2 text-medium-emphasis mb-4">
                    Trích đoạn giữ nguyên để đối chiếu bài mẫu. Bạn có thể sửa diễn giải hoặc bỏ dẫn chứng không phù hợp.
                  </p>
                  <VAlert
                    v-if="getErrors('evidence_json').length"
                    type="error"
                    variant="tonal"
                    class="mb-4"
                  >
                    {{ getErrors('evidence_json').join(' ') }}
                  </VAlert>
                  <div class="d-flex flex-column gap-5">
                    <div
                      v-for="(item, index) in props.modelValue.evidence_json || []"
                      :key="`${item.feature}-${index}`"
                    >
                      <div class="d-flex align-center justify-space-between gap-2 mb-3">
                        <span class="text-subtitle-2">{{ ruleFields.find(field => field.key === item.feature)?.label || item.feature }}</span>
                        <VBtn
                          icon="tabler-trash"
                          variant="text"
                          color="error"
                          size="small"
                          :disabled="formDisabled"
                          :aria-label="`Bỏ dẫn chứng ${index + 1}`"
                          @click="removeEvidence(index)"
                        />
                      </div>
                      <blockquote class="ai-prompt-profile__excerpt text-body-2 rounded pa-3 mb-3">
                        {{ item.excerpt }}
                      </blockquote>
                      <AppTextarea
                        :model-value="item.explanation"
                        label="Diễn giải"
                        maxlength="1000"
                        :rows="2"
                        auto-grow
                        :disabled="formDisabled"
                        :error-messages="getErrors(`evidence_json.${index}.explanation`)"
                        @update:model-value="setEvidenceExplanation(index, $event)"
                      />
                    </div>
                    <p
                      v-if="!props.modelValue.evidence_json?.length"
                      class="text-body-2 text-medium-emphasis mb-0"
                    >
                      Không có dẫn chứng trong bản đang duyệt.
                    </p>
                  </div>
                </VExpansionPanelText>
              </VExpansionPanel>
            </VExpansionPanels>
          </VCol>
          <VCol cols="12">
            <VSwitch
              :model-value="Boolean(props.modelValue.is_enabled)"
              label="Cho phép chọn văn phong khi tạo bài"
              color="primary"
              :disabled="formDisabled"
              :error-messages="getErrors('is_enabled')"
              @update:model-value="setField('is_enabled', Boolean($event))"
            />
            <VCheckbox
              :model-value="Boolean(props.modelValue.setAsDefault)"
              label="Đặt làm văn phong mặc định"
              :disabled="formDisabled || !props.modelValue.is_enabled"
              @update:model-value="setField('setAsDefault', Boolean($event))"
            />
          </VCol>
          <VCol
            cols="12"
            class="d-flex justify-end"
          >
            <VBtn
              type="submit"
              prepend-icon="tabler-device-floppy"
              :loading="props.saving"
              :disabled="!props.canSave || formDisabled || props.conflict"
            >
              {{ props.savedProfile ? 'Lưu thay đổi' : 'Lưu văn phong' }}
            </VBtn>
          </VCol>
        </VRow>
      </VForm>
    </VCardText>
  </VCard>
</template>

<style scoped>
.ai-prompt-profile__excerpt {
  border-inline-start: 3px solid rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.04);
  line-height: 1.7;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
</style>
