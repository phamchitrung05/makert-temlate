<!--
  =====================================================================
  CHỨC NĂNG FILE: Ghép bộ lọc, datatable và điều hướng Add của Ai Prompt List.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE:
  - useAiPromptList(): cung cấp query, dữ liệu thật, loading/error và thao tác tải lại.
  - useAiPromptManagement(): CRUD manual/profile/version/default, refresh list sau ghi.
  - openEdit(): điều hướng một row tới trang Ai Prompt/Edit.
  - handleTaskReady(): tải lại list khi worker tạo profile draft.
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : thao tác tìm tên, đổi trang/số dòng, tải lại, mở Add/Edit và quản lý profile.
  - OUTPUT: danh sách profile đã lưu với trạng thái rỗng/lỗi rõ ràng.
  - SIDE EFFECT: API qua composable; worker ready làm tải lại List, manual vẫn ở dialog List.
  =====================================================================
-->
<script setup>
import { onMounted, onScopeDispose, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { useAiPromptList } from '@/composables/ai/prompt/useAiPromptList'
import { AI_TASK_READY_EVENT } from '@/composables/useAiTaskQueue'
import AiPromptListFilters from '@/views/ai/prompt/AiPromptListFilters.vue'
import AiPromptListTable from '@/views/ai/prompt/AiPromptListTable.vue'
import AiPromptCreateDialog from '@/views/ai/prompt/dialog/AiPromptCreateDialog.vue'
import AiPromptToggleDialog from '@/views/ai/prompt/dialog/AiPromptToggleDialog.vue'
import AiPromptDeleteDialog from '@/views/ai/prompt/dialog/AiPromptDeleteDialog.vue'
import { useAiPromptManagement } from '@/composables/ai/prompt/useAiPromptManagement'

// =====================================================================
// Input: query từ các component con. Output: state list thật và load() thử lại.
// =====================================================================
const { page, itemsPerPage, search, items, totalItems, isLoading, error, load } = useAiPromptList()
const manager = reactive(useAiPromptManagement(load))
const router = useRouter()

/**
 * =====================================================================
 * CHỨC NĂNG: Mở trang Edit từ một dòng List, không mở dialog chỉnh sửa cũ.
 * Input: profile row có thể kèm analysis_metadata.analysis_id. Output: route Edit.
 * Side effect: navigation; không gọi mutation hoặc xóa profile.
 * =====================================================================
 */
function openEdit(item) {
  if (!router) return

  const query = { profile: String(item.id) }
  const analysisId = item.analysis_metadata?.analysis_id
  if (/^[\da-f]{8}(?:-[\da-f]{4}){3}-[\da-f]{12}$/iu.test(String(analysisId ?? ''))) query.analysis = analysisId

  router.push({ path: '/ai/prompt/edit', query })
}

function handleTaskReady() {
  void load()
}

onMounted(() => {
  window.addEventListener(AI_TASK_READY_EVENT, handleTaskReady)
})

onScopeDispose(() => {
  if (typeof window !== 'undefined') window.removeEventListener(AI_TASK_READY_EVENT, handleTaskReady)
})
</script>

<template>
  <div class="ai-prompt-list">
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-6">
      <div>
        <div class="d-flex align-center flex-wrap gap-3 mb-1">
          <h4 class="text-h4 font-weight-medium">
            Ai Prompt
          </h4>
          <VChip
            v-if="!isLoading && !error"
            color="primary"
            variant="tonal"
            size="small"
          >
            {{ totalItems }} mẫu
          </VChip>
        </div>
        <div class="text-body-1 text-medium-emphasis">
          Danh sách prompt văn phong, gồm cả bản nháp vừa được worker tạo để bạn duyệt.
        </div>
      </div>
      <div class="d-flex flex-wrap gap-3">
        <VBtn
          color="secondary"
          variant="tonal"
          prepend-icon="tabler-refresh"
          :loading="isLoading"
          :disabled="isLoading"
          @click="load"
        >
          Tải lại
        </VBtn>
        <VBtn
          :to="{ name: 'ai-prompt-add' }"
          prepend-icon="tabler-plus"
        >
          Thêm văn phong
        </VBtn>
        <VBtn
          variant="tonal"
          prepend-icon="tabler-edit"
          :disabled="Boolean(manager.action)"
          @click="manager.open('create')"
        >
          Nhập thủ công
        </VBtn>
      </div>
    </div>

    <VCard>
      <VAlert
        v-if="manager.notice"
        type="success"
        variant="tonal"
        class="ma-4"
        closable
        @click:close="manager.notice = ''"
      >
        {{ manager.notice }}
      </VAlert>
      <VCardText>
        <AiPromptListFilters v-model:search="search" />
      </VCardText>
      <VDivider />
      <VAlert
        v-if="error"
        type="error"
        variant="tonal"
        class="ma-4"
      >
        {{ error }}
      </VAlert>
      <AiPromptListTable
        v-model:page="page"
        v-model:items-per-page="itemsPerPage"
        :items="items"
        :total-items="totalItems"
        :loading="isLoading"
        :disabled="Boolean(manager.action)"
        :empty-text="error ? 'Không tải được danh sách.' : search?.trim() ? 'Không tìm thấy văn phong phù hợp.' : 'Chưa có văn phong đã lưu.'"
        @edit="openEdit"
        @toggle="manager.open('toggle', $event)"
        @remove="manager.open('delete', $event)"
      />
    </VCard>
    <AiPromptCreateDialog
      :state="manager"
      @update-form="manager.form = $event"
      @save="manager.save"
      @load="manager.load"
      @close="manager.close"
      @after-leave="manager.finishClose('create')"
    />
    <AiPromptToggleDialog
      :state="manager"
      @confirm="manager.confirm"
      @load="manager.load"
      @close="manager.close"
      @after-leave="manager.finishClose('toggle')"
    />
    <AiPromptDeleteDialog
      :state="manager"
      @confirm="manager.confirm"
      @load="manager.load"
      @close="manager.close"
      @after-leave="manager.finishClose('delete')"
    />
  </div>
</template>

<style scoped>
.ai-prompt-list {
  min-inline-size: 0;
}
</style>
