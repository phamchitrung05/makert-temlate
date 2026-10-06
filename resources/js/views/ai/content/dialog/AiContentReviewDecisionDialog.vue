<!--
  =====================================================================
  CHỨC NĂNG FILE: Xác nhận duyệt hoặc từ chối, giữ lý do/field khi API lỗi.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: watcher identity, locked/legacyTaxonomy/canSubmit
  (computed), confirm(), finishClose().
  INPUT/OUTPUT CỦA CLASS (tổng thể): kind/detail/busy/error -> options allowlist.
  SIDE EFFECT: emit confirm/reload/close, không tự POST; reset sau after-leave.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Contract duyệt Laravel. */
import { computed, ref, shallowRef, watch } from 'vue'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import AiManualTaxonomyFields from '@/views/ai/shared/AiManualTaxonomyFields.vue'

const props = defineProps({
  kind: { type: String, default: null },
  detail: { type: Object, default: null },
  busy: Boolean,
  loading: Boolean,
  blocked: Boolean,
  error: { type: String, default: '' },
})

const emit = defineEmits(['confirm', 'close', 'reload'])
const displayKind = shallowRef(null)
const reason = ref('')
const fields = ref([])
const categoryIds = ref([])
const tagIds = ref([])
const taxonomyConfirmed = ref(false)

const fieldOptions = [
  { title: 'Tiêu đề', value: 'title' },
  { title: 'Tóm tắt', value: 'excerpt' },
  { title: 'Nội dung', value: 'content' },
  { title: 'SEO', value: 'seo' },
  { title: 'Danh mục và tag thủ công', value: 'taxonomy' },
]

const locked = computed(() => props.busy || props.loading || props.blocked)
const legacyTaxonomy = computed(() => props.detail?.draft?.taxonomy_origin !== 'manual')

const canSubmit = computed(() => ['approve', 'reject'].includes(props.kind) && !locked.value && reason.value.trim().length <= 2000
  && (props.kind === 'reject' ? Boolean(reason.value.trim()) : props.detail?.can_approve !== false && fields.value.includes('title')
    && (!fields.value.includes('taxonomy') || !legacyTaxonomy.value || taxonomyConfirmed.value)))

// Input: mở quyết định mới. Output: khởi tạo form một lần; GET lại cùng bài giữ bản nhập.
watch(() => [props.kind, props.detail?.job_id], ([kind], [previousKind] = []) => {
  if (!kind) return
  displayKind.value = kind
  if (kind === previousKind) return
  reason.value = ''
  fields.value = ['title', 'excerpt', 'content', 'seo', 'taxonomy', ...(props.detail?.has_thumbnail ? ['thumbnail'] : [])]
  categoryIds.value = props.detail?.draft?.taxonomy_origin === 'manual' ? [...(props.detail.draft.category_ids ?? [])] : []
  tagIds.value = props.detail?.draft?.taxonomy_origin === 'manual' ? [...(props.detail.draft.tag_ids ?? [])] : []
  taxonomyConfirmed.value = false
}, { immediate: true })

/** Input: submit form/footer. Output: một event với options đã xác nhận, không nhận actor/status. */
function confirm() {
  if (!canSubmit.value) return
  emit('confirm', props.kind === 'reject' ? { reason: reason.value.trim() } : {
    reason: reason.value.trim() || null, fields: [...fields.value],
    ...(fields.value.includes('taxonomy') ? { category_ids: [...categoryIds.value], tag_ids: [...tagIds.value] } : {}),
  })
}

/** Input: after-leave. Output: dọn snapshot sau đóng, bỏ qua callback cũ nếu đã mở lại. */
function finishClose() {
  if (props.kind) return
  displayKind.value = null
  reason.value = ''
  fields.value = []
  categoryIds.value = []
  tagIds.value = []
  taxonomyConfirmed.value = false
}
</script>

<template>
  <VDialog
    :model-value="Boolean(props.kind)"
    max-width="700"
    scrollable
    :persistent="props.busy"
    @update:model-value="!$event && emit('close')"
    @after-leave="finishClose"
  >
    <AppDialogLayout
      :title="displayKind === 'reject' ? 'Từ chối content AI' : 'Duyệt tạo Post nháp'"
      :subtitle="props.detail?.draft?.title"
      :close-disabled="props.busy"
      close-label="Đóng xác nhận duyệt content AI"
      @close="emit('close')"
    >
      <VCardText :aria-busy="props.busy || props.loading">
        <VForm
          id="ai-content-review-decision-form"
          @submit.prevent="confirm"
        >
          <VAlert
            v-if="props.error"
            type="error"
            variant="tonal"
            class="mb-4"
          >
            {{ props.error }}
            <VBtn
              variant="text"
              :disabled="props.busy || props.loading"
              @click="emit('reload')"
            >
              Kiểm tra trạng thái
            </VBtn>
          </VAlert>
          <VAlert
            variant="tonal"
            color="info"
            class="mb-4"
          >
            {{ displayKind === 'reject' ? 'Nội dung bị từ chối sẽ không tạo Post. Có thể tạo một bản AI mới từ tác vụ này.' : 'Duyệt sẽ tạo Post ở trạng thái nháp. Editor kiểm tra bài và chọn Publish trong mục Post.' }}
          </VAlert>
          <template v-if="displayKind === 'approve'">
            <AppSelect
              v-model="fields"
              :items="[...fieldOptions, ...(props.detail?.has_thumbnail ? [{ title: 'Ảnh đại diện', value: 'thumbnail' }] : [])]"
              label="Nội dung đưa vào Post nháp"
              hint="Tiêu đề bắt buộc để tạo Post mới."
              persistent-hint
              multiple
              chips
              :disabled="locked"
              class="mb-4"
            />
            <AiManualTaxonomyFields
              v-if="fields.includes('taxonomy')"
              v-model:categories="categoryIds"
              v-model:tags="tagIds"
              :active="Boolean(props.kind) && !props.loading"
              :disabled="locked"
            />
            <VCheckbox
              v-if="fields.includes('taxonomy') && legacyTaxonomy"
              v-model="taxonomyConfirmed"
              label="Tôi xác nhận danh mục và tag đã chọn thủ công, kể cả khi để trống."
              :disabled="locked"
              class="mb-3"
            />
          </template>
          <AppTextarea
            v-model="reason"
            :label="displayKind === 'reject' ? 'Lý do từ chối (bắt buộc)' : 'Ghi chú duyệt (tùy chọn)'"
            :required="displayKind === 'reject'"
            maxlength="2000"
            counter="2000"
            rows="4"
            :disabled="locked"
          />
        </VForm>
      </VCardText>
      <template #footer>
        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            :disabled="props.busy"
            @click="emit('close')"
          >
            Hủy
          </VBtn>
          <VBtn
            type="submit"
            form="ai-content-review-decision-form"
            :color="displayKind === 'reject' ? 'error' : 'success'"
            :loading="props.busy"
            :disabled="!canSubmit"
          >
            {{ displayKind === 'reject' ? 'Xác nhận từ chối' : 'Xác nhận duyệt' }}
          </VBtn>
        </VCardActions>
      </template>
      <template #overlay>
        <VOverlay
          :model-value="props.loading"
          contained
          persistent
          class="align-center justify-center"
        >
          <VProgressCircular indeterminate />
        </VOverlay>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>
