<!--
  =====================================================================
  Header/footer cố định qua AppDialogLayout; chỉ content ở giữa được cuộn.
  CHỨC NĂNG FILE: Kết hợp giao diện tạo/chỉnh sửa Post và phát payload workflow
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
  - submit(): validate và emit payload kèm ý định save/review/publish
  - applyAiThumbnail()/commitAiThumbnail(): áp dụng ảnh thumbnail và giữ lineage run
  - watcher props.post: cập nhật form khi API tải xong Post
  - mediaBusy: khóa lưu/publish trong lúc picker/upload hoặc còn URL ảnh tạm.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : post, loading, saving và error từ page/store
  - OUTPUT: emit submit payload title/content/status/media/SEO/provenance/workflow hoặc emit discard
  =====================================================================
-->
<script setup>
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import { computed, reactive, shallowRef, watch } from 'vue'
import PostContentPanel from './PostContentPanel.vue'
import PostMediaPanel from './PostMediaPanel.vue'
import PostSettingsSidebar from './PostSettingsSidebar.vue'
import PostSeoTabs from './PostSeoTabs.vue'
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
const mediaBusy = shallowRef(false)
const pendingThumbnailPayload = shallowRef(null)
const pendingAiProvenance = shallowRef(null)
const overwriteDialog = shallowRef(false)

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
  galleryImages: [],
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
  form.galleryImages = Array.isArray(post?.media?.gallery_images) ? post.media.gallery_images : []
  form.aiLineage = []
  resetSlug(post)
}

watch(() => props.post, sync, { immediate: true })

/**
 * =====================================================================
 * CHỨC NĂNG: Validate form và phát payload workflow Post.
 * =====================================================================
 * INPUT: workflow `save`, `review` hoặc `publish`.
 * OUTPUT: emit `submit` khi form hợp lệ, status hiện tại không tự đổi bởi UI.
 * SIDE EFFECT: page caller gọi endpoint lifecycle tương ứng sau khi save.
 * EXCEPTION: dừng im lặng khi Vuetify validation không đạt.
 * =====================================================================
 */
const submit = async (workflow = 'save') => {
  if (mediaBusy.value || props.saving) return
  if (props.loading || props.saving)
    return

  const validation = await formRef.value?.validate()

  if (!validation?.valid)
    return

  emit('submit', {
    title: form.title,
    content: form.content,
    excerpt: form.excerpt,
    seo: { ...form.seo },
    status: form.status,
    workflowAction: workflow,
    categories: [...form.categories],
    tags: [...form.tags],
    thumbnail: form.thumbnail,
    galleryImages: [...form.galleryImages],
    aiRuns: activeAiLineage(form.aiLineage, form),
  })
}

/**
 * =====================================================================
 * CHỨC NĂNG: Chuẩn bị thumbnail AI và xác nhận thay ảnh hiện tại.
 * =====================================================================
 * INPUT: media asset và lineage metadata từ dialog tạo ảnh.
 * OUTPUT: cập nhật pending payload cục bộ, chưa lưu backend.
 * SIDE EFFECT: mở xác nhận nếu thumbnail khác ảnh hiện tại.
 * EXCEPTION: không gọi API hoặc lưu Post tại bước này.
 * =====================================================================
 */
const applyAiThumbnail = (asset, provenance = null) => {
  const payload = { thumbnail: asset }

  pendingThumbnailPayload.value = payload
  pendingAiProvenance.value = provenance
  if (overwrittenFields(form, payload).length) overwriteDialog.value = true
  else commitAiThumbnail()
}

/**
 * =====================================================================
 * CHỨC NĂNG: Áp dụng thumbnail và lineage sau khi admin xác nhận.
 * =====================================================================
 * INPUT: pending thumbnail/provenance sau khi admin xác nhận.
 * OUTPUT: cập nhật thumbnail trong form và đóng dialog.
 * SIDE EFFECT: form giữ aiLineage để postService gửi danh sách run/field lên API.
 * EXCEPTION: không gọi API hoặc lưu Post tại bước này.
 * =====================================================================
 */
const commitAiThumbnail = () => {
  if (pendingThumbnailPayload.value) Object.assign(form, mergePostCandidate(form, pendingThumbnailPayload.value))
  form.aiLineage = mergeAiLineage(form.aiLineage, pendingAiProvenance.value, form)
  pendingThumbnailPayload.value = null
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
          color="primary"
          prepend-icon="tabler-device-floppy"
          :loading="props.saving"
          :disabled="props.loading || props.saving || mediaBusy"
          @click="submit('save')"
        >
          Save as Draft
        </VBtn>
        <VBtn
          v-if="['draft', 'rejected'].includes(form.status)"
          variant="tonal"
          color="warning"
          prepend-icon="tabler-eye-check"
          :loading="props.saving"
          :disabled="props.loading || props.saving || mediaBusy"
          @click="submit('review')"
        >
          Submit for Review
        </VBtn>
        <VBtn
          v-if="['draft', 'pending_review', 'rejected'].includes(form.status)"
          prepend-icon="tabler-send"
          :loading="props.saving"
          :disabled="props.loading || props.saving || mediaBusy"
          @click="submit('publish')"
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

    <VDialog
      v-model="overwriteDialog"
      scrollable
      max-width="480"
    >
      <AppDialogLayout
        title="Xác nhận thay thumbnail"
        @close="overwriteDialog = false"
      >
        <VCardText>
          Thumbnail hiện tại sẽ được thay bằng ảnh AI đã chọn.
        </VCardText>
        <template #footer>
          <VCardActions>
            <VSpacer />
            <VBtn
              variant="text"
              @click="overwriteDialog = false; pendingThumbnailPayload = null; pendingAiProvenance = null"
            >
              Giữ dữ liệu hiện tại
            </VBtn>
            <VBtn
              variant="flat"
              color="primary"
              @click="commitAiThumbnail"
            >
              Áp dụng
            </VBtn>
          </VCardActions>
        </template>
      </AppDialogLayout>
    </VDialog>

    <VForm
      ref="formRef"
      :disabled="props.loading || props.saving"
      @submit.prevent="submit('save')"
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
            @media-busy="mediaBusy = $event"
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
            v-model:gallery-images="form.galleryImages"
            :title="form.title"
            :disabled="props.loading || props.saving"
            @ai-image-applied="applyAiThumbnail"
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
