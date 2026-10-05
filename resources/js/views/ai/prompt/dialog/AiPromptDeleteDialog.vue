<!--
  =====================================================================
  Header/footer cố định qua AppDialogLayout; chỉ content ở giữa được cuộn.
  CHỨC NĂNG FILE: Dialog riêng xác nhận xóa văn phong khỏi danh sách.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: requestClose().
  INPUT/OUTPUT CỦA CLASS (tổng thể): profile/loading/version -> cảnh báo và xác nhận;
  không gọi AI, giữ nội dung khi đóng và khóa mutation đến khi đọc xong.
  =====================================================================
-->
<script setup>
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'

const props = defineProps({ state: { type: Object, required: true } })
const emit = defineEmits(['confirm', 'load', 'close', 'afterLeave'])

// =====================================================================
// Input: đóng/hủy. Output: close nếu chưa gửi mutation.
// =====================================================================
function requestClose() { if (!props.state.saving) emit('close') }
</script>

<template>
  <VDialog
    scrollable
    :model-value="props.state.isOpen && props.state.action?.kind === 'delete'"
    max-width="520"
    :persistent="props.state.saving"
    @update:model-value="!$event && requestClose()"
    @after-leave="emit('afterLeave')"
  >
    <AppDialogLayout
      title="Xóa văn phong"
      :close-disabled="props.state.saving"
      close-label="Đóng xác nhận xóa văn phong"
      @close="requestClose"
    >
      <VCardText>
        <p class="font-weight-medium">
          {{ props.state.profile?.name || props.state.action?.name }} · v{{ props.state.profile?.version }}
        </p>
        <p>
          Văn phong sẽ bị xóa khỏi danh sách và không thể chọn cho bài mới. Các bài đã tạo vẫn giữ văn phong đã dùng.
        </p>
        <div
          v-if="props.state.loading"
          class="d-flex align-center gap-3 mb-4"
          role="status"
          aria-live="polite"
        >
          <VProgressCircular
            indeterminate
            color="primary"
            :size="24"
            :width="3"
            aria-hidden="true"
          />
          Đang kiểm tra văn phong…
        </div>
        <VAlert
          v-if="props.state.error"
          type="error"
          variant="tonal"
        >
          {{ props.state.error }}
          <VBtn
            variant="text"
            :disabled="props.state.loading || props.state.saving || !props.state.isOpen"
            @click="emit('load')"
          >
            Tải phiên bản mới
          </VBtn>
        </VAlert>
      </VCardText>
      <template #footer>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="tonal"
            color="secondary"
            :disabled="props.state.saving"
            @click="requestClose"
          >
            Hủy
          </VBtn>
          <VBtn
            color="error"
            variant="elevated"
            :loading="props.state.saving"
            :disabled="!props.state.isOpen || !props.state.loaded || props.state.loading || props.state.saving || props.state.conflict"
            @click="emit('confirm')"
          >
            Xóa văn phong
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>
