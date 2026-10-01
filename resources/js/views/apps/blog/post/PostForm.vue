<!--
  =====================================================================
  CHỨC NĂNG FILE: Kết hợp giao diện tạo/chỉnh sửa Post và phát payload lưu
  =====================================================================

  Component là form container cho giao diện Post mới. Nó đồng bộ dữ liệu Post
  hiện tại vào state cục bộ, phối hợp các panel nội dung/media/settings và chỉ
  gửi những field backend hiện hỗ trợ.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - isEditing/pageTitle/pageDescription: nội dung header theo create/edit mode
  - permalinkSlug/generateSlug/resetSlug: slug backend thông qua useSlug dùng chung
  - publicOrigin/permalink/contentAnalysis/seoAnalysis: URL và phân tích SEO chung
  - moreActions: cấu hình menu Vuexy MoreBtn cho thao tác discard
  - createPostOptions(): tạo state mặc định cho nhóm tùy chọn bài viết
  - sync(): đồng bộ Post prop vào form state
  - submit(): validate và emit payload với trạng thái được chọn
  - applyAiContent()/commitAiContent(): áp dụng candidate và giữ lineage run
  - watcher props.post: cập nhật form khi API tải xong Post

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : post, loading, saving và error từ page/store
  - OUTPUT: emit submit payload title/content/status/media/SEO và provenance hoặc emit discard
  =====================================================================
-->
<script setup>
import { computed, reactive, shallowRef, watch } from 'vue'
import PostContentPanel from './PostContentPanel.vue'
import PostMediaPanel from './PostMediaPanel.vue'
import PostSettingsSidebar from './PostSettingsSidebar.vue'
import PostSeoTabs from './PostSeoTabs.vue'
import CreateWithAiDialog from './dialog/CreateWithAiDialog.vue'
import { buildContentUrl, createSeo } from '../../../../composables/seoMetadata'
import { useSeoMetadata } from '../../../../composables/useSeoMetadata'
import { useSlug } from '../../../../composables/useSlug'
import { activeAiLineage, mergeAiLineage, mergePostCandidate, overwrittenFields } from '@/composables/aiCandidate'

const props = defineProps({
  post: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
  error: { type: String, default: '' },
})

const emit = defineEmits(['submit', 'discard'])
const formRef = shallowRef()
const aiDialog = shallowRef(false)
const pendingAiPayload = shallowRef(null)
const pendingAiProvenance = shallowRef(null)
const overwriteDialog = shallowRef(false)
const overwritten = shallowRef([])

/**
 * =====================================================================
 * CHỨC NĂNG: Khởi tạo option preview cho mỗi form Post
 * =====================================================================
 * INPUT: Không có đối số.
 * OUTPUT: Mảng option mới; chưa phải cấu hình được lưu backend.
 * SIDE EFFECT: Không thay đổi shared state hoặc gọi API.
 * EXCEPTION/TRANSACTION: Không ném lỗi hoặc mở transaction.
 * =====================================================================
 */
const createPostOptions = () => [
  { key: 'comments', title: 'Allow Comments', subtitle: 'Let readers comment on this post.', value: true },
  { key: 'sharing', title: 'Enable Social Sharing', subtitle: 'Show social sharing buttons.', value: true },
  { key: 'pin', title: 'Pin This Post', subtitle: 'Keep this post at the top.', value: false },
  { key: 'sponsored', title: 'Sponsored Post', subtitle: 'Mark as sponsored content.', value: false },
  { key: 'notification', title: 'Send Email Notification', subtitle: 'Notify subscribers about this post.', value: true },
]

const form = reactive({
  title: '',
  content: '',
  status: 'draft',
  excerpt: '',
  seo: createSeo(),
  categories: [],
  tags: [],
  options: createPostOptions(),
  thumbnail: null,
  contentImages: [],
  aiLineage: [],
})

const isEditing = computed(() => Boolean(props.post?.id))
const pageTitle = computed(() => isEditing.value ? 'Edit Post' : 'Create New Post')

const pageDescription = computed(() => isEditing.value
  ? 'Update the article content, media and publishing status.'
  : 'Share your ideas with the world. Write, format and publish your content with powerful tools.')

const moreActions = [{ title: 'Discard and return', prependIcon: 'tabler-arrow-left', onClick: () => emit('discard') }]

