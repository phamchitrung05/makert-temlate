<!--
  =====================================================================
  CHỨC NĂNG FILE: Điều phối danh sách và form tạo bài AI mới độc lập.
  =====================================================================
  Page dùng header giống Post, catalog AI Settings và API session hiện có.

  CÁC HÀM/METHOD TRONG FILE:
  - useAiContentWorkspace()/useAiContentCatalog(): nguồn và catalog/list thật.
  - useAiContentGeneration(): tạo và polling, không tự apply bài vào Post.
  - createNew(): reset nguồn khi không đang chạy tác vụ.
  - onMounted(): tải list và catalog độc lập.

  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : thao tác tìm kiếm và tạo bài viết mới.
  - OUTPUT: giao diện hai cột theo component/theme của project.
  - SIDE EFFECT: GET catalog/list, POST create và GET polling qua Admin API.
  =====================================================================
-->
<script setup>
import { onMounted } from 'vue'
import AiContentList from '@/views/ai/content/AiContentList.vue'
import AiContentCreateForm from '@/views/ai/content/AiContentCreateForm.vue'
import { useAiContentWorkspace } from '@/composables/useAiContentWorkspace'
import { useAiContentCatalog } from '@/composables/useAiContentCatalog'
import { useAiContentGeneration } from '@/composables/useAiContentGeneration'

const { items, source, listLoading, listError, loadItems, updateSession, resetSource } = useAiContentWorkspace()
const { catalog, loadCatalog } = useAiContentCatalog(source)
const { generation, generate, retryRun, reset, resumePolling } = useAiContentGeneration(source, catalog, updateSession)

onMounted(loadCatalog)
onMounted(loadItems)

/** Input: thao tác tạo mới. Output: reset form/thông báo, giữ list; không reset khi AI đang chạy. */
function createNew() {
  if (reset()) resetSource()
}
</script>

<template>
  <div class="ai-content-page">
    <div class="d-flex flex-wrap justify-space-between gap-y-4 gap-x-6 mb-6">
      <div>
        <h4 class="text-h4 font-weight-medium">
          Ai Content
        </h4>
        <div class="text-body-1">
          Tạo bài viết bằng AI và quản lý kết quả chờ duyệt.
        </div>
      </div>
      <div class="d-flex flex-wrap align-center gap-4">
        <VBtn
          prepend-icon="tabler-plus"
          :disabled="generation.busy"
          @click="createNew"
        >
          Tạo content AI mới
        </VBtn>
      </div>
    </div>

    <VRow>
      <VCol
        cols="12"
        lg="5"
      >
        <AiContentList
          :items="items"
          :loading="listLoading"
          :error="listError"
          @reload="loadItems"
        />
      </VCol>
      <VCol
        cols="12"
        lg="7"
      >
        <AiContentCreateForm
          v-model="source"
          :catalog="catalog"
          :generation="generation"
          @generate="generate"
          @retry-run="retryRun"
          @resume-polling="resumePolling"
          @reload-catalog="loadCatalog"
        />
      </VCol>
    </VRow>
  </div>
</template>
