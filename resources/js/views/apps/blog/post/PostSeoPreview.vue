<!--
  =====================================================================
  CHỨC NĂNG FILE: Xem trước search/social từ metadata hiệu lực.
  CÁC HÀM/METHOD TRONG FILE: Không có; chọn tab bằng state cục bộ.
  INPUT/OUTPUT CỦA CLASS (tổng thể): analysis/thumbnail -> preview escaped.
  =====================================================================
-->
<script setup>
import { shallowRef } from 'vue'

const props = defineProps({ analysis: { type: Object, required: true }, thumbnail: { type: Object, default: null } })
const platform = shallowRef('search')
</script>

<template>
  <VCard
    title="SEO Preview"
    class="mb-6"
  >
    <VCardText>
      <VBtnToggle
        v-model="platform"
        mandatory
        density="compact"
        class="mb-4"
      >
        <VBtn
          value="search"
          size="small"
        >
          Search
        </VBtn>
        <VBtn
          value="social"
          size="small"
        >
          Social
        </VBtn>
      </VBtnToggle>
      <div class="border rounded pa-3">
        <VImg
          v-if="platform === 'social' && props.thumbnail?.file"
          :src="props.thumbnail.file.preview_url || props.thumbnail.file.url"
          :alt="props.thumbnail.alt_text || ''"
          max-height="180"
          cover
          class="mb-3"
        />
        <div class="text-caption text-break">
          {{ props.analysis.url }}
        </div>
        <div class="text-primary font-weight-medium mt-2">
          {{ (platform === 'search' ? props.analysis.title : props.analysis.ogTitle) || 'Tiêu đề bài viết' }}
        </div>
        <div class="text-caption mt-1">
          {{ (platform === 'search' ? props.analysis.description : props.analysis.ogDescription) || 'Mô tả bài viết' }}
        </div>
      </div>
      <p class="text-caption text-medium-emphasis mt-3 mb-0">
        Bản xem trước minh họa; kết quả tìm kiếm thực tế có thể khác.
      </p>
    </VCardText>
  </VCard>
</template>
