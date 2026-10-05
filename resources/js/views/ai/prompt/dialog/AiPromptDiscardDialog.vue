<!--
  =====================================================================
  Header/footer cố định qua AppDialogLayout; chỉ content ở giữa được cuộn.
  CHỨC NĂNG FILE: Xác nhận bỏ bản sửa trước khi đóng hoặc tải lại văn phong.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: Không có; chuyển sự kiện confirm/cancel/afterLeave.
  INPUT/OUTPUT CỦA CLASS (tổng thể): trạng thái xác nhận -> thông báo và sự kiện;
  giữ purpose/uncertain trong lúc đóng, không gọi API hoặc thay form.
  =====================================================================
-->
<script setup>
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  purpose: { type: String, default: '' },
  uncertain: { type: Boolean, default: false },
})

const emit = defineEmits(['confirm', 'cancel', 'afterLeave'])
</script>

<template>
  <VDialog
    scrollable
    :model-value="props.modelValue"
    max-width="440"
    @update:model-value="!$event && emit('cancel')"
    @after-leave="emit('afterLeave')"
  >
    <AppDialogLayout
      title="Bỏ bản đang sửa?"
      @close="emit('cancel')"
    >
      <VCardText>
        {{ props.uncertain ? 'Lần tạo mẫu chưa rõ kết quả. Đóng để kiểm tra danh sách trước khi tạo lại.' : 'Các thay đổi chưa lưu sẽ bị bỏ. Bạn có thể hủy để tiếp tục chỉnh sửa.' }}
      </VCardText>
      <template #footer>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="tonal"
            @click="emit('cancel')"
          >
            Hủy
          </VBtn>
          <VBtn
            variant="flat"
            color="warning"
            @click="emit('confirm')"
          >
            {{ props.purpose === 'load' ? 'Tải bản mới' : 'Đóng' }}
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>
