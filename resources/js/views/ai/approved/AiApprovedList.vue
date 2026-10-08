<!--
  =====================================================================
  CHỨC NĂNG FILE: Ghép filter, datatable và dialog của AI Approved.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: emptyText(), view().
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : thao tác admin với kho bài AI đã duyệt.
  - OUTPUT: panel filter phía trên, datatable phía dưới và dialog chi tiết.
  - SIDE EFFECT: GET API qua composable; không có thao tác lưu/xóa archive.
  =====================================================================
-->
<script setup>
import { computed } from 'vue'
import { useAiArticleArchives } from '@/composables/ai/content/useAiArticleArchives'
import AiApprovedFilters from './AiApprovedFilters.vue'
import AiApprovedTable from './AiApprovedTable.vue'
import AiApprovedDetailDialog from './dialog/AiApprovedDetailDialog.vue'

const archive = useAiArticleArchives()

const emptyText = computed(() => archive.error.value
  ? 'Không tải được kho bài AI.'
  : archive.search.value?.trim() || archive.createdFrom.value || archive.createdTo.value
    ? 'Không tìm thấy bài AI phù hợp.' : 'Chưa có bài AI nào được duyệt.')
</script>

<template>
  <div class="ai-approved-list">
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-6">
      <div>
        <div class="d-flex align-center flex-wrap gap-3 mb-1">
          <h4 class="text-h4 font-weight-medium">
            AI Approved
          </h4>
          <VChip
            v-if="!archive.isLoading.value && !archive.error.value"
            color="primary"
            variant="tonal"
            size="small"
          >
            {{ archive.totalItems.value }} bài
          </VChip>
        </div>
        <div class="text-body-1 text-medium-emphasis">
          Các bài viết do AI tạo đã được duyệt và lưu snapshot dài hạn.
        </div>
      </div>
      <VBtn
        color="secondary"
        variant="tonal"
        prepend-icon="tabler-refresh"
        :loading="archive.isLoading.value"
        :disabled="archive.isLoading.value"
        @click="archive.load"
      >
        Tải lại
      </VBtn>
    </div>
    <AiApprovedFilters
      v-model:search="archive.search.value"
      v-model:created-from="archive.createdFrom.value"
      v-model:created-to="archive.createdTo.value"
    />
    <VAlert
      v-if="archive.error.value"
      type="error"
      variant="tonal"
      class="mb-4"
    >
      {{ archive.error.value }}
      <template #append>
        <VBtn
          variant="text"
          :loading="archive.isLoading.value"
          @click="archive.load"
        >
          Tải lại
        </VBtn>
      </template>
    </VAlert>
    <AiApprovedTable
      v-model:page="archive.page.value"
      v-model:items-per-page="archive.itemsPerPage.value"
      :items="archive.items.value"
      :total-items="archive.totalItems.value"
      :loading="archive.isLoading.value"
      :empty-text="emptyText"
      @view="archive.open($event.id)"
    />
    <AiApprovedDetailDialog
      v-model="archive.detailVisible.value"
      :detail="archive.detail.value"
      :loading="archive.detailLoading.value"
      :error="archive.detailError.value"
      @retry="archive.open(archive.detail.value?.id)"
      @close="archive.close"
      @after-leave="archive.finishClose"
    />
  </div>
</template>

<style scoped>
.ai-approved-list {
  min-inline-size: 0;
}
</style>
