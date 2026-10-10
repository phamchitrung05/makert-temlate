<!--
  =====================================================================
  Header/footer cố định qua AppDialogLayout; chỉ content ở giữa được cuộn.
  CHỨC NĂNG FILE: Xác nhận regenerate/Apply draft/xóa/hủy một run riêng biệt.
  CÁC HÀM/METHOD TRONG FILE: watcher action/displayAction/session/capability, finishLeave(),
  removing/applying/cancelling/regenerating/fieldOptions/blocked (computed), confirm().
  INPUT/OUTPUT CỦA CLASS (tổng thể): action/detail/version/busy/error -> confirm
  options whitelist; GET capability qua service, không tự mutation hay publish.
  =====================================================================
-->
<script setup>
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
/* eslint-disable camelcase -- Contract candidate Laravel. */
import { computed, ref, shallowRef, watch } from 'vue'
import { aiAgentService } from '@/services/aiAgent'
import { withoutAiTaxonomyOutputs } from '@/utils/aiContentInput'
import { emptyWritingPreferences, writingOptions } from '@/utils/aiArticleOptions'
import AiWritingPreferences from '@/views/ai/shared/AiWritingPreferences.vue'
import AiManualTaxonomyFields from '@/views/ai/shared/AiManualTaxonomyFields.vue'
import AiPipelineReport from '@/views/ai/shared/AiPipelineReport.vue'
import AiThumbnailOptions from '@/views/ai/shared/AiThumbnailOptions.vue'

const props = defineProps({ action: { type: Object, default: null }, busy: Boolean, error: { type: String, default: '' }, catalog: { type: Object, default: () => ({}) } })
const emit = defineEmits(['confirm', 'close'])
const fields = ref([])
const instructions = ref('')
const writing = ref(emptyWritingPreferences(true))
const categoryIds = ref([])
const tagIds = ref([])
const manualOverride = ref(false)
const taxonomyConfirmed = ref(false)
const refreshSource = ref(false)
const thumbnail = ref({ thumbnailMode: 'source', imageModelId: null, thumbnailPrompt: '' })
const capability = shallowRef({})
const loadingCapabilities = shallowRef(false)
const capabilityError = shallowRef('')
const displayAction = shallowRef(null)
const dialogOpen = shallowRef(false)
const actionView = computed(() => displayAction.value ?? props.action)
const removing = computed(() => actionView.value?.kind === 'remove')
const applying = computed(() => actionView.value?.kind === 'apply')
const cancelling = computed(() => actionView.value?.kind === 'cancel')
const regenerating = computed(() => actionView.value?.kind === 'regenerate')
const legacyTaxonomy = computed(() => applying.value && actionView.value?.session?.draft?.taxonomy_origin !== 'manual')
const thumbnailRequested = computed(() => regenerating.value && capability.value.outputs?.includes('thumbnail') && (!fields.value.length || fields.value.includes('thumbnail')))

const fieldOptions = computed(() => applying.value
  ? ['title', 'excerpt', 'content', 'seo', 'taxonomy', ...(actionView.value?.session?.thumbnail ? ['thumbnail'] : [])].map(value => ({ title: { title: 'Tiêu đề', excerpt: 'Tóm tắt', content: 'Nội dung', seo: 'SEO', taxonomy: 'Danh mục và tag thủ công', thumbnail: 'Ảnh đại diện' }[value], value }))
  : withoutAiTaxonomyOutputs(capability.value.outputs ?? []).map(value => ({ title: capability.value.output_options?.find(item => item.value === value)?.title ?? value, value })))

const blocked = computed(() => props.busy || actionView.value?.loading || actionView.value?.loadFailed
  || (regenerating.value && (loadingCapabilities.value || Boolean(capabilityError.value)))
  || (thumbnailRequested.value && thumbnail.value.thumbnailMode === 'generate' && !(props.catalog.imageModelOptions ?? []).some(option => option.value === thumbnail.value.imageModelId))
  || (applying.value && (!fields.value.length || actionView.value?.session?.status !== 'ready' || actionView.value?.session?.applied_target_id || actionView.value?.session?.review?.status === 'rejected' || (legacyTaxonomy.value && fields.value.includes('taxonomy') && !taxonomyConfirmed.value))))

