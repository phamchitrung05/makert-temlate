<!--
  =====================================================================
  CHỨC NĂNG FILE: Dialog biên tập candidate bằng editor và input của project.
  CÁC HÀM/METHOD TRONG FILE: watcher session, canSave (computed), emit save/close.
  INPUT/OUTPUT CỦA CLASS (tổng thể): detail/loading/error -> field đã sửa.
  Không gắn candidate vào form tạo mới, không tự gọi API hoặc publish.
  =====================================================================
-->
<script setup>
import { computed, ref, watch } from 'vue'
import PostEditor from '@/views/apps/blog/post/PostEditor.vue'

const props = defineProps({
  session: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
  error: { type: String, default: '' },
})

const emit = defineEmits(['save', 'close'])
const form = ref({})

// Input: detail mới. Output: bản sao field editor; không mutate session/DTO của parent.
watch(() => props.session, session => {
  const draft = session?.draft ?? {}

  form.value = Object.fromEntries(['title', 'excerpt', 'content_html', 'seo_title', 'seo_description', 'focus_keyword']
    .map(key => [key, draft[key] ?? (key === 'content_html' ? draft.content ?? '' : '')]))
}, { immediate: true })

const canSave = computed(() => !props.loading && !props.saving && props.session?.status === 'ready'
  && !props.session.applied_target_id && Boolean(form.value.title?.trim()) && Boolean(form.value.content_html?.trim()))
</script>

<template>
  <VDialog
    :model-value="Boolean(props.session)"
    max-width="1000"
    scrollable
    :persistent="props.saving"
    @update:model-value="!$event && emit('close')"
  >
    <DialogCloseBtn
      :disabled="props.saving"
      aria-label="Đóng chỉnh sửa content AI"
      @click="emit('close')"
    />
    <VCard title="Chỉnh sửa content AI">
      <VCardText>
        <VProgressLinear
          v-if="props.loading"
          indeterminate
          class="mb-4"
        />
        <VAlert
          v-if="props.error"
          type="error"
          variant="tonal"
          class="mb-4"
        >
          {{ props.error }}
        </VAlert>
        <template v-if="props.session?.draft_version && !props.loading">
          <AppTextField
            v-model="form.title"
            label="Tiêu đề"
            maxlength="255"
            :disabled="props.saving"
            class="mb-4"
          />
          <AppTextarea
            v-model="form.excerpt"
            label="Tóm tắt"
            rows="3"
            :disabled="props.saving"
            class="mb-4"
          />
          <div class="text-body-2 mb-2">
            Nội dung
          </div>
          <PostEditor
            v-model="form.content_html"
            :disabled="props.saving"
            placeholder="Chỉnh sửa nội dung AI..."
          />
          <VRow class="mt-4">
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="form.seo_title"
                label="Tiêu đề SEO"
                :disabled="props.saving"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="form.focus_keyword"
                label="Từ khóa chính"
                :disabled="props.saving"
              />
            </VCol>
            <VCol cols="12">
              <AppTextarea
                v-model="form.seo_description"
                label="Mô tả SEO"
                rows="2"
                :disabled="props.saving"
              />
            </VCol>
          </VRow>
        </template>
      </VCardText>
      <VCardActions class="justify-end gap-3 pa-6">
        <VBtn
          variant="tonal"
          color="secondary"
          :disabled="props.saving"
          @click="emit('close')"
        >
          Hủy
        </VBtn>
        <VBtn
          variant="elevated"
          prepend-icon="tabler-device-floppy"
          :disabled="!canSave"
          :loading="props.saving"
          @click="emit('save', { ...form })"
        >
          Lưu nội dung
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
