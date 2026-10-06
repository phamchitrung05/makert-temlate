<!-- Registry locale thật; input draft/options/errors, output field change, không tự tạo locale. -->
<script setup>
import { getAlertColor } from '@/config/alertColors'

defineProps({
  form: { type: Object, required: true },
  locales: { type: Array, required: true },
  errors: { type: Object, default: () => ({}) },
  disabled: Boolean,
})

const emit = defineEmits(['changeField'])
</script>

<template>
  <VAlert
    variant="tonal"
    :color="getAlertColor('info')"
    class="mb-6"
  >
    Danh sách chỉ gồm ngôn ngữ đã có bản dịch. Nội dung các trang quản trị tùy chỉnh hiện vẫn dùng tiếng Việt.
  </VAlert>
  <AppSelect
    id="settings-default-locale"
    :model-value="form.default_locale"
    :items="locales.filter(locale => form.enabled_locales.includes(locale.value))"
    label="Ngôn ngữ mặc định"
    :error-messages="errors.default_locale"
    :disabled="disabled"
    class="mb-5"
    hint="Dùng khi chưa chọn ngôn ngữ hoặc ngôn ngữ đang dùng bị tắt."
    persistent-hint
    @update:model-value="emit('changeField', 'default_locale', $event)"
  />
  <AppSelect
    id="settings-enabled-locales"
    :model-value="form.enabled_locales"
    :items="locales"
    label="Ngôn ngữ được bật"
    multiple
    chips
    :error-messages="errors.enabled_locales"
    :disabled="disabled"
    @update:model-value="emit('changeField', 'enabled_locales', $event)"
  />
  <VList
    class="mt-5"
    lines="two"
  >
    <VListItem
      v-for="locale in locales"
      :key="locale.value"
      :title="locale.title"
      :subtitle="locale.value + (locale.rtl ? ' · Viết từ phải sang trái' : ' · Viết từ trái sang phải')"
    >
      <template #append>
        <VChip
          size="small"
          :color="form.enabled_locales.includes(locale.value) ? 'success' : 'secondary'"
        >
          {{ form.enabled_locales.includes(locale.value) ? 'Đã bật' : 'Đã tắt' }}
        </VChip>
      </template>
    </VListItem>
  </VList>
</template>
