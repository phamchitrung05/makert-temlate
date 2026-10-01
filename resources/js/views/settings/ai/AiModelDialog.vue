<script setup>
/* eslint-disable camelcase -- Model payload uses Laravel field names. */
import { computed, ref, watch } from 'vue'

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Dialog khai báo model và capability explicit của provider
 * =====================================================================
 * CÁC HÀM/METHOD: resetDraft(), submit(), hasUnknownCapability().
 * INPUT: provider, model catalog hiện tại và capability admin xác nhận.
 * OUTPUT: model payload; remote ID đã tồn tại không thể đổi ở backend.
 * SIDE EFFECT: chỉ đổi state dialog; không gọi endpoint provider.
 * EXCEPTION/TRANSACTION: không mở transaction; lỗi unique/capability từ API.
 * =====================================================================
 */
const props = defineProps({ modelValue: Boolean, provider: { type: Object, default: null }, model: { type: Object, default: null }, saving: Boolean })
const emit = defineEmits(['update:modelValue', 'save'])
const draft = ref({})

const capabilities = [
  { title: 'Tạo nội dung', value: 'text_generation' },
  { title: 'Structured output', value: 'structured_output' },
  { title: 'Tạo ảnh', value: 'image_generation' },
  { title: 'Vision', value: 'vision' },
  { title: 'Embedding', value: 'embedding' },
]

/**
 * =====================================================================
 * CHỨC NĂNG: Đồng bộ model hiện tại vào form capability explicit
 * =====================================================================
 * INPUT: modelValue/model từ parent.
 * OUTPUT: draft model; remote ID bị khóa khi sửa.
 * SIDE EFFECT: reset state dialog; không gọi endpoint provider.
 * EXCEPTION/TRANSACTION: không mở transaction.
 * =====================================================================
 */
function resetDraft() {
  if (!props.modelValue) return
  draft.value = props.model
    ? { remote_model_id: props.model.remote_model_id, label: props.model.label, capabilities: [...(props.model.capabilities ?? [])], is_enabled: props.model.is_enabled, is_available: props.model.is_available }
    : { remote_model_id: '', label: '', capabilities: ['text_generation', 'structured_output'], is_enabled: true, is_available: true }
}

watch(() => [props.modelValue, props.model], resetDraft, { immediate: true, deep: true })

/**
 * =====================================================================
 * CHỨC NĂNG: Cảnh báo model chưa được xác nhận capability
 * =====================================================================
 * INPUT: draft capabilities.
 * OUTPUT: true khi danh sách capability rỗng.
 * SIDE EFFECT: computed chỉ đọc state.
 * EXCEPTION/TRANSACTION: không mở transaction.
 * =====================================================================
 */
const hasUnknownCapability = computed(() => draft.value.capabilities?.length === 0)

/**
 * =====================================================================
 * CHỨC NĂNG: Emit model payload sau khi có capability
 * =====================================================================
 * INPUT: draft model đã chọn.
 * OUTPUT: event save tới settings page.
 * SIDE EFFECT: emit bản copy payload; không gọi API trực tiếp.
 * EXCEPTION/TRANSACTION: model chưa có capability thì không emit.
 * =====================================================================
 */
const submit = () => {
  if (!hasUnknownCapability.value) emit('save', { ...draft.value })
}
</script>

<template>
  <VDialog
    :model-value="modelValue"
    max-width="620"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <VCard>
      <VCardTitle>{{ model ? 'Chỉnh sửa model' : 'Thêm model thủ công' }}</VCardTitle>
      <VCardText>
        <VTextField
          v-model="draft.remote_model_id"
          label="Remote model ID"
          class="mb-4"
          :disabled="!!model"
        />
        <VTextField
          v-model="draft.label"
          label="Tên hiển thị"
          class="mb-4"
        />
        <VSelect
          v-model="draft.capabilities"
          :items="capabilities"
          label="Capability đã xác nhận"
          multiple
          chips
          closable-chips
          class="mb-4"
        />
        <VSwitch
          v-model="draft.is_enabled"
          label="Cho phép chọn"
          color="primary"
        />
        <VAlert
          v-if="hasUnknownCapability"
          type="warning"
          variant="tonal"
        >
          Hãy xác nhận ít nhất một capability trước khi lưu model.
        </VAlert>
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
          :disabled="hasUnknownCapability"
          @click="submit"
        >
          Lưu model
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
