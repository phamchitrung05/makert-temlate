<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị vùng nhập tiêu đề và nội dung chính của Post
  =====================================================================

  Component nhận các model của form từ PostForm, cung cấp chế độ viết/xem trước
  an toàn và cho phép chỉnh các option preview chưa có backend persistence.

  CÁC HÀM/METHOD TRONG FILE:
  - plainContent: lấy text đã phân tích dùng chung với SEO
  - wordCount: đếm số từ trong content
  - slugDomain: lấy domain làm prefix của ô Slug
  - copyPermalink(): sao chép permalink preview nếu Clipboard API khả dụng

  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : v-model title/content/excerpt/options và slug preview
  - OUTPUT: cập nhật model, title-blur/media-busy; cha chặn lưu khi ảnh chưa xong.
  =====================================================================
-->
<script setup>
import { computed, shallowRef } from 'vue'
import PostEditor from './PostEditor.vue'

const props = defineProps({
  slug: { type: String, required: true },
  permalink: { type: String, required: true },
  slugLoading: { type: Boolean, default: false },
  slugError: { type: String, default: '' },
  contentAnalysis: { type: Object, required: true },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['titleBlur', 'mediaBusy'])

const title = defineModel('title', { type: String, default: '' })
const content = defineModel('content', { type: String, default: '' })
const excerpt = defineModel('excerpt', { type: String, default: '' })
const postOptions = defineModel('options', { type: Array, default: () => [] })

const contentTab = shallowRef('write')
const copied = shallowRef(false)

const plainContent = computed(() => props.contentAnalysis.text)
const wordCount = computed(() => props.contentAnalysis.words.length)
const slugDomain = computed(() => `${new URL(props.permalink).origin}/`)

/**
 * INPUT: permalink preview hiện tại.
 * OUTPUT: Promise hoàn tất sau thao tác Clipboard API.
 * SIDE EFFECT: ghi permalink vào clipboard và hiển thị trạng thái copied ngắn hạn.
 * EXCEPTION: bỏ qua khi trình duyệt không hỗ trợ Clipboard API.
 */
const copyPermalink = async () => {
  if (!navigator?.clipboard)
    return

  try {
    await navigator.clipboard.writeText(props.permalink)
    copied.value = true
    window.setTimeout(() => { copied.value = false }, 1500)
  }
  catch { copied.value = false }
}
</script>

<template>
  <VCard
    title="Basic Information"
    class="mb-6"
  >
    <VCardText>
      <VRow>
        <VCol cols="12">
          <AppTextField
            v-model="title"
            label="Post Title"
            placeholder="Enter an engaging title for your post..."
            :rules="[requiredValidator]"
            counter="255"
            maxlength="255"
            @blur="emit('titleBlur')"
          >
            <template #append-inner>
              <VIcon
                color="primary"
                size="18"
                icon="tabler-wand"
              />
            </template>
          </AppTextField>
        </VCol>

        <VCol cols="12">
          <AppTextField
            :model-value="props.slug"
            label="Slug"
            :prefix="slugDomain"
            persistent-placeholder
            placeholder="Slug sẽ được tạo từ Title"
            :loading="props.slugLoading"
            hint="Slug được trả về sau khi rời ô Title. Đường dẫn bài viết đầy đủ dùng /blog/slug."
            persistent-hint
            readonly
          >
            <template #append-inner>
              <IconBtn
                size="x-small"
                :color="copied ? 'success' : 'secondary'"
                aria-label="Copy permalink"
                :disabled="!props.slug || props.slugLoading"
                @click="copyPermalink"
              >
                <VIcon :icon="copied ? 'tabler-check' : 'tabler-copy'" />
              </IconBtn>
            </template>
          </AppTextField>
          <VAlert
            v-if="props.slugError"
            type="warning"
            variant="tonal"
            class="mt-2"
            role="alert"
          >
            {{ props.slugError }}
          </VAlert>
        </VCol>
      </VRow>
    </VCardText>
  </VCard>

  <VCard class="mb-6">
    <VCardItem>
      <template #title>
        Content
      </template>
      <template #append>
        <div class="d-flex align-center ga-2">
          <VBtnToggle
            v-model="contentTab"
            mandatory
            density="compact"
            color="primary"
            rounded="lg"
          >
            <VBtn
              value="write"
              size="small"
              class="text-none"
            >
              Write
            </VBtn>
            <VBtn
              value="preview"
              size="small"
              class="text-none"
            >
              Preview
            </VBtn>
          </VBtnToggle>
          <VBtn
            color="primary"
            variant="tonal"
            size="small"
            prepend-icon="tabler-wand"
            class="text-none"
            disabled
          >
            AI Assistant
          </VBtn>
        </div>
      </template>
    </VCardItem>

    <VCardText>
      <PostEditor
        v-if="contentTab === 'write'"
        v-model="content"
        placeholder="Start writing your post..."
        :disabled="props.disabled"
        @media-busy="emit('mediaBusy', $event)"
      />
      <div
        v-else
        class="editor-canvas content-preview pa-6 text-body-1 border rounded"
      >
        {{ plainContent || 'Your content preview will appear here.' }}
      </div>

      <div class="d-flex flex-wrap justify-space-between align-center ga-2 pt-4 text-body-2 text-medium-emphasis">
        <span>Word count: {{ wordCount }}</span>
        <span class="d-flex align-center">
          <VIcon
            size="14"
            color="success"
            class="me-1"
            icon="tabler-circle-check"
          />
          Changes are kept locally until you save
        </span>
      </div>
    </VCardText>
  </VCard>

  <VCard class="mb-6">
    <VCardItem>
      <template #title>
        Post Options
      </template>
      <template #append>
        <VChip
          size="x-small"
          color="secondary"
          variant="tonal"
        >
          Planned
        </VChip>
      </template>
    </VCardItem>

    <VCardText>
      <div class="d-flex flex-column ga-4">
        <div
          v-for="option in postOptions"
          :key="option.key"
          class="d-flex align-center justify-space-between ga-4"
        >
          <div>
            <div class="text-high-emphasis font-weight-medium mb-1">
              {{ option.title }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ option.subtitle }}
            </div>
          </div>
          <VSwitch
            v-model="option.value"
            color="primary"
            hide-details
            density="compact"
            inset
          />
        </div>
      </div>
    </VCardText>
  </VCard>

  <VCard class="mb-6">
    <VCardItem>
      <template #title>
        Excerpt
      </template>
    </VCardItem>

    <VCardText>
      <AppTextarea
        v-model="excerpt"
        label="Excerpt"
        placeholder="Write a short description for your post..."
        rows="3"
        counter="5000"
        maxlength="5000"
        hint="Tóm tắt bài viết; dùng làm mô tả SEO khi Meta Description để trống."
        persistent-hint
      />
    </VCardText>
  </VCard>
</template>

<style scoped>
.editor-canvas {
  min-block-size: 12vh;
  background: rgb(var(--v-theme-surface));
}

.content-preview {
  white-space: pre-wrap;
}
</style>
