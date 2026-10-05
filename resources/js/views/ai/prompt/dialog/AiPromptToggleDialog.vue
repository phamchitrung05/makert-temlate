<!--
  =====================================================================
  Header/footer cố định qua AppDialogLayout; chỉ content ở giữa được cuộn.
  CHỨC NĂNG FILE: Dialog xác nhận bật/tắt văn phong, không chứa form chỉnh sửa.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: requestClose().
  INPUT/OUTPUT CỦA CLASS (tổng thể): profile/loading/version -> xác nhận trạng thái;
  khóa xác nhận đến khi đọc xong và giữ nội dung trong hiệu ứng đóng.
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
    :model-value="props.state.isOpen && props.state.action?.kind === 'toggle'"
    max-width="520"
    :persistent="props.state.saving"
    @update:model-value="!$event && requestClose()"
    @after-leave="emit('afterLeave')"
  >
    <AppDialogLayout
      :title="props.state.profile?.is_enabled ? 'Tắt văn phong' : 'Bật văn phong'"
      :close-disabled="props.state.saving"
      close-label="Đóng xác nhận bật/tắt văn phong"
      @close="requestClose"
    >
      <VCardText>
        <p class="font-weight-medium">
          {{ props.state.profile?.name || props.state.action?.name }} · v{{ props.state.profile?.version }}
        </p>
        <p>
          {{ props.state.profile?.is_enabled ? 'Văn phong sẽ không còn được chọn cho bài mới. Nếu đang là mặc định, thiết lập mặc định cũng được gỡ.' : 'Văn phong sẽ xuất hiện trong danh sách lựa chọn khi tạo bài mới.' }}
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
          Đang kiểm tra trạng thái văn phong…
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
            variant="elevated"
            :color="props.state.profile?.is_enabled ? 'warning' : 'primary'"
            :loading="props.state.saving"
            :disabled="!props.state.isOpen || !props.state.loaded || props.state.loading || props.state.saving || props.state.conflict"
            @click="emit('confirm')"
          >
            {{ props.state.profile?.is_enabled ? 'Tắt văn phong' : 'Bật văn phong' }}
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>
