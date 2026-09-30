<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị chi tiết một file trong Media Library
  =====================================================================

  Component trình bày metadata an toàn từ MediaAssetResource. Private asset
  không hiển thị storage path; thao tác download vẫn đi qua page/store và policy
  backend.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - file: computed metadata file của asset
  - formatBytes()/formatDate(): định dạng thông tin chi tiết

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : modelValue và selected asset từ page Media Library
  - OUTPUT: update:modelValue khi đóng dialog
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Laravel API fields preserve snake_case contract. */
import { computed, reactive, watch } from 'vue'

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false,
  },
  asset: {
    type: Object,
    default: null,
  },
  loading: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:modelValue', 'update'])

const file = computed(() => props.asset?.file ?? {})
const form = reactive({ title: '', alt_text: '', visibility: 'public' })

watch(() => props.asset, asset => {
  form.title = asset?.title ?? ''
  form.alt_text = asset?.alt_text ?? ''
  form.visibility = asset?.visibility ?? 'public'
}, { immediate: true })

const submit = () => {
  if (!props.asset)
    return
  emit('update', { id: props.asset.id, data: { title: form.title, alt_text: form.alt_text, visibility: form.visibility } })
}

/**
 * Định dạng byte trong panel chi tiết.
 *
 * Input: kích thước byte hoặc null.
 * Output: chuỗi kích thước dễ đọc.
 */
const formatBytes = bytes => {
  if (!Number.isFinite(Number(bytes)) || Number(bytes) <= 0)
    return '—'

  const units = ['B', 'KB', 'MB', 'GB']
  const exponent = Math.min(Math.floor(Math.log(Number(bytes)) / Math.log(1024)), units.length - 1)

  return `${(Number(bytes) / 1024 ** exponent).toFixed(exponent === 0 ? 0 : 1)} ${units[exponent]}`
}

/**
 * Định dạng timestamp ISO trong chi tiết asset.
 *
 * Input: ISO date string hoặc null.
 * Output: ngày giờ locale vi-VN hoặc dấu gạch ngang.
 */
const formatDate = value => {
  if (!value)
    return '—'

  return new Intl.DateTimeFormat('vi-VN', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}
</script>

<template>
  <VDialog
    max-width="680"
    :model-value="props.modelValue"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <DialogCloseBtn @click="emit('update:modelValue', false)" />

    <VCard v-if="props.asset">
      <VCardItem>
        <VCardTitle>{{ props.asset.title }}</VCardTitle>
        <VCardSubtitle>{{ file.original_name || file.file_name || 'File metadata' }}</VCardSubtitle>
      </VCardItem>

      <VCardText>
        <VForm @submit.prevent="submit">
          <VRow class="mb-3">
            <VCol cols="12">
              <AppTextField
                v-model="form.title"
                label="Title"
                :disabled="props.loading"
                required
              />
            </VCol>
            <VCol cols="12">
              <AppTextarea
                v-model="form.alt_text"
                label="Alt text"
                rows="2"
                :disabled="props.loading"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="form.visibility"
                label="Visibility"
                :items="['public', 'private']"
                :disabled="props.loading || props.asset.kind === 'archive'"
              />
            </VCol>
          </VRow>
        </VForm>
        <VList lines="two">
          <VListItem
            title="Kind"
            :subtitle="props.asset.kind"
          />
          <VListItem
            title="Visibility"
            :subtitle="props.asset.visibility"
          />
          <VListItem
            title="MIME type"
            :subtitle="file.mime_type || '—'"
          />
          <VListItem
            title="Size"
            :subtitle="formatBytes(file.size)"
          />
          <VListItem
            title="Scan status"
            :subtitle="file.scan_status || '—'"
          />
          <VListItem
            title="Conversion status"
            :subtitle="file.conversion_status || '—'"
          />
          <VListItem
            title="Created"
            :subtitle="formatDate(props.asset.created_at)"
          />
          <VListItem
            title="Updated"
            :subtitle="formatDate(props.asset.updated_at)"
          />
        </VList>

        <VAlert
          v-if="file.checksum_sha256"
          color="info"
          variant="tonal"
          class="mt-4"
        >
          <div class="text-caption text-break">
            SHA-256: {{ file.checksum_sha256 }}
          </div>
        </VAlert>
      </VCardText>

      <VCardActions class="justify-end">
        <VBtn
          variant="tonal"
          :disabled="props.loading"
          @click="emit('update:modelValue', false)"
        >
          Close
        </VBtn>
        <VBtn
          color="primary"
          :loading="props.loading"
          @click="submit"
        >
          Save
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
