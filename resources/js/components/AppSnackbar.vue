<!--
  =====================================================================
  CHỨC NĂNG FILE: Snackbar bọc VSnackbar với default chuẩn của ứng dụng.
  =====================================================================
  Dùng với useSnackbar(): `<AppSnackbar v-model="snackbar" />` thay cho
  VSnackbar template rải rác. Default location="bottom end", timeout 3200ms;
  page cần khác truyền props override. Shape state: {visible,message,color}.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - computed proxy: 2 chiều v-model.visible giữa state cha và VSnackbar.
  - props/emits: modelValue {visible,message,color}; update:modelValue.
  =====================================================================
-->
<script setup>
const props = defineProps({
  modelValue: {
    type: Object,
    default: () => ({ visible: false, message: '', color: 'success' }),
  },
  timeout: { type: Number, default: 3200 },
  location: { type: String, default: 'bottom end' },
})

const emit = defineEmits(['update:modelValue'])

const visible = computed({
  get: () => props.modelValue?.visible ?? false,
  set: value => emit('update:modelValue', { ...props.modelValue, visible: value }),
})
</script>

<template>
  <VSnackbar
    v-model="visible"
    :color="props.modelValue.color"
    :location="location"
    :timeout="timeout"
  >
    {{ props.modelValue.message }}
  </VSnackbar>
</template>
