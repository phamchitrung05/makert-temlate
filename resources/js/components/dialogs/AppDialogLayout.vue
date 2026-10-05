<!--
  =====================================================================
  CHỨC NĂNG FILE: Khung ba vùng bắt buộc cho dialog của ứng dụng.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: requestClose() phát yêu cầu đóng nếu không bị khóa.
  INPUT/OUTPUT CỦA CLASS (tổng thể): title/subtitle, header/content/footer slots
  -> header và footer cố định; chỉ body được cuộn trong chiều cao dialog.
  Nút X dùng DialogCloseBtn nổi ngoài góc card; attrs được chuyển vào VCard.
  bodyScroll=false dành cho picker chia panel, scrollbar vẫn nằm trong body.
  OUTPUT: close để caller xử lý trạng thái, cảnh báo bản chưa lưu và lifecycle.
  SIDE EFFECT: không tự đóng, reset form hoặc gọi API.
  =====================================================================
-->
<script setup>
import DialogCloseBtn from '@/@core/components/DialogCloseBtn.vue'

const props = defineProps({
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
  closeLabel: { type: String, default: 'Đóng dialog' },
  closeDisabled: { type: Boolean, default: false },
  bodyScroll: { type: Boolean, default: true },
})

const emit = defineEmits(['close'])

defineOptions({ inheritAttrs: false })

/**
 * =====================================================================
 * CHỨC NĂNG: Chuyển thao tác đóng về dialog nghiệp vụ.
 * INPUT: click nút đóng và closeDisabled từ caller.
 * OUTPUT: event close khi caller cho phép; không thay modelValue.
 * =====================================================================
 */
function requestClose() {
  if (!props.closeDisabled) emit('close')
}
</script>

<template>
  <DialogCloseBtn
    :aria-label="props.closeLabel"
    :disabled="props.closeDisabled"
    @click="requestClose"
  />
  <VCard
    v-bind="$attrs"
    class="app-dialog-layout"
  >
    <header class="app-dialog-layout__header">
      <div class="app-dialog-layout__heading">
        <slot name="header">
          <VCardItem
            :title="props.title"
            :subtitle="props.subtitle || undefined"
          />
        </slot>
      </div>
    </header>
    <VDivider />
    <div
      class="app-dialog-layout__body"
      :class="{ 'app-dialog-layout__body--panels': !props.bodyScroll }"
    >
      <slot />
    </div>
    <VDivider />
    <footer class="app-dialog-layout__footer">
      <slot name="footer">
        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            :disabled="props.closeDisabled"
            @click="requestClose"
          >
            Đóng
          </VBtn>
        </VCardActions>
      </slot>
    </footer>
    <slot name="overlay" />
  </VCard>
</template>

<style scoped>
.app-dialog-layout {
  position: relative;
  display: flex;
  flex-direction: column;
  max-block-size: 100%;
  min-block-size: 0;
  overflow: hidden !important;
}

.app-dialog-layout__header,
.app-dialog-layout__footer {
  flex: 0 0 auto;
  min-inline-size: 0;
}

.app-dialog-layout__header {
  display: flex;
  align-items: flex-start;
}

.app-dialog-layout__heading {
  flex: 1 1 auto;
  min-inline-size: 0;
}

.app-dialog-layout__heading :deep(.v-card-title),
.app-dialog-layout__heading :deep(.v-card-subtitle) {
  overflow-wrap: anywhere;
  white-space: normal;
}

.app-dialog-layout__body {
  flex: 1 1 auto;
  min-block-size: 0;
  min-inline-size: 0;
  overflow: hidden auto;
  overscroll-behavior: contain;
}

.app-dialog-layout__body > :deep(.v-card-text) {
  overflow: visible;
}

.app-dialog-layout__body--panels {
  flex: 1 1 0%;
  overflow: hidden;
}

.app-dialog-layout__footer :deep(.v-card-actions) {
  flex-wrap: wrap;
  gap: 12px;
  padding: 16px 24px;
}

.app-dialog-layout__footer :deep(.v-btn) {
  max-inline-size: 100%;
}

.app-dialog-layout__footer :deep(.v-btn__content) {
  white-space: normal;
}
</style>
