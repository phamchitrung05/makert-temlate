<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị publishing, SEO và taxonomy sidebar của Post
  =====================================================================

  Sidebar cho phép chọn status mà backend hiện hỗ trợ và trình bày preview SEO.
  Các control SEO/taxonomy chưa có contract backend vẫn được nhập để phục vụ
  preview cục bộ và được gắn nhãn planned nhằm phản ánh đúng phạm vi lưu dữ liệu.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - plainContent: chuyển HTML editor thành text dùng cho SEO preview
  - wordCount: đếm từ để đưa ra gợi ý SEO
  - seoChecks/seoScore/seoColor: phân tích preview từ dữ liệu form hiện tại
  - filteredCategories: lọc danh sách category dùng cho preview cục bộ

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : các v-model status/title/excerpt/SEO/taxonomy và content/media preview
  - OUTPUT: cập nhật form state; render SEO preview và taxonomy controls
  =====================================================================
-->
<script setup>
import { computed, shallowRef } from 'vue'

const props = defineProps({
  content: { type: String, default: '' },
  slug: { type: String, required: true },
  thumbnail: { type: Object, default: null },
})

const status = defineModel('status', { type: String, default: 'draft' })
const title = defineModel('title', { type: String, default: '' })
const excerpt = defineModel('excerpt', { type: String, default: '' })
const focusKeyword = defineModel('focusKeyword', { type: String, default: '' })
const selectedCategories = defineModel('categories', { type: Array, default: () => [] })
const postTags = defineModel('tags', { type: Array, default: () => [] })

const showSeoPreview = shallowRef(true)
const previewPlatform = shallowRef('google')
const categorySearch = shallowRef('')
const categories = ['Technology', 'Marketing', 'Design', 'Lifestyle', 'Business', 'Travel']

const statusItems = [
  { title: 'Draft', value: 'draft' },
  { title: 'Published', value: 'published' },
  { title: 'Archived', value: 'archived' },
]

const plainContent = computed(() => props.content
  .replace(/<[^>]*>/g, ' ')
  .replace(/&nbsp;/gi, ' ')
  .replace(/\s+/g, ' ')
  .trim())

const wordCount = computed(() => plainContent.value ? plainContent.value.split(/\s+/).length : 0)

const seoChecks = computed(() => [
  { label: 'Post title is set', passed: title.value.trim().length > 0 },
  { label: 'Permalink is available', passed: props.slug !== 'enter-post-slug' },
  { label: 'Featured image is added', passed: Boolean(props.thumbnail) },
  { label: 'Content has at least 300 words', passed: wordCount.value >= 300 },
  { label: 'Excerpt is ready for meta description', passed: excerpt.value.trim().length > 0 },
])

const seoScore = computed(() => Math.round((seoChecks.value.filter(check => check.passed).length / seoChecks.value.length) * 100))
const seoColor = computed(() => seoScore.value >= 80 ? 'success' : seoScore.value >= 50 ? 'warning' : 'error')
const filteredCategories = computed(() => categories.filter(category => category.toLowerCase().includes(categorySearch.value.toLowerCase())))
</script>

