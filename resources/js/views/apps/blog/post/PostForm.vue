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
  - applyAiContent(): áp dụng candidate field từ AI Agent vào form cục bộ
  - watcher props.post: cập nhật form khi API tải xong Post

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : post, loading, saving và error từ page/store
  - OUTPUT: emit submit payload title/content/status/media/SEO hoặc emit discard
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

const props = defineProps({
  post: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
  error: { type: String, default: '' },
})

const emit = defineEmits(['submit', 'discard'])
const formRef = shallowRef()
const aiDialog = shallowRef(false)

/** Input: không có. Output: option preview mới, chưa lưu backend. */
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
 * INPUT: Post từ API hoặc null khi tạo mới.
 * OUTPUT: cập nhật form state, không trả giá trị.
 * SIDE EFFECT: reset toàn bộ field được backend hỗ trợ khi Post thay đổi.
 * EXCEPTION: dữ liệu media sai shape được chuẩn hóa về null/mảng rỗng.
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
  resetSlug(post)
}

watch(() => props.post, sync, { immediate: true })

/**
 * INPUT: status đích; mặc định dùng status đang chọn trong sidebar.
 * OUTPUT: emit `submit` khi form hợp lệ.
 * SIDE EFFECT: cập nhật form.status trước khi phát payload cho page.
 * EXCEPTION: dừng im lặng khi Vuetify validation không đạt.
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
  })
}

/** Input: candidate fields từ AI Agent. Output: cập nhật form cục bộ, chưa lưu. */
const applyAiContent = payload => {
  form.title = payload.title ?? form.title
  form.content = payload.content ?? form.content
  form.excerpt = payload.excerpt ?? form.excerpt
  if (payload.seo) form.seo = createSeo(payload.seo)
  if (Array.isArray(payload.categories)) form.categories = [...payload.categories]
  if (Array.isArray(payload.tags)) form.tags = [...payload.tags]
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
            :disabled="props.loading || props.saving"
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
