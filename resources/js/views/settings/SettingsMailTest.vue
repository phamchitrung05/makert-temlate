<!-- Gửi email thử theo nút bấm; input trạng thái đã lưu, output địa chỉ nhận. -->
<script setup>
import { getAlertColor } from '@/config/alertColors'
import { shallowRef } from 'vue'

defineProps({
  disabled: Boolean,
  loading: Boolean,
  notice: { type: Object, default: null },
})

const emit = defineEmits(['send'])
const recipient = shallowRef('')
</script>

<template>
  <VDivider class="my-6" />
  <h3 class="text-h6 mb-2">
    Kiểm tra cấu hình đã lưu
  </h3>
  <p class="text-body-2 text-medium-emphasis mb-4">
    Lưu thay đổi trước khi thử. Khi dùng SMTP, thao tác này sẽ gửi một email đến địa chỉ bạn nhập.
  </p>
  <VAlert
    v-if="notice"
    :color="getAlertColor(notice.type)"
    variant="tonal"
    class="mb-4"
    role="status"
  >
    {{ notice.message }}
  </VAlert>
  <div class="d-flex flex-wrap align-start gap-3">
    <AppTextField
      id="settings-test-recipient"
      v-model="recipient"
      label="Email nhận thử"
      type="email"
      autocomplete="email"
      class="flex-grow-1"
      :disabled="loading || disabled"
    />
    <VBtn
      class="align-self-end mb-1"
      variant="tonal"
      prepend-icon="tabler-send"
      :disabled="disabled || !recipient.trim()"
      :loading="loading"
      @click="emit('send', recipient.trim())"
    >
      Gửi email thử
    </VBtn>
  </div>
</template>
