<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị form upload file cho Media Library admin
  =====================================================================

  Dialog chỉ giữ state form cục bộ và phát payload lên page cha. Page gọi
  Pinia store để upload qua service; component không tự gọi HTTP và không biết
  chi tiết response envelope của Laravel.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - resetForm(): khôi phục form sau khi đóng hoặc upload thành công
  - submit(): validate và emit payload upload
  - watch modelValue/kind: điều chỉnh form theo trạng thái dialog

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : modelValue, loading, progress và error từ page Media Library
  - OUTPUT: update:modelValue và submit payload `{ file, kind, title, alt_text, visibility }`
  =====================================================================
-->
<script setup>
import { computed, reactive, useTemplateRef, watch } from 'vue'

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false,
  },
  loading: {
    type: Boolean,
    default: false,
  },
  progress: {
    type: Number,
    default: 0,
  },
  error: {
    type: String,
    default: '',
  },
})

const emit = defineEmits(['update:modelValue', 'submit'])

const refVForm = useTemplateRef('refVForm')

const kindOptions = [
  { title: 'Image', value: 'image' },
  { title: 'Document', value: 'document' },
  { title: 'Archive', value: 'archive' },
  { title: 'Video', value: 'video' },
]

const visibilityOptions = [
  { title: 'Public', value: 'public' },
  { title: 'Private', value: 'private' },
]

const defaultForm = () => ({
  file: null,
  kind: 'image',
  title: '',
  altText: '',
  visibility: 'public',
})

const form = reactive(defaultForm())

const isArchive = computed(() => form.kind === 'archive')

/**
 * Xóa state form và đưa visibility về giá trị mặc định cho lần upload tiếp theo.
 *
 * Input: không có.
 * Output: form trở về trạng thái rỗng.
 * Side effect: thay đổi state reactive cục bộ của dialog.
 */
const resetForm = () => {
  Object.assign(form, defaultForm())
  refVForm.value?.resetValidation()
}

/**
 * Validate form upload và phát payload cho page cha.
 *
 * Input: File, kind, title, alt_text và visibility người dùng nhập.
 * Output: emit `submit` nếu form hợp lệ.
 * Side effect: chạy validation Vuetify; không gọi HTTP.
 */
const submit = async () => {
  const validation = await refVForm.value?.validate()

  if (!validation?.valid)
    return

  emit('submit', { ...form, 'alt_text': form.altText })
}

/**
 * Archive luôn private theo security contract backend.
 *
 * Input: kind hiện tại của form.
 * Output: không trả dữ liệu; visibility được khóa về private cho archive.
 */
watch(() => form.kind, kind => {
  if (kind === 'archive')
    form.visibility = 'private'
})

watch(() => props.modelValue, isVisible => {
  if (!isVisible)
    resetForm()
})
</script>

<template>
  <VDialog
    max-width="720"
    :model-value="props.modelValue"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <DialogCloseBtn @click="emit('update:modelValue', false)" />

    <VCard>
      <VCardItem>
        <VCardTitle>Upload file</VCardTitle>
        <VCardSubtitle>Thêm file vào Media Library dùng chung.</VCardSubtitle>
      </VCardItem>

      <VCardText>
        <VAlert
          v-if="props.error"
          color="error"
          variant="tonal"
          class="mb-5"
        >
          {{ props.error }}
        </VAlert>

        <VProgressLinear
          v-if="props.loading"
          :model-value="props.progress"
          color="primary"
          class="mb-5"
        />

        <VForm
          ref="refVForm"
          @submit.prevent="submit"
        >
          <VRow>
            <VCol cols="12">
              <VFileInput
                v-model="form.file"
                label="File"
                prepend-icon="tabler-paperclip"
                show-size
                :rules="[requiredValidator]"
                :disabled="props.loading"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppSelect
                v-model="form.kind"
                label="Kind"
                :items="kindOptions"
                :disabled="props.loading"
                :rules="[requiredValidator]"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppSelect
                v-model="form.visibility"
                label="Visibility"
                :items="visibilityOptions"
                :disabled="props.loading || isArchive"
                :rules="[requiredValidator]"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="form.title"
                label="Title"
                placeholder="Ví dụ: Dashboard hero image"
                :disabled="props.loading"
                :rules="[requiredValidator]"
              />
            </VCol>
            <VCol cols="12">
              <AppTextarea
                v-model="form.altText"
                label="Alt text"
                rows="2"
                :disabled="props.loading"
              />
            </VCol>
          </VRow>
        </VForm>
      </VCardText>

      <VCardActions class="justify-end">
        <VBtn
          variant="tonal"
          :disabled="props.loading"
          @click="emit('update:modelValue', false)"
        >
          Cancel
        </VBtn>
        <VBtn
          :loading="props.loading"
          @click="submit"
        >
          Upload
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
