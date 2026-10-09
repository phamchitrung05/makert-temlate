<!--
  * =====================================================================
  * CHỨC NĂNG FILE: Popup theo dõi analysis/job AI trong Admin layout.
  * =====================================================================
  * CÁC HÀM/METHOD TRONG FILE:
  * - openTask(): điều hướng theo task_type khi task ready.
  * - showError(): mở dialog lỗi generic của task failed.
  * - errorDialogTitle(): chọn tiêu đề lỗi theo task_type.
  * - startQueue(): bắt đầu/dừng composable theo quyền Admin.
  * - canUseQueue, terminalTaskCount, queuedTaskCount, queueStatus,
  *   errorDialogVisible: computed quyền, badge, trạng thái và dialog.
  * - watcher quyền: giữ polling theo quyền Admin; task mới chỉ cập nhật badge.
  * INPUT/OUTPUT CỦA CLASS (tổng thể):
  * - INPUT : task summary từ useAiTaskQueue và thao tác mở/kết quả/hủy.
  * - OUTPUT: popup cố định, filter lifecycle và dialog lỗi bounded.
  * - SIDE EFFECT: router navigation, queue polling và cancel API qua composable.
  * =====================================================================
-->
<script setup>
import { computed, onMounted, shallowRef, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAdminAuthStore } from '@/stores/adminAuth'
import AiTaskQueueItem from '@/components/ai/AiTaskQueueItem.vue'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import { useAiTaskQueue } from '@/composables/useAiTaskQueue'

const auth = useAdminAuthStore()
const router = useRouter()
const queue = useAiTaskQueue()
const isOpen = shallowRef(false)
const isMinimized = shallowRef(false)
const errorTask = shallowRef(null)
const { tasks, filteredTasks, filters, currentFilter, activeCount, loading, cancellingId, error } = queue

const canUseQueue = computed(() => auth.isAuthenticated && auth.permissions.some(permission => ['ai_settings.manage', 'media.upload', 'posts.manage', 'resources.create'].includes(permission)))
const terminalTaskCount = computed(() => tasks.value.filter(task => ['ready', 'failed', 'cancelled', 'expired'].includes(task.status)).length)
const queuedTaskCount = computed(() => tasks.value.filter(task => task.status === 'queued').length)

const queueStatus = computed(() => {
  if (tasks.value.some(task => ['running', 'processing', 'analyzing'].includes(task.status))) return 'Đang xử lý'
  if (activeCount.value > 0) return 'Đang chờ'

  return 'Sẵn sàng'
})

const errorDialogVisible = computed({
  get: () => Boolean(errorTask.value),
  set: visible => { if (!visible) errorTask.value = null },
})

/**
 * =====================================================================
 * CHỨC NĂNG: Mở kết quả analysis đã sẵn sàng.
 * =====================================================================
 * Input: task ready. Output: route có analysis UUID để trang resume.
 * Side effect: navigation; không tạo analysis mới.
 * =====================================================================
 */
function openTask(task) {
  if (task.status !== 'ready') return

  if (task.task_type === 'writing_profile_analysis') {
    router.push({ path: '/ai/prompt/edit', query: { analysis: task.taskable_id || task.id, profile: task.draft_profile_id || undefined } })

    return
  }

  router.push({ path: '/ai/content', query: { session: task.parent_id || task.taskable_id } })
}

/**
 * =====================================================================
 * CHỨC NĂNG: Mở dialog lỗi của task failed.
 * =====================================================================
 * Input: task failed. Output: error dialog với message đã bounded.
 * Side effect: chỉ cập nhật UI state.
 * =====================================================================
 */
function showError(task) {
  errorTask.value = task
}

/**
 * =====================================================================
 * CHỨC NĂNG: Chọn tiêu đề lỗi phù hợp với từng loại worker AI.
 * =====================================================================
 * Input: task failed. Output: nhãn bounded cho dialog lỗi.
 * Side effect: hàm thuần; không đọc payload hoặc gọi API.
 * =====================================================================
 */
function errorDialogTitle(task) {
  return {
    'writing_profile_analysis': 'Phân tích văn phong thất bại',
    'article_generation': 'Tạo nội dung thất bại',
    'image_generation': 'Tạo ảnh thất bại',
  }[task?.task_type] || 'Tác vụ AI thất bại'
}