const { slug: permalinkSlug, loading: slugLoading, error: slugError, checked: slugChecked, reset: resetSlug, generate: generateSlug } = useSlug({
  title: () => form.title,
  modelType: 'post',
  modelId: () => props.post?.id ?? null,
  modelLabel: 'bài viết',
})

const publicOrigin = computed(() => import.meta.env.VITE_PUBLIC_URL || (props.post?.permalink ? new URL(props.post.permalink).origin : window.location.origin))
const permalink = computed(() => buildContentUrl(permalinkSlug.value, publicOrigin.value, '/blog'))

const { contentAnalysis, seoAnalysis } = useSeoMetadata({
  form,
  slug: permalinkSlug,
  checked: slugChecked,
  url: permalink,
  content: () => form.content,
  origin: publicOrigin,
})

/**
 * =====================================================================
 * CHỨC NĂNG: Đồng bộ Post hiện tại vào form và reset lineage AI cục bộ.
 * =====================================================================
 * INPUT: Post từ API hoặc null khi tạo mới.
 * OUTPUT: cập nhật form state, không trả giá trị.
 * SIDE EFFECT: reset toàn bộ field được backend hỗ trợ khi Post thay đổi.
 * EXCEPTION: dữ liệu media sai shape được chuẩn hóa về null/mảng rỗng.
 * =====================================================================
 */
const sync = post => {
  form.title = post?.title ?? ''
  form.content = post?.content ?? ''
  form.status = post?.status ?? 'draft'
  form.excerpt = post?.excerpt ?? ''
  form.seo = createSeo(post)
  form.categories = Array.isArray(post?.categories) ? post.categories.map(category => typeof category === 'object' ? category.id : category) : []
  form.tags = Array.isArray(post?.tags) ? post.tags.map(tag => typeof tag === 'object' ? tag.id : tag) : []
  form.options = createPostOptions()
  form.thumbnail = post?.media?.thumbnail ?? null
  form.contentImages = Array.isArray(post?.media?.content_images) ? post.media.content_images : []
  form.aiLineage = []
  resetSlug(post)
}

watch(() => props.post, sync, { immediate: true })

/**
 * =====================================================================
 * CHỨC NĂNG: Validate form và phát payload lưu Post.
 * =====================================================================
 * INPUT: status đích; mặc định dùng status đang chọn trong sidebar.
 * OUTPUT: emit `submit` khi form hợp lệ.
 * SIDE EFFECT: cập nhật form.status trước khi phát payload cho page.
 * EXCEPTION: dừng im lặng khi Vuetify validation không đạt.
 * =====================================================================
 */
const submit = async (status = form.status) => {
  if (props.loading || props.saving)
    return
  form.status = status

  const validation = await formRef.value?.validate()

  if (!validation?.valid)
    return

  emit('submit', {
    title: form.title,
    content: form.content,
    excerpt: form.excerpt,
    seo: { ...form.seo },
    status: form.status,
    categories: [...form.categories],
    tags: [...form.tags],
    thumbnail: form.thumbnail,
    contentImages: form.contentImages,
    aiRuns: activeAiLineage(form.aiLineage, form),
  })
}

/**
 * =====================================================================
 * CHỨC NĂNG: Chuẩn bị candidate AI và xác nhận field bị ghi đè.
 * =====================================================================
 * INPUT: candidate fields và lineage metadata từ AI Agent.
 * OUTPUT: cập nhật pending payload cục bộ, chưa lưu backend.
 * SIDE EFFECT: mở xác nhận nếu payload sẽ ghi đè dữ liệu hiện tại.
 * EXCEPTION: không gọi API hoặc lưu Post tại bước này.
 * =====================================================================
 */
const applyAiContent = (payload, provenance = null) => {
  pendingAiPayload.value = payload
  pendingAiProvenance.value = provenance
  overwritten.value = overwrittenFields(form, payload)
  if (overwritten.value.length) overwriteDialog.value = true
  else commitAiContent()
}

/**
 * =====================================================================
 * CHỨC NĂNG: Áp dụng candidate và lineage sau khi admin xác nhận.
 * =====================================================================
 * INPUT: pending payload/provenance sau khi admin xác nhận.
 * OUTPUT: merge field được chọn vào form và đóng dialog.
 * SIDE EFFECT: form giữ aiLineage để postService gửi danh sách run/field lên API.
 * EXCEPTION: không gọi API hoặc lưu Post tại bước này.
 * =====================================================================
 */