<template>
  <VCard
    title="Publish Settings"
    class="mb-6"
  >
    <VCardText>
      <div class="d-flex flex-column ga-4">
        <AppSelect
          v-model="status"
          label="Status"
          :items="statusItems"
          prepend-inner-icon="tabler-status-change"
        />
        <div class="d-flex align-center justify-space-between ga-3 text-caption">
          <span class="text-high-emphasis d-flex align-center">
            <VIcon
              size="16"
              class="me-2"
              icon="tabler-eye"
            />
            Visibility
          </span>
          <span class="font-weight-medium d-flex align-center">
            <VIcon
              size="14"
              class="me-1"
              icon="tabler-world"
            />
            Public
          </span>
        </div>
        <div class="d-flex align-center justify-space-between ga-3 text-caption">
          <span class="text-high-emphasis d-flex align-center">
            <VIcon
              size="16"
              class="me-2"
              icon="tabler-calendar"
            />
            Publish
          </span>
          <span class="font-weight-medium d-flex align-center">
            <VIcon
              size="14"
              class="me-1"
              icon="tabler-calendar-clock"
            />
            Immediately
          </span>
        </div>
      </div>
    </VCardText>
  </VCard>

  <VCard class="mb-6">
    <VCardItem>
      <template #title>
        SEO Analysis
      </template>
      <template #append>
        <VChip
          size="x-small"
          color="secondary"
          variant="tonal"
        >
          Preview
        </VChip>
      </template>
    </VCardItem>

    <VCardText>
      <div class="d-flex align-center mb-4">
        <VProgressCircular
          :model-value="seoScore"
          :size="56"
          :width="6"
          :color="seoColor"
          class="me-3 font-weight-bold"
        >
          <span class="text-caption font-weight-bold">{{ seoScore }}</span>
        </VProgressCircular>
        <div class="d-flex flex-column">
          <div
            class="text-caption font-weight-bold"
            :class="`text-${seoColor}`"
          >
            {{ seoScore >= 80 ? 'Good SEO Score' : 'SEO needs more content' }}
          </div>
          <div class="text-caption text-medium-emphasis">
            Preview only; SEO metadata persistence is planned.
          </div>
        </div>
      </div>

      <div class="d-flex flex-column ga-2 text-caption">
        <div
          v-for="check in seoChecks"
          :key="check.label"
          class="d-flex align-center"
        >
          <VIcon
            :color="check.passed ? 'success' : 'warning'"
            :icon="check.passed ? 'tabler-circle-check-filled' : 'tabler-alert-circle-filled'"
            size="16"
            class="me-2"
          />
          {{ check.label }}
        </div>
      </div>
    </VCardText>
  </VCard>

  <VCard class="mb-6">
    <VCardItem>
      <template #title>
        SEO Settings
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
      <AppTextField
        v-model="focusKeyword"
        label="Focus Keyword"
        placeholder="Enter focus keyword"
        prepend-inner-icon="tabler-key"
        class="mb-2"
      />
      <AppTextField
        v-model="title"
        label="Meta Title"
        class="mb-2"
      />
      <AppTextarea
        v-model="excerpt"
        label="Meta Description"
        rows="2"
      />
      <div class="d-flex align-center justify-space-between mt-2">
        <span class="text-high-emphasis">Show SEO Preview</span>
        <VSwitch
          v-model="showSeoPreview"
          color="primary"
          density="compact"
          hide-details
          inset
        />
      </div>
    </VCardText>
  </VCard>

  <VCard
    v-if="showSeoPreview"
    class="mb-6"
  >
    <VCardItem>
      <template #title>
        Search Preview
      </template>

      <template #append>
        <VBtnToggle
          v-model="previewPlatform"
          mandatory
          density="compact"
          color="primary"
          rounded="lg"
        >
          <VBtn
            value="google"
            size="small"
            class="text-none"
          >
            Google Search
          </VBtn>
          <VBtn
            value="social"
            size="small"
            class="text-none"
          >
            Google Share
          </VBtn>
        </VBtnToggle>
      </template>
    </VCardItem>

    <VCardText>
      <div class="search-preview pa-3 rounded-lg border">
        <div class="text-caption text-medium-emphasis text-truncate mb-1">
          https://yourdomain.com/blog/{{ props.slug }}
        </div>
        <div class="text-body-2 font-weight-bold text-primary mb-1">
          {{ title || 'Your Post Title Will Appear Here' }}
        </div>
        <div class="text-caption text-medium-emphasis">
          {{ excerpt || 'Your meta description will appear here.' }}
        </div>
      </div>
    </VCardText>
  </VCard>

  <VCard class="mb-6">
    <VCardItem>
      <template #title>
        Categories
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
      <AppTextField
        v-model="categorySearch"
        placeholder="Search categories..."
        prepend-inner-icon="tabler-search"
        class="mb-3"
      />
      <VRow dense>
        <VCol
          v-for="category in filteredCategories"
          :key="category"
          cols="6"
        >
          <VCheckbox
            v-model="selectedCategories"
            :value="category"
            :label="category"
            color="primary"
            density="compact"
            hide-details
          />
        </VCol>
      </VRow>
    </VCardText>
  </VCard>

  <VCard>
    <VCardItem>
      <template #title>
        Tags
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
      <AppCombobox
        v-model="postTags"
        placeholder="Tag support is planned"
        density="compact"
        multiple
        chips
        closable-chips
        hide-details
      />
    </VCardText>
  </VCard>
</template>

<style scoped>
.search-preview {
  background: rgb(var(--v-theme-surface));
}
</style>
