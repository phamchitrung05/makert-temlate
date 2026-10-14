<script setup>
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Preview một screen và fallback screenshot/missing state
 * =====================================================================
 */
const props = defineProps({
  screen: { type: Object, default: null },
  previewUrl: { type: String, default: '' },
  screenshot: { type: Object, default: null },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:screenshot'])
</script>

<template>
  <VCard variant="tonal" class="h-100">
    <VCardItem title="Screen preview" :subtitle="screen?.source_path || 'Chọn một screen'" />
    <VCardText>
      <MediaAssetField
        v-if="props.screen"
        :model-value="props.screenshot"
        field="template_screen.preview"
        :multiple="false"
        visibility="public"
        :disabled="props.disabled"
        label="Screenshot fallback"
        class="mb-4"
        @update:model-value="emit('update:screenshot', $event)"
      />
      <iframe
        v-if="props.previewUrl && props.screen"
        :src="props.previewUrl"
        title="Template screen preview"
        class="w-100 rounded"
        style="height: 420px; border: 0; background: white"
        sandbox="allow-scripts"
        referrerpolicy="no-referrer"
      />
      <div v-else-if="props.screen && props.screenshot?.file?.url">
        <VImg :src="props.screenshot.file.preview_url || props.screenshot.file.url" :alt="props.screen.title" max-height="420" cover rounded />
        <VAlert type="info" variant="tonal" class="mt-3">
          Đang hiển thị screenshot fallback vì live preview chưa sẵn sàng.
        </VAlert>
      </div>
      <VAlert v-else-if="props.screen" type="info" variant="tonal">
        Live preview chưa sẵn sàng và chưa có screenshot fallback.
      </VAlert>
      <VAlert v-else type="secondary" variant="tonal">
        Chọn screen để xem preview.
      </VAlert>
    </VCardText>
  </VCard>
</template>
