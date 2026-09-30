<!--
  =====================================================================
  CHỨC NĂNG FILE: Nhập trạng thái xuất bản và taxonomy lấy từ API.
  CÁC HÀM/METHOD TRONG FILE: filteredCategories (computed): lọc category;
  loadTaxonomy(): tải category/tag và quản lý loading/error.
  INPUT/OUTPUT CỦA CLASS (tổng thể): v-model status/categories/tags -> cập nhật form.
  SEO được tách sang các component riêng; taxonomy được lưu theo ID qua Post API.
  =====================================================================
-->
<script setup>
import { computed, onMounted, shallowRef } from 'vue'
import { postService } from '@/services/post'

const status = defineModel('status', { type: String, default: 'draft' })
const selectedCategories = defineModel('categories', { type: Array, default: () => [] })
const postTags = defineModel('tags', { type: Array, default: () => [] })
const categorySearch = shallowRef('')
const categories = shallowRef([])
const tagItems = shallowRef([])
const taxonomyLoading = shallowRef(false)
const taxonomyError = shallowRef('')

const statusItems = [
  { title: 'Draft', value: 'draft' },
  { title: 'Published', value: 'published' },
  { title: 'Archived', value: 'archived' },
]

const filteredCategories = computed(() => categories.value.filter(category => category.name.toLowerCase().includes(categorySearch.value.toLowerCase())))

const loadTaxonomy = async () => {
  taxonomyLoading.value = true
  taxonomyError.value = ''
  try {
    ;[categories.value, tagItems.value] = await Promise.all([
      postService.taxonomy('categories'),
      postService.taxonomy('tags'),
    ])
  }
  catch (error) {
    taxonomyError.value = error?.data?.message || error?.message || 'Không tải được category/tag.'
  }
  finally {
    taxonomyLoading.value = false
  }
}

onMounted(loadTaxonomy)
</script>

<template>
  <VCard
    title="Publish Settings"
    class="mb-6"
  >
    <VCardText>
      <AppSelect
        v-model="status"
        label="Status"
        :items="statusItems"
        prepend-inner-icon="tabler-status-change"
      />
      <div class="d-flex justify-space-between text-caption mt-4">
        <span>Visibility</span><span>Public</span>
      </div>
      <div class="d-flex justify-space-between text-caption mt-3">
        <span>Publish</span><span>Immediately</span>
      </div>
    </VCardText>
  </VCard>
  <VExpansionPanels class="mb-6">
    <VExpansionPanel title="Categories & Tags">
      <VExpansionPanelText>
        <VProgressLinear
          v-if="taxonomyLoading"
          indeterminate
          color="primary"
          class="mb-3"
        />
        <VAlert
          v-if="taxonomyError"
          type="error"
          variant="tonal"
          class="mb-3"
        >
          {{ taxonomyError }}
        </VAlert>
        <AppTextField
          v-model="categorySearch"
          placeholder="Search categories..."
          prepend-inner-icon="tabler-search"
          class="mb-3"
        />
        <VRow dense>
          <VCol
            v-for="category in filteredCategories"
            :key="category.id"
            cols="6"
          >
            <VCheckbox
              v-model="selectedCategories"
              :value="category.id"
              :label="category.name"
              color="primary"
              density="compact"
              hide-details
            />
          </VCol>
        </VRow>
        <AppCombobox
          v-model="postTags"
          label="Tags"
          placeholder="Select tags"
          :items="tagItems"
          item-title="name"
          item-value="id"
          multiple
          chips
          closable-chips
        />
      </VExpansionPanelText>
    </VExpansionPanel>
  </VExpansionPanels>
</template>