/**
 * =====================================================================
 * CHỨC NĂNG: Giữ action snapshot trong thời gian VDialog chạy transition.
 * =====================================================================
 * INPUT: action từ page, null ngay sau thao tác đóng.
 * OUTPUT: dialogOpen tắt trước; actionView chỉ dọn sau after-leave.
 * SIDE EFFECT: cập nhật state cục bộ, không gọi API hoặc mutate action.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
watch(() => props.action, value => {
  if (value) {
    displayAction.value = value
    dialogOpen.value = true

    return
  }

  dialogOpen.value = false
}, { immediate: true })

/**
 * =====================================================================
 * CHỨC NĂNG: Dọn state action sau khi transition đóng hoàn tất.
 * =====================================================================
 * INPUT: lifecycle after-leave của VDialog.
 * OUTPUT: snapshot và field tạm rỗng nếu action chưa mở lại.
 * SIDE EFFECT: dọn state form cục bộ; không gọi API hoặc emit mutation.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
function finishLeave() {
  if (props.action) return
  displayAction.value = null
  fields.value = []
  instructions.value = ''
  writing.value = emptyWritingPreferences(true)
  categoryIds.value = []
  tagIds.value = []
  manualOverride.value = false
  taxonomyConfirmed.value = false
  refreshSource.value = false
  capability.value = {}
  capabilityError.value = ''
  loadingCapabilities.value = false
}

/**
 * =====================================================================
 * CHỨC NĂNG: Khởi tạo lại form khi chuyển sang một action mới.
 * =====================================================================
 * INPUT: kind/item identity từ action và callback cleanup của watcher.
 * OUTPUT: field mặc định, capability regenerate và trạng thái loading.
 * SIDE EFFECT: GET capability cho target; hủy áp dụng kết quả của request cũ.
 * EXCEPTION/TRANSACTION: lỗi capability hiển thị trong capabilityError; không mutation server.
 * =====================================================================
 */
watch(() => `${props.action?.kind || ''}:${props.action?.item?.id || ''}:${props.action?.item?.targetType || ''}`, async (_, previous, onCleanup) => {
  if (!props.action) return
  let active = true
  onCleanup(() => { active = false })
  fields.value = applying.value ? ['title', 'excerpt', 'content', 'seo', 'taxonomy'] : []
  instructions.value = ''
  writing.value = emptyWritingPreferences(true)
  categoryIds.value = []
  tagIds.value = []
  manualOverride.value = false
  taxonomyConfirmed.value = false
  refreshSource.value = false
  capability.value = {}
  capabilityError.value = ''
  loadingCapabilities.value = regenerating.value
  if (!regenerating.value) return
  try {
    const value = await aiAgentService.capabilities(props.action.item.targetType ?? 'post')
    if (active) capability.value = value
  }
  catch { if (active) capabilityError.value = 'Không tải được hạng mục AI. Hãy đóng và mở lại hộp thoại.' }
  finally { if (active) loadingCapabilities.value = false }
}, { immediate: true })

/**
 * =====================================================================
 * CHỨC NĂNG: Đồng bộ taxonomy và thumbnail từ detail action đã tải.
 * =====================================================================
 * INPUT: session detail của action hiện tại.
 * OUTPUT: category/tag/thumbnail form theo dữ liệu thủ công của session.
 * SIDE EFFECT: cập nhật ref form cục bộ; không gọi API.
 * EXCEPTION/TRANSACTION: bỏ qua session null để giữ snapshot trong transition đóng.
 * =====================================================================
 */
watch(() => props.action?.session, session => {
  if (!session) return
  categoryIds.value = session.draft?.taxonomy_origin === 'manual' ? [...(session.draft.category_ids ?? [])] : []
  tagIds.value = session.draft?.taxonomy_origin === 'manual' ? [...(session.draft.tag_ids ?? [])] : []
  thumbnail.value = { thumbnailMode: session.thumbnail_options?.mode || 'source', imageModelId: session.thumbnail_options?.model_id ?? props.catalog.defaultImageModelId ?? null,
    thumbnailPrompt: session.thumbnail_options?.prompt || '' }
}, { immediate: true })

/**
 * =====================================================================
 * Input: các field/override người dùng duyệt. Output: confirm whitelist;
 * regen thiếu profile/brief/taxonomy nghĩa là kế thừa, Apply gửi IDs thủ công.
 * =====================================================================
 */
function confirm() {
  if (blocked.value) return
  emit('confirm', {
    fields: fields.value ?? [],
    ...(regenerating.value ? { instructions: instructions.value, ...writingOptions(writing.value, true), ...(refreshSource.value ? { refresh_source: true } : {}) } : {}),
    ...(thumbnailRequested.value ? { thumbnail_mode: thumbnail.value.thumbnailMode,
      ...(thumbnail.value.thumbnailMode === 'generate' ? { image_model_id: thumbnail.value.imageModelId, thumbnail_prompt: thumbnail.value.thumbnailPrompt } : {}) } : {}),
    ...(applying.value || manualOverride.value ? { category_ids: categoryIds.value ?? [], tag_ids: tagIds.value ?? [] } : {}),
  })
}
</script>

