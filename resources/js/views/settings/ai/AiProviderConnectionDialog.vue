<!--
  =====================================================================
  CHỨC NĂNG FILE: Dialog tạo/sửa connection AI với API key write-only
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: resetDraft(), selectedPreset/timeoutValid (computed), submit().
  INPUT/OUTPUT CỦA CLASS (tổng thể): metadata/config -> draft và event lưu provider.
  INPUT: provider hiện tại, preset driver, timeout 5–600 giây và key mới tùy chọn.
  OUTPUT: event save chỉ chứa key khi admin nhập key mới.
  SIDE EFFECT: chỉ đổi state dialog; không lưu key vào localStorage/API trực tiếp.
  EXCEPTION/TRANSACTION: không mở transaction; validation server xử lý payload.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Provider payload uses Laravel field names. */
import { computed, ref, watch } from 'vue'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  provider: { type: Object, default: null },
  presets: { type: Array, default: () => [] },
  saving: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'save'])
const draft = ref({})

/**
 * =====================================================================
 * CHỨC NĂNG: Khởi tạo form connection và xóa key khi dialog đóng
 * =====================================================================
 * INPUT: provider/presets/modelValue từ props.
 * OUTPUT: draft metadata; api_key chỉ tồn tại khi admin đang nhập.
 * SIDE EFFECT: reset state cục bộ; không ghi localStorage hoặc gọi API.
 * EXCEPTION/TRANSACTION: không mở transaction.
 * =====================================================================
 */
function resetDraft() {
  if (!props.modelValue) {
    draft.value.api_key = ''
    
    return
  }
  const driver = props.provider?.driver ?? props.presets[0]?.key ?? 'openai-compatible'
  const preset = props.presets.find(item => item.key === driver)

  draft.value = props.provider
    ? { name: props.provider.name, driver, base_url: props.provider.base_url, discovery_mode: props.provider.discovery_mode, request_timeout: props.provider.request_timeout ?? 120, is_active: props.provider.is_active, api_key: '' }
    : { name: '', driver, base_url: preset?.base_url ?? '', discovery_mode: preset?.discovery_mode ?? 'models_endpoint', request_timeout: preset?.request_timeout ?? 120, is_active: true, api_key: '' }
}

watch(() => [props.modelValue, props.provider, props.presets], resetDraft, { immediate: true, deep: true })

const selectedPreset = computed(() => props.presets.find(item => item.key === draft.value.driver))

const timeoutValid = computed(() => Number.isInteger(Number(draft.value.request_timeout))
  && Number(draft.value.request_timeout) >= 5 && Number(draft.value.request_timeout) <= 600)

/**
 * =====================================================================
 * CHỨC NĂNG: Đồng bộ endpoint hiển thị khi admin đổi preset provider.
 * =====================================================================
 * INPUT: driver được chọn trên form mới.
 * OUTPUT: official endpoint hoặc field gateway rỗng để admin nhập.
 * SIDE EFFECT: chỉ thay state dialog, không đổi connection đã lưu.
 * EXCEPTION/TRANSACTION: Không có.
 * =====================================================================
 */
watch(() => draft.value.driver, (driver, previous) => {
  if (props.provider || !previous || driver === previous) return
  draft.value.base_url = props.presets.find(item => item.key === driver)?.base_url ?? ''
})

/**
 * =====================================================================
 * CHỨC NĂNG: Emit payload connection đã loại key rỗng
 * =====================================================================
 * INPUT: draft metadata, timeout nguyên 5–600 giây và optional key mới.
 * OUTPUT: event save với timeout dạng số; không emit khi đang lưu hoặc timeout sai.
 * SIDE EFFECT: emit về page; không gọi API trực tiếp.
 * EXCEPTION/TRANSACTION: không mở transaction; validation server xử lý tiếp.
 * =====================================================================
 */
function submit() {
  if (props.saving || !timeoutValid.value) return
  const payload = { ...draft.value, request_timeout: Number(draft.value.request_timeout) }
  if (!payload.api_key) delete payload.api_key
  emit('save', payload)
}
</script>

<template>
  <VDialog
    :model-value="modelValue"
    max-width="620"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <VCard>
      <VCardTitle>{{ provider ? 'Chỉnh sửa provider AI' : 'Thêm provider AI' }}</VCardTitle>
      <VCardText>
        <VTextField
          v-model="draft.name"
          label="Tên hiển thị"
          class="mb-4"
        />
        <VSelect
          v-model="draft.driver"
          :items="presets"
          item-title="label"
          item-value="key"
          label="Provider / giao thức"
          class="mb-4"
          :disabled="!!provider"
        />
        <VTextField
          v-model="draft.base_url"
          label="Endpoint HTTPS"
          :disabled="selectedPreset?.kind === 'official'"
          hint="Gateway OpenAI-compatible cần endpoint /v1"
          persistent-hint
          class="mb-4"
        />
        <VTextField
          v-model="draft.api_key"
          type="password"
          autocomplete="new-password"
          :label="provider?.has_api_key ? 'API key mới (để trống để giữ key hiện tại)' : 'API key'"
          class="mb-4"
        />
        <VTextField
          v-model="draft.request_timeout"
          type="number"
          label="Thời gian chờ (giây)"
          min="5"
          max="600"
          step="1"
          prepend-inner-icon="tabler-clock"
          hint="5–600 giây cho mỗi request. Mất kết nối hoặc hết thời gian chờ: thử lại thủ công."
          persistent-hint
          :error-messages="timeoutValid ? [] : ['Nhập số nguyên từ 5 đến 600 giây.']"
          class="mb-4"
        />
        <VSelect
          v-model="draft.discovery_mode"
          :items="[{ title: 'Đồng bộ từ /models', value: 'models_endpoint' }, { title: 'Nhập model thủ công', value: 'manual' }]"
          label="Nguồn danh sách model"
        />
        <VSwitch
          v-model="draft.is_active"
          label="Cho phép sử dụng"
          color="primary"
          hide-details
        />
      </VCardText>
      <VCardActions class="justify-end">
        <VBtn
          variant="text"
          @click="emit('update:modelValue', false)"
        >
          Hủy
        </VBtn>
        <VBtn
          color="primary"
          :loading="saving"
          :disabled="saving || !timeoutValid"
          @click="submit"
        >
          Lưu connection
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
