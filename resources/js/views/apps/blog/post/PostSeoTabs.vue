<!--
  =====================================================================
  CHỨC NĂNG FILE: Gom SEO Analysis/Settings/Preview vào ba tab ở cột nội dung.
  CÁC HÀM/METHOD TRONG FILE: Không có; activeTab điều khiển hiển thị bằng v-show.
  INPUT/OUTPUT CỦA CLASS (tổng thể): SEO model/analysis/title/excerpt/thumbnail -> v-model SEO.
  Panel giữ mounted để đổi tab không mất dữ liệu hoặc bỏ qua validation form.
  =====================================================================
-->
<script setup>
import { shallowRef, useId } from 'vue'
import PostSeoAnalysis from './PostSeoAnalysis.vue'
import PostSeoSettings from './PostSeoSettings.vue'
import PostSeoPreview from './PostSeoPreview.vue'

const props = defineProps({
  analysis: { type: Object, required: true },
  title: { type: String, default: '' },
  excerpt: { type: String, default: '' },
  thumbnail: { type: Object, default: null },
})

const seo = defineModel({ type: Object, required: true })
const activeTab = shallowRef('analysis')
const tabsId = useId()

const tabs = [
  { value: 'analysis', label: 'Phân tích', icon: 'tabler-chart-bar' },
  { value: 'settings', label: 'Cài đặt', icon: 'tabler-settings' },
  { value: 'preview', label: 'Xem trước', icon: 'tabler-eye' },
]
</script>

<template>
  <VCard
    title="SEO"
    class="mb-6"
    data-testid="post-seo-tabs"
  >
    <VTabs
      v-model="activeTab"
      grow
      aria-label="SEO tabs"
    >
      <VTab
        v-for="tab in tabs"
        :id="`${tabsId}-${tab.value}-tab`"
        :key="tab.value"
        :value="tab.value"
        :prepend-icon="tab.icon"
        :aria-controls="`${tabsId}-${tab.value}-panel`"
        @click="activeTab = tab.value"
      >
        {{ tab.label }}
      </VTab>
    </VTabs>
    <VDivider />
    <div
      v-show="activeTab === 'analysis'"
      :id="`${tabsId}-analysis-panel`"
      role="tabpanel"
      :aria-labelledby="`${tabsId}-analysis-tab`"
      class="pa-4"
    >
      <PostSeoAnalysis
        :analysis="props.analysis"
        class="mb-0 elevation-0"
      />
    </div>
    <div
      v-show="activeTab === 'settings'"
      :id="`${tabsId}-settings-panel`"
      role="tabpanel"
      :aria-labelledby="`${tabsId}-settings-tab`"
      class="pa-4"
    >
      <PostSeoSettings
        v-model="seo"
        :title="props.title"
        :excerpt="props.excerpt"
        class="mb-0 elevation-0"
      />
    </div>
    <div
      v-show="activeTab === 'preview'"
      :id="`${tabsId}-preview-panel`"
      role="tabpanel"
      :aria-labelledby="`${tabsId}-preview-tab`"
      class="pa-4"
    >
      <PostSeoPreview
        :analysis="props.analysis"
        :thumbnail="props.thumbnail"
        class="mb-0 elevation-0"
      />
    </div>
  </VCard>
</template>