/**
 * =====================================================================
 * CHỨC NĂNG: Khởi động queue sau khi phiên Admin sẵn sàng.
 * =====================================================================
 * Input: auth state. Output: queue GET/polling khi có quyền.
 * Side effect: gọi composable start/stop.
 * =====================================================================
 */
function startQueue() {
  if (canUseQueue.value) queue.start()
  else queue.stop()
}

watch(canUseQueue, startQueue, { immediate: true })
onMounted(startQueue)
</script>

<template>
  <div
    id="view-moi"
    class="ai-task-queue-popup-host"
  >
    <VBadge
      v-if="canUseQueue"
      :content="queuedTaskCount"
      :model-value="queuedTaskCount > 0"
      color="warning"
      floating
      class="ai-task-queue-popup__toggle"
    >
      <VBtn
        color="primary"
        icon="tabler-list-check"
        density="comfortable"
        elevation="4"
        :aria-label="isOpen ? 'Ẩn hàng đợi AI' : `Mở hàng đợi AI (${queuedTaskCount} task đang chờ)`"
        @click="isOpen = !isOpen"
      />
    </VBadge>

    <VCard
      v-if="canUseQueue"
      v-show="isOpen"
      rounded="lg"
      elevation="10"
      border
      class="process-queue-popup"
    >
      <div class="queue-header px-4 py-3 d-flex align-center justify-space-between border-b gap-2">
        <div class="d-flex align-center">
          <VIcon
            color="primary"
            size="20"
            class="me-2"
          >
            tabler-list-details
          </VIcon>
          <span class="text-subtitle-2 font-weight-bold text-high-emphasis">
            Tiến trình xử lý ({{ tasks.length }})
          </span>
        </div>

        <div class="d-flex align-center gap-1">
          <div
            class="d-flex align-center me-2 text-caption font-weight-medium"
            :class="activeCount ? 'text-success' : 'text-medium-emphasis'"
          >
            <span
              v-if="activeCount"
              class="status-pulse-dot me-1"
            />
            {{ queueStatus }}
          </div>
          <VBtn
            :icon="isMinimized ? 'tabler-chevron-up' : 'tabler-minus'"
            variant="text"
            size="x-small"
            color="secondary"
            :aria-label="isMinimized ? 'Mở rộng hàng đợi AI' : 'Thu gọn hàng đợi AI'"
            @click="isMinimized = !isMinimized"
          />
          <VBtn
            icon="tabler-x"
            variant="text"
            size="x-small"
            color="secondary"
            aria-label="Ẩn hàng đợi AI"
            @click="isOpen = false"
          />
        </div>
      </div>

      <VExpandTransition>
        <div
          v-show="!isMinimized"
          class="queue-content d-flex flex-column"
        >
          <div class="px-3 pt-3 pb-2 d-flex gap-1 overflow-x-auto filter-bar">
            <VChip
              v-for="filter in filters"
              :key="filter.value"
              size="x-small"
              :color="currentFilter === filter.value ? 'primary' : 'secondary'"
              :variant="currentFilter === filter.value ? 'flat' : 'tonal'"
              class="font-weight-medium cursor-pointer flex-shrink-0"
              :aria-pressed="currentFilter === filter.value"
              :aria-label="`${filter.label}: ${filter.count} tác vụ`"
              @click="currentFilter = filter.value"
            >
              {{ filter.label }} {{ filter.count }}
            </VChip>
          </div>

          <div
            v-if="error"
            class="px-3 pb-2"
          >
            <VAlert
              type="error"
              variant="tonal"
              density="compact"
            >
              {{ error }}
              <template #append>
                <VBtn
                  size="small"
                  variant="text"
                  :loading="loading"
                  @click="queue.refresh"
                >
                  Tải lại
                </VBtn>
              </template>
            </VAlert>
          </div>

          <div class="task-list-container px-3 py-1">
            <div
              v-if="loading && !tasks.length"
              class="py-8 text-center text-medium-emphasis text-body-2"
            >
              Đang tải hàng đợi…
            </div>
            <div
              v-else-if="!filteredTasks.length"
              class="py-8 text-center text-medium-emphasis text-body-2"
            >
              Chưa có tác vụ phù hợp.
            </div>

            <AiTaskQueueItem
              v-for="task in filteredTasks"
              :key="task.id"
              :task="task"
              :cancelling-id="cancellingId"
              @open="openTask"
              @error="showError"
              @cancel="queue.cancelTask"
            />
          </div>

          <VDivider class="queue-footer-divider" />
          <div class="px-4 py-2-5 d-flex justify-space-between align-center bg-background queue-footer">
            <VBtn
              variant="tonal"
              color="error"
              size="small"
              prepend-icon="tabler-trash"
              class="text-none font-weight-medium px-2"
              :disabled="!terminalTaskCount"
              @click="queue.clearFinished"
            >
              Ẩn đã xong
            </VBtn>
            <RouterLink
              :to="{ name: 'ai-prompt-list' }"
              class="text-caption text-primary text-decoration-none font-weight-medium d-inline-flex align-center"
            >
              Xem văn phong
              <VIcon
                size="14"
                class="ms-1"
              >
                tabler-arrow-right
              </VIcon>
            </RouterLink>
          </div>
        </div>
      </VExpandTransition>
    </VCard>

    <VDialog
      v-model="errorDialogVisible"
      max-width="440"
      scrollable
    >
      <AppDialogLayout
        v-if="errorTask"
        :title="errorDialogTitle(errorTask)"
        :subtitle="errorTask.name"
        @close="errorTask = null"
      >
        <VCardText>
          <VAlert
            type="error"
            variant="tonal"
          >
            {{ errorTask.error_message || 'Không thể phân tích bài mẫu.' }}
          </VAlert>
          <div
            v-if="errorTask.error_code"
            class="text-caption text-medium-emphasis mt-3"
          >
            Mã lỗi: {{ errorTask.error_code }}
          </div>
        </VCardText>
      </AppDialogLayout>
    </VDialog>
  </div>
