<!--
  =====================================================================
  Header/footer cố định qua AppDialogLayout; chỉ content ở giữa được cuộn.
  CHỨC NĂNG FILE: Xác nhận regenerate/Apply draft/xóa/hủy một run riêng biệt.
  CÁC HÀM/METHOD TRONG FILE: removing/applying/cancelling/regenerating/fieldOptions/blocked
  (computed), confirm(), watcher action identity/session/capability.
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

const props = defineProps({ action: { type: Object, default: null }, busy: Boolean, error: { type: String, default: '' } })
const emit = defineEmits(['confirm', 'close'])
const fields = ref([])
const instructions = ref('')
const writing = ref(emptyWritingPreferences(true))
const categoryIds = ref([])
const tagIds = ref([])
const manualOverride = ref(false)
const taxonomyConfirmed = ref(false)
const refreshSource = ref(false)
const capability = shallowRef({})
const loadingCapabilities = shallowRef(false)
const capabilityError = shallowRef('')
const removing = computed(() => props.action?.kind === 'remove')
const applying = computed(() => props.action?.kind === 'apply')
const cancelling = computed(() => props.action?.kind === 'cancel')
const regenerating = computed(() => props.action?.kind === 'regenerate')
const legacyTaxonomy = computed(() => applying.value && props.action?.session?.draft?.taxonomy_origin !== 'manual')

const fieldOptions = computed(() => applying.value
  ? ['title', 'excerpt', 'content', 'seo', 'taxonomy', ...(props.action?.session?.thumbnail ? ['thumbnail'] : [])].map(value => ({ title: { title: 'Tiêu đề', excerpt: 'Tóm tắt', content: 'Nội dung', seo: 'SEO', taxonomy: 'Danh mục và tag thủ công', thumbnail: 'Ảnh đại diện' }[value], value }))
  : withoutAiTaxonomyOutputs(capability.value.outputs ?? []).map(value => ({ title: capability.value.output_options?.find(item => item.value === value)?.title ?? value, value })))

const blocked = computed(() => props.busy || props.action?.loading || props.action?.loadFailed
  || (regenerating.value && (loadingCapabilities.value || Boolean(capabilityError.value)))
  || (applying.value && (!fields.value.length || props.action?.session?.status !== 'ready' || props.action?.session?.applied_target_id || (legacyTaxonomy.value && fields.value.includes('taxonomy') && !taxonomyConfirmed.value))))

// =====================================================================
// Input: action identity mới. Output: reset một lần, tải capability regen;
// cleanup bỏ GET cũ, không reset yêu cầu người dùng khi detail trả về.
// =====================================================================
watch(() => `${props.action?.kind || ''}:${props.action?.item?.id || ''}:${props.action?.item?.targetType || ''}`, async (_, previous, onCleanup) => {
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

// =====================================================================
// Input: detail ready. Output: chỉ lấy taxonomy đã chọn thủ công; legacy để trống.
// =====================================================================
watch(() => props.action?.session, session => {
  if (!session) return
  categoryIds.value = session.draft?.taxonomy_origin === 'manual' ? [...(session.draft.category_ids ?? [])] : []
  tagIds.value = session.draft?.taxonomy_origin === 'manual' ? [...(session.draft.tag_ids ?? [])] : []
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
    ...(applying.value || manualOverride.value ? { category_ids: categoryIds.value ?? [], tag_ids: tagIds.value ?? [] } : {}),
  })
}
</script>

<template>
  <VDialog
    :model-value="Boolean(props.action)"
    :persistent="props.busy"
    max-width="780"
    scrollable
    @update:model-value="!$event && emit('close')"
  >
    <AppDialogLayout
      :title="removing ? 'Xóa content AI' : cancelling ? 'Hủy tác vụ AI' : applying ? 'Tạo Post nháp' : 'Tạo lại content AI'"
      :close-disabled="props.busy"
      close-label="Đóng hộp thoại content AI"
      @close="emit('close')"
    >
      <VCardText>
        <p class="font-weight-medium text-wrap">
          {{ props.action?.item.title }}
        </p>
        <VProgressLinear
          v-if="props.action?.loading"
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
            :disabled="props.busy || props.action?.loading || loadingCapabilities"
          />
          <template v-if="regenerating">
            <AiWritingPreferences
              v-model="writing"
              regenerate
              :parent-profile="props.action?.session?.writing_profile"
              :disabled="props.busy || props.action?.loading"
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
              v-if="props.action?.item.targetType === 'post'"
              v-model="manualOverride"
              label="Thay danh mục và tag cho bản mới"
              :disabled="props.busy || props.action?.loading"
            />
          </template>
          <template v-if="applying || manualOverride">
            <AiManualTaxonomyFields
              v-model:categories="categoryIds"
              v-model:tags="tagIds"
              :disabled="props.busy || props.action?.loading"
            />
            <VCheckbox
              v-if="legacyTaxonomy && fields.includes('taxonomy')"
              v-model="taxonomyConfirmed"
              label="Tôi đã kiểm tra danh mục/tag và xác nhận các lựa chọn trên"
              :disabled="props.busy || props.action?.loading"
            />
          </template>
          <VAlert
            v-if="applying && props.action?.session?.applied_target_id"
            type="info"
            variant="tonal"
          >
            Bản này đã được áp dụng vào Post #{{ props.action.session.applied_target_id }}.
          </VAlert>
          <AiPipelineReport :session="props.action?.session" />
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
