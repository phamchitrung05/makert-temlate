<!-- Form field chuẩn Vuetify; input schema/draft/errors, output changeField; không gọi API. -->
<script setup>
defineProps({
  fields: { type: Array, required: true },
  form: { type: Object, required: true },
  errors: { type: Object, default: () => ({}) },
  disabled: Boolean,
})

const emit = defineEmits(['changeField'])
</script>

<template>
  <VRow>
    <VCol
      v-for="field in fields"
      :key="field.key"
      cols="12"
      :sm="field.full ? 12 : 6"
    >
      <AppSelect
        v-if="field.type === 'select'"
        :id="'settings-' + field.key"
        :model-value="form[field.key]"
        :label="field.label"
        :items="field.items"
        :multiple="field.multiple"
        :chips="field.multiple"
        :closable-chips="field.multiple"
        :error-messages="errors[field.key]"
        :hint="field.hint"
        :persistent-hint="Boolean(field.hint)"
        :disabled="disabled || (field.smtp && form.mailer !== 'smtp')"
        @update:model-value="emit('changeField', field.key, $event)"
      />
      <AppTextarea
        v-else-if="field.type === 'textarea'"
        :id="'settings-' + field.key"
        :model-value="form[field.key]"
        :label="field.label"
        :rows="field.rows ?? 3"
        :maxlength="field.max"
        :counter="field.max"
        :error-messages="errors[field.key]"
        :hint="field.hint"
        :persistent-hint="Boolean(field.hint)"
        :disabled="disabled"
        @update:model-value="emit('changeField', field.key, $event)"
      />
      <AppTextField
        v-else
        :id="'settings-' + field.key"
        :model-value="form[field.key]"
        :label="field.label"
        :type="field.type ?? 'text'"
        :min="field.type === 'number' ? 1 : undefined"
        :maxlength="field.max"
        :autocomplete="field.type === 'password' ? 'new-password' : 'off'"
        :error-messages="errors[field.key]"
        :hint="field.hint"
        :persistent-hint="Boolean(field.hint)"
        :disabled="disabled || (field.smtp && form.mailer !== 'smtp')"
        @update:model-value="emit('changeField', field.key, field.type === 'number' && $event !== '' ? Number($event) : $event)"
      />
    </VCol>
  </VRow>
</template>