</template>

<style scoped>
.ai-task-queue-popup-host { display: contents; }

.ai-task-queue-popup__toggle,
.process-queue-popup {
  position: fixed;
  z-index: 1050;

  /* ScrollToTop dùng bottom 5%, right 25px; chừa chiều cao nút và khoảng hở. */
  inset-block-end: calc(5% + 64px);
  inset-inline-end: 25px;
}

.ai-task-queue-popup__toggle {
  z-index: 1060;
  inset-block-end: calc(5% + 64px);
}

.process-queue-popup {
  display: flex;
  flex-direction: column;
  block-size: min(620px, calc(95dvh - 136px));
  inline-size: 520px;
  inset-block-end: calc(5% + 120px);
  max-inline-size: calc(100vw - 32px);
}

.queue-header,
.filter-bar,
.queue-footer {
  flex-shrink: 0;
}

.queue-content {
  overflow: hidden;
  flex: 1 1 auto;
  min-block-size: 0;
}

.queue-footer {
  padding-block: 10px;
}

.queue-footer-divider { flex-shrink: 0; }

.task-list-container {
  overflow: hidden auto;
  flex: 1 1 0%;
  min-block-size: 0;
  overscroll-behavior: contain;
  scrollbar-gutter: stable;
}

.filter-bar { scrollbar-width: none; }
.filter-bar::-webkit-scrollbar { block-size: 0; }

.status-pulse-dot {
  display: inline-block;
  flex-shrink: 0;
  border-radius: 50%;
  animation: queue-pulse 1.8s infinite;
  background-color: rgb(var(--v-theme-success));
  block-size: 7px;
  inline-size: 7px;
}

@keyframes queue-pulse {
  0%,
  100% {
    box-shadow: 0 0 0 0 rgba(var(--v-theme-success), 0.6);
  }
  70% { box-shadow: 0 0 0 5px rgba(var(--v-theme-success), 0); }
}

.task-list-container::-webkit-scrollbar { inline-size: 4px; }

.task-list-container::-webkit-scrollbar-thumb {
  border-radius: 4px;
  background: rgb(var(--v-theme-perfect-scrollbar-thumb));
}

@media (max-width: 600px) {
  .ai-task-queue-popup__toggle,
  .process-queue-popup {
    inset-inline-end: 16px;
  }

  .ai-task-queue-popup__toggle { inset-block-end: calc(5% + 56px); }
  .process-queue-popup { inset-block-end: calc(5% + 112px); }
  .queue-header { flex-wrap: wrap; }
}

@media (prefers-reduced-motion: reduce) {
  .status-pulse-dot { animation: none; }
}
</style>
