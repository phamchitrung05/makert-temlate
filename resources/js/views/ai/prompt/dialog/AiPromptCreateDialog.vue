<!--
  =====================================================================
  CHỨC NĂNG FILE: Dialog riêng để nhập văn phong thủ công từ List.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: useAiPromptDialogGuard() quản lý đóng/tải lại.
  INPUT/OUTPUT CỦA CLASS (tổng thể): management state -> form tạo mới và sự kiện;
  luôn giữ form trong loading/exit, chỉ dọn state qua afterLeave.
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
    :model-value="props.state.isOpen && props.state.action?.kind === 'create'"
    max-width="1000"
    scrollable
    :persistent="props.state.saving"
    @update:model-value="!$event && requestClose()"
    @after-leave="emit('afterLeave')"
  >
    <AiPromptProfileDialogContent
      :state="props.state"
      title="Nhập văn phong thủ công"
      subtitle="Nhập hướng dẫn và quy tắc để sử dụng khi tạo bài viết."
      save-label="Tạo văn phong"
      close-label="Đóng nhập văn phong thủ công"
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
