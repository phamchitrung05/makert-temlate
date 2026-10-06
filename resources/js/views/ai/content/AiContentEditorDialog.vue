<!--
  =====================================================================
  Header/footer cố định qua AppDialogLayout; chỉ content ở giữa được cuộn.
  CHỨC NĂNG FILE: Dialog biên tập candidate bằng editor và input của project.
  CÁC HÀM/METHOD TRONG FILE: watcher session/loading/error, finishClose(), readOnly/canSave (computed), reloadConfirm,
  emit save/close/reload; danh mục/tag thủ công và báo cáo run qua component chung.
  INPUT/OUTPUT CỦA CLASS (tổng thể): detail/loading/error -> form đầy đủ, khóa khi tải và field đã sửa.
  Giữ form/loading đến after-leave để không co dialog trong hiệu ứng đóng.
  Không gắn candidate vào form tạo mới, không tự gọi API hoặc publish.
  =====================================================================
-->
<script setup>
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
/* eslint-disable camelcase -- Candidate contract Laravel. */
import { computed, ref, shallowRef, watch } from 'vue'
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
const display = shallowRef({ session: null, loading: false, error: '' })
const reloadConfirm = ref(false)
const mediaBusy = ref(false)
const editorDialogOpen = ref(false)

// Input: state kỹ thuật/Apply. Output: khóa field của bản không thể lưu.
const readOnly = computed(() => props.saving || props.loading || !props.session?.draft_version || !props.session?.draft
  || props.session.status !== 'ready' || Boolean(props.session.applied_target_id) || props.session.review?.status === 'rejected')

// Input: detail/loading/error. Output: snapshot hiển thị giữ đến hết hiệu ứng đóng;
// chỉ chép form khi nhận session mới, không ghi đè nội dung nhập khi save/error đổi.
watch(() => [props.session, props.loading, props.error], ([session, loading, error]) => {
  if (!session) {
    reloadConfirm.value = false

    return
  }
  const previous = display.value.session

  display.value = { session, loading, error }
  if (session === previous) return
  const draft = session.draft ?? {}

  form.value = Object.fromEntries(['title', 'excerpt', 'content_html', 'seo_title', 'seo_description', 'focus_keyword']
    .map(key => [key, draft[key] ?? (key === 'content_html' ? draft.content ?? '' : '')]))
  form.value.category_ids = draft.taxonomy_origin === 'manual' ? [...(draft.category_ids ?? [])] : []
  form.value.tag_ids = draft.taxonomy_origin === 'manual' ? [...(draft.tag_ids ?? [])] : []
}, { immediate: true })

const canSave = computed(() => !readOnly.value && !mediaBusy.value
  && Boolean(form.value.title?.trim()) && Boolean(form.value.content_html?.trim()))

/**
 * =====================================================================
 * Input: VDialog after-leave. Output: dọn snapshot/form sau khi đóng hoàn toàn;
 * bỏ qua event cũ nếu dialog đã mở lại. Không gọi API hoặc mutate DTO.
 * =====================================================================
 */
function finishClose() {
  if (props.session) return
  display.value = { session: null, loading: false, error: '' }
  form.value = {}
  mediaBusy.value = false
  editorDialogOpen.value = false
}
</script>

<template>
  <VDialog
    :model-value="Boolean(props.session)"
    max-width="1000"
    scrollable
    :persistent="props.saving"
    :retain-focus="!editorDialogOpen"
    @update:model-value="!$event && emit('close')"
    @after-leave="finishClose"
  >
    <AppDialogLayout
      title="Chỉnh sửa content AI"
      :close-disabled="props.saving"
      close-label="Đóng chỉnh sửa content AI"
      @close="emit('close')"
    >
      <VCardText :aria-busy="display.loading">
        <VAlert
          v-if="display.error"
          type="error"
          variant="tonal"
          class="mb-4"
        >
          {{ display.error }}
          <VBtn
            v-if="display.session?.job_id"
            variant="text"
            :disabled="!props.session || props.saving || props.loading"
            class="ms-2"
            @click="reloadConfirm = true"
          >
            Tải bản mới
          </VBtn>
        </VAlert>
        <AiPipelineReport
          v-if="display.session?.status && !display.loading"
          :session="display.session"
        />
        <AppTextField
          v-model="form.title"
          label="Tiêu đề"
          placeholder="Nhập tiêu đề nội dung"
          maxlength="255"
          :disabled="readOnly"
          class="mb-4"
        />
        <AppTextarea
          v-model="form.excerpt"
          label="Tóm tắt"
          placeholder="Nhập phần tóm tắt nội dung"
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
          @editor-dialog="editorDialogOpen = $event"
        />
        <AiManualTaxonomyFields
          v-if="display.session?.target_type === 'post'"
          v-model:categories="form.category_ids"
          v-model:tags="form.tag_ids"
          :disabled="readOnly"
          :active="Boolean(props.session?.draft_version) && !props.loading"
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
              placeholder="Nhập tiêu đề hiển thị trên công cụ tìm kiếm"
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
              placeholder="Nhập từ khóa chính của nội dung"
              :disabled="readOnly"
            />
          </VCol>
          <VCol cols="12">
            <AppTextarea
              v-model="form.seo_description"
              label="Mô tả SEO"
              placeholder="Nhập mô tả ngắn cho kết quả tìm kiếm"
              rows="2"
              :disabled="readOnly"
            />
          </VCol>
        </VRow>
      </VCardText>
      <template #footer>
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
            @click="canSave && emit('save', { ...form })"
          >
            Lưu nội dung
          </VBtn>
        </VCardActions>
      </template>
      <template #overlay>
        <div
          v-if="display.loading"
          class="ai-content-editor__loading"
          role="status"
          aria-live="polite"
        >
          <div class="ai-content-editor__loading-status">
            <VProgressCircular
              indeterminate
              color="primary"
              :size="28"
              :width="3"
              aria-hidden="true"
            />
            <span>Đang tải nội dung AI…</span>
          </div>
        </div>
      </template>
    </AppDialogLayout>
  </VDialog>
  <VDialog
    v-model="reloadConfirm"
    scrollable
    max-width="430"
  >
    <AppDialogLayout
      title="Tải bản mới từ server?"
      @close="reloadConfirm = false"
    >
      <VCardText>Nội dung chưa lưu trong editor sẽ được thay bằng bản mới. Bạn có thể hủy để sao chép phần đã sửa trước.</VCardText>
      <template #footer>
        <VCardActions>
          <VSpacer /><VBtn
            variant="tonal"
            @click="reloadConfirm = false"
          >
            Hủy
          </VBtn><VBtn
            variant="flat"
            @click="reloadConfirm = false; emit('reload')"
          >
            Tải bản mới
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>

<style scoped>
.ai-content-editor__loading {
  position: absolute;
  z-index: 3;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  inset: 0;
  pointer-events: none;
}

.ai-content-editor__loading-status {
  display: flex;
  align-items: center;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
  background: rgb(var(--v-theme-surface));
  box-shadow: 0 4px 16px rgba(0, 0, 0, 8%);
  gap: 12px;
  padding-block: 16px;
  padding-inline: 20px;
}
</style>
