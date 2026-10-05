<!--
  =====================================================================
  CHỨC NĂNG FILE: Nội dung form dùng chung cho dialog tạo/sửa văn phong.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: saveDisabled (computed); chuyển sự kiện form/save/load/close.
  INPUT/OUTPUT CỦA CLASS (tổng thể): state/saveLabel -> form luôn có mặt, khóa
  khi chưa tải xong, vòng xoay phủ lên form; header/footer cố định qua AppDialogLayout.
  =====================================================================
-->
<script setup>
import { computed } from 'vue'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import AiPromptProfileForm from '../AiPromptProfileForm.vue'

const props = defineProps({
  state: { type: Object, required: true },
  title: { type: String, required: true },
  subtitle: { type: String, required: true },
  saveLabel: { type: String, required: true },
  closeLabel: { type: String, required: true },
})

const emit = defineEmits(['save', 'load', 'close', 'updateForm'])

// Input: trạng thái tải/lưu/xung đột. Output: nút footer theo cùng guard của form.
const saveDisabled = computed(() => !props.state.canSave || !props.state.loaded || !props.state.isOpen
  || props.state.loading || props.state.saving || props.state.uncertain || props.state.conflict)
</script>

<template>
  <AppDialogLayout
    class="ai-prompt-dialog-form"
    :title="props.title"
    :subtitle="props.subtitle"
    :close-label="props.closeLabel"
    :close-disabled="props.state.saving"
    @close="emit('close')"
  >
    <VCardText
      class="pa-0"
      :aria-busy="props.state.loading"
    >
      <VAlert
        v-if="props.state.error"
        type="error"
        variant="tonal"
        class="mx-6 mb-4"
      >
        {{ props.state.error }}
        <VBtn
          v-if="!props.state.uncertain"
          variant="text"
          :disabled="props.state.saving || props.state.loading || !props.state.isOpen"
          @click="emit('load')"
        >
          Tải phiên bản mới
        </VBtn>
      </VAlert>
      <AiPromptProfileForm
        :model-value="props.state.form"
        :busy="props.state.loading || !props.state.loaded || props.state.uncertain || !props.state.isOpen"
        :saving="props.state.saving"
        :can-save="props.state.canSave"
        :saved-profile="props.state.profile"
        :default-profile-id="props.state.defaultId"
        :default-error="props.state.defaultError"
        :conflict="props.state.conflict"
        :errors="props.state.errors"
        :show-header="false"
        :show-actions="false"
        :save-label="props.saveLabel"
        @update:model-value="emit('updateForm', $event)"
        @save="emit('save')"
        @reload-profile="emit('load')"
      />
    </VCardText>
    <template #footer>
      <VCardActions class="justify-end">
        <VBtn
          variant="tonal"
          color="secondary"
          :disabled="props.state.saving"
          @click="emit('close')"
        >
          Hủy
        </VBtn>
        <VBtn
          variant="flat"
          prepend-icon="tabler-device-floppy"
          :loading="props.state.saving"
          :disabled="saveDisabled"
          @click="emit('save')"
        >
          {{ props.saveLabel }}
        </VBtn>
      </VCardActions>
    </template>
    <template #overlay>
      <div
        v-if="props.state.loading"
        class="ai-prompt-dialog-form__loading"
        role="status"
        aria-live="polite"
      >
        <div class="ai-prompt-dialog-form__status">
          <VProgressCircular
            indeterminate
            color="primary"
            :size="28"
            :width="3"
            aria-hidden="true"
          />
          <span>Đang tải dữ liệu văn phong…</span>
        </div>
      </div>
    </template>
  </AppDialogLayout>
</template>

<style scoped>
.ai-prompt-dialog-form {
  position: relative;
}

.ai-prompt-dialog-form__loading {
  position: absolute;
  display: flex;
  align-items: center;
  justify-content: center;
  inset: 0;
  pointer-events: none;
}

.ai-prompt-dialog-form__status {
  display: flex;
  align-items: center;
  padding: 16px 20px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
  background: rgb(var(--v-theme-surface));
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
  gap: 12px;
}
</style>
