<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị preview nguồn URL trước khi AI tạo candidate.
  =====================================================================

  Card giữ presentation của dialog Post cũ; URL chỉ được hiển thị như metadata,
  không render HTML nguồn và không gọi provider trực tiếp.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - Không có hàm/computed/watcher; sourceTags là dữ liệu trình bày tĩnh.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : sourceUrl từ dialog.
  - OUTPUT: card preview nguồn; không emit và không có side effect mạng.
  =====================================================================
-->
<script setup>
const props = defineProps({
  sourceUrl: {
    type: String,
    default: '',
  },
})

const sourceTags = [
  'Laravel 11',
  'New Features',
  'Application Structure',
  'Performance',
  'Developer Experience',
]
</script>

<template>
  <VCard
    border
    elevation="0"
    class="h-100"
  >
    <VCardItem class="pb-0">
      <template #prepend>
        <VIcon
          icon="tabler-link"
          size="18"
          color="primary"
          class="me-2"
        />
      </template>
      <VCardTitle class="text-subtitle-2">
        Xem trước nguồn
      </VCardTitle>
    </VCardItem>

    <VCardText>
      <VImg
        src="https://images.unsplash.com/photo-1618401471353-b98aedd04e11?w=600"
        height="140"
        cover
        rounded="lg"
        class="bg-grey-darken-4"
      >
        <div class="d-flex align-center justify-center h-100 banner-overlay">
          <div class="text-center text-white">
            <div class="text-h6 font-weight-black text-red-accent-2">
              Laravel 11
            </div>
            <div class="text-subtitle-2 font-weight-bold">
              New Features
            </div>
          </div>
        </div>
      </VImg>

      <VCardTitle class="px-0 pb-0 pt-3 text-subtitle-2">
        Laravel 11 New Features
      </VCardTitle>

      <div class="text-caption text-primary text-truncate mb-2">
        {{ props.sourceUrl }}
      </div>

      <div class="d-flex align-center flex-wrap gap-2 mb-2 text-caption text-medium-emphasis">
        <VChip
          size="x-small"
          variant="text"
          prepend-icon="tabler-user"
        >
          Laravel News
        </VChip>
        <VChip
          size="x-small"
          variant="text"
          prepend-icon="tabler-calendar"
        >
          12 thg 3, 2024
        </VChip>
        <VChip
          size="x-small"
          variant="text"
          prepend-icon="tabler-clock"
        >
          8 phút đọc
        </VChip>
      </div>

      <VCardText class="pa-0 mb-3 text-caption text-medium-emphasis source-excerpt">
        Laravel 11 introduces several exciting new features including a new application structure, improved performance, and more developer experience enhancements that make building modern PHP applications even better...
      </VCardText>

      <div class="d-flex flex-wrap gap-1">
        <VChip
          v-for="tag in sourceTags"
          :key="tag"
          size="x-small"
          variant="tonal"
          color="primary"
        >
          {{ tag }}
        </VChip>
      </div>
    </VCardText>
  </VCard>
</template>

<style scoped>
.banner-overlay {
  background: rgba(18, 14, 38, 0.7);
}

.source-excerpt {
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
  line-height: 1.4;
}
</style>