const commitAiContent = () => {
  if (pendingAiPayload.value) Object.assign(form, mergePostCandidate(form, pendingAiPayload.value))
  form.aiLineage = mergeAiLineage(form.aiLineage, pendingAiProvenance.value, form)
  pendingAiPayload.value = null
  pendingAiProvenance.value = null
  overwriteDialog.value = false
}
</script>

<template>
  <div>
    <VProgressLinear
      v-if="props.loading"
      indeterminate
      color="primary"
      class="mb-6"
    />

    <div class="d-flex flex-wrap justify-start justify-sm-space-between gap-y-4 gap-x-6 mb-6">
      <div class="d-flex flex-column justify-center">
        <h4 class="text-h4 font-weight-medium">
          {{ pageTitle }}
        </h4>
        <div class="text-body-1">
          {{ pageDescription }}
        </div>
      </div>

      <div class="d-flex gap-4 align-center flex-wrap">
        <VBtn
          variant="tonal"
          color="secondary"
          prepend-icon="tabler-wand"
          @click="aiDialog = true"
        >
          Fill All with AI
          <VTooltip activator="parent">
            Đọc URL và điền nội dung, SEO vào form.
          </VTooltip>
        </VBtn>
        <VBtn
          variant="tonal"
          color="primary"
          prepend-icon="tabler-device-floppy"
          :loading="props.saving && form.status === 'draft'"
          :disabled="props.loading || props.saving"
          @click="submit('draft')"
        >
          Save as Draft
        </VBtn>
        <VBtn
          prepend-icon="tabler-send"
          :loading="props.saving && form.status === 'published'"
          :disabled="props.loading || props.saving"
          @click="submit('published')"
        >
          Publish
        </VBtn>
        <MoreBtn
          :menu-list="moreActions"
          item-props
          class="text-medium-emphasis"
        />
      </div>
    </div>

    <VAlert
      v-if="props.error"
      color="error"
      variant="tonal"
      class="mb-5"
      closable
    >
      {{ props.error }}
    </VAlert>

    <CreateWithAiDialog
      v-model="aiDialog"
      :target-id="props.post?.id"
      @apply="applyAiContent"
    />
    <VDialog
      v-model="overwriteDialog"
      max-width="480"
    >
      <VCard title="Xác nhận ghi đè nội dung">
        <VCardText>
          Các field đã có dữ liệu sẽ được thay bằng candidate AI: {{ overwritten.join(', ') }}.
          Những field không chọn vẫn được giữ nguyên.
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="overwriteDialog = false; pendingAiPayload = null; pendingAiProvenance = null"
          >
            Giữ dữ liệu hiện tại
          </VBtn>
          <VBtn
            color="primary"
            @click="commitAiContent"
          >
            Áp dụng
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VForm
      ref="formRef"
      :disabled="props.loading || props.saving"
      @submit.prevent="submit(form.status)"
    >
      <VRow>
        <VCol
          cols="12"
          md="8"
          data-testid="post-main-column"
        >
          <PostContentPanel
            v-model:title="form.title"
            v-model:content="form.content"
            v-model:excerpt="form.excerpt"
            v-model:options="form.options"
            :slug="permalinkSlug"
            :permalink="permalink"
            :slug-loading="slugLoading"
            :slug-error="slugError"
            :content-analysis="contentAnalysis"
            :disabled="props.loading || props.saving"
            @title-blur="generateSlug"
          />
          <PostSeoTabs
            v-model="form.seo"
            :analysis="seoAnalysis"
            :title="form.title"
            :excerpt="form.excerpt"
            :thumbnail="form.thumbnail"
          />
        </VCol>

        <VCol
          cols="12"
          md="4"
          data-testid="post-sidebar-column"
        >
          <PostMediaPanel
            v-model:thumbnail="form.thumbnail"
            v-model:content-images="form.contentImages"
            :title="form.title"
            :disabled="props.loading || props.saving"
            @ai-image-applied="(asset, provenance) => applyAiContent({ thumbnail: asset }, provenance)"
          />
          <PostSettingsSidebar
            v-model:status="form.status"
            v-model:categories="form.categories"
            v-model:tags="form.tags"
          />
        </VCol>
      </VRow>
    </VForm>
  </div>
</template>