<template>
  <VDialog
    :model-value="dialogOpen"
    :persistent="props.busy"
    max-width="780"
    scrollable
    @update:model-value="!$event && emit('close')"
    @after-leave="finishLeave"
  >
    <AppDialogLayout
      :title="removing ? 'Xóa content AI' : cancelling ? 'Hủy tác vụ AI' : applying ? 'Tạo Post nháp' : 'Tạo lại content AI'"
      :close-disabled="props.busy"
      close-label="Đóng hộp thoại content AI"
      @close="emit('close')"
    >
      <VCardText>
        <p class="font-weight-medium text-wrap">
          {{ actionView?.item.title }}
        </p>
        <VProgressLinear
          v-if="actionView?.loading"
          indeterminate
          class="mb-4"
        />
        <p v-if="removing">
          Xóa bản content này khỏi danh sách. Tài nguyên đã được duyệt vẫn được giữ.
        </p>
        <p v-else-if="cancelling">
          Yêu cầu worker dừng tác vụ. Request đã gửi tới model có thể vẫn hoàn thành và phát sinh usage.
        </p>
        <template v-else>
          <p>{{ applying ? 'Chuyển các phần đã chọn thành Post nháp để tiếp tục duyệt.' : 'Bản mới dùng nguồn của bản đã chọn. Bản cũ được giữ để đối chiếu.' }}</p>
          <AppSelect
            v-model="fields"
            :items="fieldOptions"
            :label="applying ? 'Phần đưa vào Post nháp' : 'Phần cần tạo lại'"
            :placeholder="applying ? 'Chọn ít nhất một phần' : 'Toàn bộ bài'"
            multiple
            chips
            clearable
            class="mb-4"
            :disabled="props.busy || actionView?.loading || loadingCapabilities"
          />
          <template v-if="regenerating">
            <AiThumbnailOptions
              v-if="thumbnailRequested"
              v-model="thumbnail"
              :catalog="props.catalog"
              :disabled="props.busy || actionView?.loading"
              class="mb-4"
            />
            <AiWritingPreferences
              v-model="writing"
              regenerate
              :parent-profile="actionView?.session?.writing_profile"
              :disabled="props.busy || actionView?.loading"
            />
            <AppTextarea
              v-model="instructions"
              label="Yêu cầu bổ sung"
              placeholder="Để trống để giữ yêu cầu cũ"
              maxlength="4000"
              rows="3"
              :disabled="props.busy"
            />
            <VCheckbox
              v-model="refreshSource"
              label="Đọc lại nguồn thay vì snapshot đã lưu"
              :disabled="props.busy"
            />
            <VCheckbox
              v-if="actionView?.item.targetType === 'post'"
              v-model="manualOverride"
              label="Thay danh mục và tag cho bản mới"
              :disabled="props.busy || actionView?.loading"
            />
          </template>
          <template v-if="applying || manualOverride">
            <AiManualTaxonomyFields
              v-model:categories="categoryIds"
              v-model:tags="tagIds"
              :disabled="props.busy || actionView?.loading"
            />
            <VCheckbox
              v-if="legacyTaxonomy && fields.includes('taxonomy')"
              v-model="taxonomyConfirmed"
              label="Tôi đã kiểm tra danh mục/tag và xác nhận các lựa chọn trên"
              :disabled="props.busy || actionView?.loading"
            />
          </template>
          <VAlert
            v-if="applying && actionView?.session?.applied_target_id"
            type="info"
            variant="tonal"
          >
            Bản này đã được áp dụng vào Post #{{ actionView.session.applied_target_id }}.
          </VAlert>
          <AiPipelineReport :session="actionView?.session" />
        </template>
        <VAlert
          v-if="props.error || capabilityError"
          type="error"
          variant="tonal"
          class="mt-4"
        >
          {{ props.error || capabilityError }}
        </VAlert>
      </VCardText>
      <template #footer>
        <VCardActions class="d-flex justify-end gap-3">
          <VBtn
            variant="tonal"
            color="secondary"
            :disabled="props.busy"
            @click="emit('close')"
          >
            Hủy
          </VBtn>
          <VBtn
            variant="flat"
            :color="removing || cancelling ? 'error' : 'primary'"
            :loading="props.busy"
            :disabled="blocked"
            @click="confirm"
          >
            {{ removing ? 'Xóa' : cancelling ? 'Hủy tác vụ' : applying ? 'Tạo Post nháp' : 'Tạo lại' }}
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>
