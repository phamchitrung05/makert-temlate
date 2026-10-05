<!--
  =====================================================================
  CHỨC NĂNG FILE: Dialog biên tập candidate bằng editor và input của project.
  CÁC HÀM/METHOD TRONG FILE: watcher session, readOnly/canSave (computed), reloadConfirm,
  emit save/close/reload; danh mục/tag thủ công và báo cáo run qua component chung.
  INPUT/OUTPUT CỦA CLASS (tổng thể): detail/loading/error -> field đã sửa.
  Không gắn candidate vào form tạo mới, không tự gọi API hoặc publish.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Candidate contract Laravel. */
import { computed, ref, watch } from 'vue'
import PostEditor from '@/views/apps/blog/post/PostEditor.vue'
import AiManualTaxonomyFields from '@/views/ai/shared/AiManualTaxonomyFields.vue'
import AiPipelineReport from '@/views/ai/shared/AiPipelineReport.vue'

const props = defineProps({
  session: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
  error: { type: String, default: '' },
})

const emit = defineEmits(['save', 'close', 'reload'])
const form = ref({})
const reloadConfirm = ref(false)
const mediaBusy = ref(false)

// Input: state kỹ thuật/Apply. Output: khóa field của bản không thể lưu.
const readOnly = computed(() => props.saving || props.loading || props.session?.status !== 'ready' || Boolean(props.session?.applied_target_id))

// Input: detail mới. Output: bản sao field editor; không mutate session/DTO của parent.
watch(() => props.session, session => {
  const draft = session?.draft ?? {}

  form.value = Object.fromEntries(['title', 'excerpt', 'content_html', 'seo_title', 'seo_description', 'focus_keyword']
    .map(key => [key, draft[key] ?? (key === 'content_html' ? draft.content ?? '' : '')]))
  form.value.category_ids = draft.taxonomy_origin === 'manual' ? [...(draft.category_ids ?? [])] : []
  form.value.tag_ids = draft.taxonomy_origin === 'manual' ? [...(draft.tag_ids ?? [])] : []
}, { immediate: true })

const canSave = computed(() => !props.loading && !props.saving && !mediaBusy.value && props.session?.status === 'ready'
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
          <VBtn
            v-if="props.session?.job_id"
            variant="text"
            :disabled="props.saving"
            class="ms-2"
            @click="reloadConfirm = true"
          >
            Tải bản mới
          </VBtn>
        </VAlert>
        <AiPipelineReport
          v-if="!props.loading"
          :session="props.session"
        />
        <template v-if="props.session?.draft_version && props.session?.draft && !props.loading">
          <AppTextField
            v-model="form.title"
            label="Tiêu đề"
            maxlength="255"
            :disabled="readOnly"
            class="mb-4"
          />
          <AppTextarea
            v-model="form.excerpt"
            label="Tóm tắt"
            rows="3"
            :disabled="readOnly"
            class="mb-4"
          />
          <div class="text-body-2 mb-2">
            Nội dung
          </div>
          <PostEditor
            v-model="form.content_html"
            :disabled="readOnly"
            placeholder="Chỉnh sửa nội dung AI..."
            @media-busy="mediaBusy = $event"
          />
          <AiManualTaxonomyFields
            v-if="props.session.target_type === 'post'"
            v-model:categories="form.category_ids"
            v-model:tags="form.tag_ids"
            :disabled="readOnly"
            class="mt-4"
          />
          <VRow class="mt-4">
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="form.seo_title"
                label="Tiêu đề SEO"
                :disabled="readOnly"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="form.focus_keyword"
                label="Từ khóa chính"
                :disabled="readOnly"
              />
            </VCol>
            <VCol cols="12">
              <AppTextarea
                v-model="form.seo_description"
                label="Mô tả SEO"
                rows="2"
                :disabled="readOnly"
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
  <VDialog
    v-model="reloadConfirm"
    max-width="430"
  >
    <VCard title="Tải bản mới từ server?">
      <VCardText>Nội dung chưa lưu trong editor sẽ được thay bằng bản mới. Bạn có thể hủy để sao chép phần đã sửa trước.</VCardText>
      <VCardActions>
        <VSpacer /><VBtn
          variant="tonal"
          @click="reloadConfirm = false"
        >
          Hủy
        </VBtn><VBtn @click="reloadConfirm = false; emit('reload')">
          Tải bản mới
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
