<script setup>
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Panel xử lý/settings/preview template từ version draft
 * CÁC HÀM/METHOD: load/process/save/openPreview/attachScreenshot/selectScreen.
 * INPUT: version và disabled. OUTPUT: updated khi backend đổi state; không
 * mutate props. Quyền và ready gate luôn do backend quyết định.
 * =====================================================================
 */
import { computed, ref, watch } from 'vue'
import TemplateScreenList from './TemplateScreenList.vue'
import TemplateScreenPreview from './TemplateScreenPreview.vue'
import { useResourceTemplate } from '@/composables/useResourceTemplate'

const props = defineProps({
  version: { type: Object, default: null },
  resourceVersionId: { type: [Number, String], required: true },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['updated'])
const api = useResourceTemplate(computed(() => props.resourceVersionId))
const { payload, template: templateState, capabilities, status, loading, mutating, error } = api
const entry = ref('')
const screens = ref([])
const selectedSlug = ref('')
const previewGrant = ref('')
const localError = ref('')
const mappingText = ref('')

const selected = computed(() => screens.value.find(screen => screen.slug === selectedSlug.value) || null)
const screenshot = computed(() => selected.value?.preview ?? null)
const previewUrl = computed(() => previewGrant.value && selected.value
  ? `${previewGrant.value}?screen=${encodeURIComponent(selected.value.slug)}` : '')
const statusColor = computed(() => ({ ready: 'success', failed: 'error', processing: 'info', pending: 'warning' }[status.value] || 'secondary'))
const packageScanStatus = computed(() => props.version?.media?.package?.file?.scan_status ?? null)
const canProcess = computed(() => capabilities.value.can_process === true
  && packageScanStatus.value === 'clean' && !props.disabled && !mutating.value
  && !['pending', 'processing', 'ready'].includes(status.value))
const canEdit = computed(() => capabilities.value.can_edit === true && status.value === 'ready'
  && !props.disabled && !mutating.value)
const warnings = computed(() => templateState.value?.warnings ?? [])
const errorMessage = computed(() => localError.value || error.value?.data?.message
  || error.value?.response?._data?.message || error.value?.message || '')

/** INPUT: API detail. OUTPUT: bản sao local settings; emit để cha cập nhật ready capability. */
watch(payload, value => {
  const next = value?.template
  entry.value = next?.entry_screen_slug || ''
  screens.value = (next?.screens || []).map(screen => ({ ...screen, metadata: { ...screen.metadata } }))
  if (!screens.value.some(screen => screen.slug === selectedSlug.value))
    selectedSlug.value = entry.value || screens.value[0]?.slug || ''
  mappingText.value = (selected.value?.metadata?.link_mapping || []).join('\n')
  if (value)
    emit('updated', value)
})

/** INPUT: một action API. OUTPUT: lỗi public cho panel, không nuốt state backend. */
const run = async action => {
  localError.value = ''
  try {
    return await action()
  }
  catch (requestError) {
    localError.value = requestError?.data?.message || requestError?.response?._data?.message
      || requestError?.message || 'Không thể thực hiện yêu cầu template.'

    return null
  }
}

/** INPUT: không có. OUTPUT: detail và polling chỉ khi đang có run. */
const load = () => run(async () => {
  await api.load()
  api.startPolling()
})
/** INPUT: không có. OUTPUT: enqueue/retry rồi poll tới terminal. */
const process = () => run(api.process)
/** INPUT: draft settings. OUTPUT: detail đã persist và capability mới. */
const save = () => run(() => api.update({
  entry_screen_slug: entry.value || null,
  screens: screens.value.map((screen, sortOrder) => ({
    id: screen.id, title: screen.title, sort_order: sortOrder,
    metadata: { link_mapping: screen.metadata?.link_mapping || [] },
  })),
}))
/** INPUT: không có. OUTPUT: iframe URL grant, không tự mở popup bị browser chặn. */
const openPreview = () => run(async () => {
  const result = await api.preview()
  previewGrant.value = result?.url || ''
})
/** INPUT: asset/null. OUTPUT: thay/gỡ screenshot qua backend khi draft. */
const attachScreenshot = asset => {
  if (canEdit.value && selected.value)
    void run(() => api.screenPreview(selected.value.id, asset?.id ?? null))
}
/** INPUT: screen DTO. OUTPUT: đổi screen hiển thị và editor mapping. */
const selectScreen = screen => {
  selectedSlug.value = screen.slug
  mappingText.value = (screen.metadata?.link_mapping || []).join('\n')
}
/** INPUT: textarea mapping. OUTPUT: cập nhật bản sao local, không đổi HTML source. */
const updateMapping = value => {
  mappingText.value = value
  screens.value = screens.value.map(screen => screen.slug === selectedSlug.value
    ? { ...screen, metadata: { ...screen.metadata, link_mapping: value.split('\n').map(line => line.trim()).filter(Boolean) } }
    : screen)
}

watch(() => props.resourceVersionId, () => {
  previewGrant.value = ''
  void load()
}, { immediate: true })
</script>

<template>
  <VCard class="mt-6" title="Template processing">
    <VCardText>
      <VAlert v-if="errorMessage" color="error" variant="tonal" class="mb-4">{{ errorMessage }}</VAlert>
      <div class="d-flex flex-wrap align-center gap-3 mb-4">
        <VChip :color="statusColor" size="small">{{ status || 'Chưa xử lý' }}</VChip>
        <span class="text-body-2">Package scan: <strong>{{ packageScanStatus || 'unknown' }}</strong></span>
        <VSpacer />
        <VBtn variant="tonal" :loading="loading" :disabled="mutating" @click="load">Refresh</VBtn>
        <VBtn :disabled="!canProcess" :loading="mutating" @click="process">
          {{ status === 'failed' ? 'Retry process' : 'Process template' }}
        </VBtn>
        <VBtn variant="tonal" :disabled="status !== 'ready' || !templateState?.preview_available" @click="openPreview">Open preview</VBtn>
      </div>
      <VAlert v-if="templateState?.processing_error" color="error" variant="tonal" class="mb-4">{{ templateState.processing_error }}</VAlert>
      <VAlert v-if="warnings.length" color="warning" variant="tonal" class="mb-4">
        <ul class="mb-0"><li v-for="(warning, index) in warnings" :key="index">{{ typeof warning === 'string' ? warning : warning.message }}</li></ul>
      </VAlert>
      <VRow>
        <VCol cols="12" md="6">
          <VSelect v-model="entry" :items="screens" item-title="title" item-value="slug" label="Entry screen" :disabled="!canEdit" class="mb-4" />
          <TemplateScreenList v-model:entry="entry" :screens="screens" :disabled="!canEdit"
            @update:screens="screens = $event" @select="selectScreen" />
          <VTextarea v-if="selected" :model-value="mappingText" label="Link mapping metadata"
            hint="Mỗi dòng một mapping để đối chiếu; link thực tế dùng HTML trong ZIP." persistent-hint rows="3"
            :disabled="!canEdit" class="mt-4" @update:model-value="updateMapping" />
          <VBtn class="mt-4" :disabled="!canEdit || !screens.length" :loading="mutating" @click="save">Save screen settings</VBtn>
        </VCol>
        <VCol cols="12" md="6">
          <TemplateScreenPreview :screen="selected" :preview-url="previewUrl" :screenshot="screenshot"
            :disabled="!canEdit" @update:screenshot="attachScreenshot" />
        </VCol>
      </VRow>
    </VCardText>
  </VCard>
</template>
