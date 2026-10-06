<!--
  =====================================================================
  CHỨC NĂNG FILE: Form chọn, xem trước và gỡ logo/favicon trong Settings.
  CÁC HÀM/METHOD TRONG FILE: items (computed).
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : site DTO, pending files/previews, errors và disabled từ page.
  - OUTPUT: emit select-file/remove-file; không upload hoặc tự lưu cấu hình.
  =====================================================================
-->
<script setup>
import { computed } from 'vue'

const props = defineProps({
  form: { type: Object, required: true },
  changes: { type: Object, default: () => ({}) },
  previews: { type: Object, default: () => ({}) },
  errors: { type: Object, default: () => ({}) },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['selectFile', 'removeFile'])

const definitions = [
  { kind: 'logo', label: 'Logo website', accept: '.png,.jpg,.jpeg,.webp', hint: 'PNG, JPG hoặc WebP · Tối đa 2 MB · Kích thước tối đa 4096 × 4096 px', icon: 'tabler-photo' },
  { kind: 'favicon', label: 'Favicon', accept: '.png', hint: 'PNG hình vuông · 16–512 px · Tối đa 512 KB', icon: 'tabler-browser' },
]

/** Input: DTO/pending state. Output: metadata preview; giữ ảnh server đến khi người dùng chọn gỡ. */
const items = computed(() => definitions.map(item => ({
  ...item,
  file: props.changes[item.kind] || null,
  removed: Object.hasOwn(props.changes, item.kind) && props.changes[item.kind] === null,
  preview: Object.hasOwn(props.changes, item.kind) ? props.previews[item.kind] : props.form[`${item.kind}_url`],
  configured: Boolean(props.form[`${item.kind}_configured`] || props.changes[item.kind]),
})))
</script>

<template>
  <section
    class="mt-6"
    aria-labelledby="settings-branding-heading"
  >
    <h3
      id="settings-branding-heading"
      class="text-h6 mb-1"
    >
      Thương hiệu website
    </h3>
    <p class="text-body-2 text-medium-emphasis mb-4">
      Logo và biểu tượng tab được dùng cho website và trang quản trị sau khi lưu.
    </p>
    <VRow>
      <VCol
        v-for="item in items"
        :key="item.kind"
        cols="12"
        md="6"
      >
        <div class="settings-branding-card pa-4">
          <div class="text-subtitle-1 mb-3">
            {{ item.label }}
          </div>
          <div class="settings-branding-preview mb-4">
            <img
              v-if="item.preview"
              :src="item.preview"
              :alt="`Xem trước ${item.label}`"
              class="settings-branding-image"
              :class="{ 'settings-branding-image--favicon': item.kind === 'favicon' }"
            >
            <div
              v-else
              class="text-center text-medium-emphasis"
            >
              <VIcon
                :icon="item.icon"
                size="28"
                class="mb-1"
              />
              <div class="text-caption">
                {{ item.removed ? 'Sẽ dùng biểu tượng mặc định' : 'Biểu tượng mặc định' }}
              </div>
            </div>
          </div>
          <VFileInput
            :model-value="item.file"
            :label="`Chọn ${item.label}`"
            :accept="item.accept"
            :disabled="props.disabled"
            :error-messages="props.errors[`${item.kind}_file`]"
            :hint="item.hint"
            persistent-hint
            show-size
            prepend-icon=""
            prepend-inner-icon="tabler-upload"
            variant="outlined"
            @update:model-value="value => emit('selectFile', item.kind, Array.isArray(value) ? value[0] : value)"
          />
          <VBtn
            v-if="item.removed"
            class="mt-3"
            size="small"
            variant="text"
            :disabled="props.disabled"
            @click="emit('selectFile', item.kind, null)"
          >
            Hoàn tác gỡ ảnh
          </VBtn>
          <VBtn
            v-else-if="item.configured"
            class="mt-3"
            size="small"
            color="secondary"
            variant="text"
            prepend-icon="tabler-photo-off"
            :disabled="props.disabled"
            @click="emit('removeFile', item.kind)"
          >
            Dùng biểu tượng mặc định
          </VBtn>
        </div>
      </VCol>
    </VRow>
  </section>
</template>

<style scoped>
.settings-branding-card {
  border: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
  block-size: 100%;
}

.settings-branding-preview {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 12px;
  border-radius: 4px;
  background: rgb(var(--v-theme-background));
  block-size: 104px;
}

.settings-branding-image {
  max-block-size: 80px;
  max-inline-size: 100%;
  object-fit: contain;
}

.settings-branding-image--favicon {
  block-size: 48px;
  inline-size: 48px;
}
</style>
