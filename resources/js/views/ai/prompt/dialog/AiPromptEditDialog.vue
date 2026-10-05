<!--
  =====================================================================
  CHỨC NĂNG FILE: Dialog riêng để chỉnh sửa văn phong đã lưu.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: useAiPromptDialogGuard() quản lý đóng/tải bản mới.
  INPUT/OUTPUT CỦA CLASS (tổng thể): management state -> form sửa/version và sự kiện;
  bảo vệ bản chưa lưu và giữ nội dung đến afterLeave, không tự mutation.
  =====================================================================
-->
<script setup>
import { useAiPromptDialogGuard } from '@/composables/ai/prompt/useAiPromptDialogGuard'
import AiPromptProfileDialogContent from './AiPromptProfileDialogContent.vue'
import AiPromptDiscardDialog from './AiPromptDiscardDialog.vue'

const props = defineProps({ state: { type: Object, required: true } })
const emit = defineEmits(['save', 'load', 'close', 'updateForm', 'afterLeave'])
const { discardOpen, discardAction, discardUncertain, requestClose, requestReload, acceptDiscard, cancelDiscard, finishDiscard } = useAiPromptDialogGuard(() => props.state, emit)
</script>

<template>
  <VDialog
    :model-value="props.state.isOpen && props.state.action?.kind === 'edit'"
    max-width="1000"
    scrollable
    :persistent="props.state.saving"
    @update:model-value="!$event && requestClose()"
    @after-leave="emit('afterLeave')"
  >
    <AiPromptProfileDialogContent
      :state="props.state"
      title="Chỉnh sửa văn phong"
      subtitle="Cập nhật nội dung và tùy chọn của văn phong đã lưu."
      save-label="Lưu thay đổi"
      close-label="Đóng chỉnh sửa văn phong"
      @close="requestClose"
      @update-form="emit('updateForm', $event)"
      @save="emit('save')"
      @load="requestReload"
    />
  </VDialog>
  <AiPromptDiscardDialog
    :model-value="discardOpen"
    :purpose="discardAction"
    :uncertain="discardUncertain"
    @confirm="acceptDiscard"
    @cancel="cancelDiscard"
    @after-leave="finishDiscard"
  />
</template>
