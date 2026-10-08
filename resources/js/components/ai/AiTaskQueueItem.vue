<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị một dòng task trong popup hàng đợi AI.
  =====================================================================

  Component này giữ phần trình bày lifecycle, thời gian và thao tác của một task
  riêng khỏi container queue. Container chịu trách nhiệm lấy dữ liệu/polling;
  component phát event để mở kết quả, xem lỗi hoặc hủy task.

  CÁC HÀM/METHOD TRONG FILE:
  - formatTaskTime(): định dạng mốc thời gian của task.
  - taskSubtitle(): chọn mô tả theo source task.
  - canCancel: computed kiểm tra task có thể hủy.
  - statusMeta/statusIconBackground: computed metadata và màu nền theo theme.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : task summary, cancellingId và event handler từ queue container.
  - OUTPUT: row UI; emit open, error hoặc cancel với task tương ứng.
  =====================================================================
-->
<script setup>
import { computed } from 'vue'
import { aiTaskStatusMeta } from '@/composables/useAiTaskQueue'

const props = defineProps({
  task: { type: Object, required: true },
  cancellingId: { type: String, default: '' },
})

const emit = defineEmits(['open', 'error', 'cancel'])

const canCancel = computed(() => ['queued', 'analyzing'].includes(props.task.status) && props.cancellingId !== props.task.id)
const statusMeta = computed(() => aiTaskStatusMeta(props.task.status))
const statusIconBackground = computed(() => `rgba(var(--v-theme-${statusMeta.value.color}), 0.12)`)

/**
 * =====================================================================
 * CHỨC NĂNG: Hiển thị thời gian task theo múi giờ người dùng.
 * =====================================================================
 * INPUT: task summary.
 * OUTPUT: chuỗi tương đối ngắn hoặc ngày giờ.
 * SIDE EFFECT: không gọi API.
 * =====================================================================
 */
function formatTaskTime(task) {
  const value = task.completed_at || task.started_at || task.created_at
  if (!value) return '—'

  const timestamp = new Date(value).getTime()
  if (Number.isNaN(timestamp)) return '—'

  const minutes = Math.max(0, Math.floor((Date.now() - timestamp) / 60000))
  if (minutes < 1) return 'Vừa xong'
  if (minutes < 60) return `${minutes} phút trước`

  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours} giờ trước`

  return new Intl.DateTimeFormat('vi-VN', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(timestamp))
}

/**
 * =====================================================================
 * CHỨC NĂNG: Tạo mô tả ngắn từ task type/source allowlist.
 * =====================================================================
 * INPUT: task summary.
 * OUTPUT: subtitle hiển thị trong row.
 * SIDE EFFECT: hàm thuần.
 * =====================================================================
 */
function taskSubtitle(task) {
  if (task.source === 'ai_writing_profile') return 'Phân tích văn phong'

  return 'Tác vụ AI'
}
</script>

<template>
  <div class="task-item pa-2 my-1 rounded d-flex align-center justify-space-between">
    <div class="d-flex align-center task-item__identity">
      <VAvatar
        :color="statusIconBackground"
        rounded="lg"
        size="34"
        class="me-2 flex-shrink-0"
      >
        <VIcon
          :color="statusMeta.color"
          size="18"
        >
          {{ statusMeta.icon }}
        </VIcon>
      </VAvatar>
      <div class="text-truncate">
        <div class="text-caption font-weight-bold text-high-emphasis text-truncate">
          {{ task.name }}
        </div>
        <div class="text-caption text-medium-emphasis text-truncate task-item__subtitle">
          {{ taskSubtitle(task) }}
        </div>
      </div>
    </div>

    <div class="d-flex align-center justify-end flex-grow-1 ms-2 task-item__status">
      <div
        v-if="task.status === 'analyzing'"
        class="d-flex align-center gap-2"
      >
        <div class="task-progress">
          <VProgressLinear
            indeterminate
            color="primary"
            height="4"
            rounded
          />
        </div>
        <div class="text-caption text-medium-emphasis text-no-wrap task-time">
          {{ formatTaskTime(task) }}
        </div>
      </div>
      <div
        v-else
        class="d-flex align-center gap-2"
      >
        <div
          class="text-caption d-flex align-center font-weight-medium text-no-wrap"
          :class="`text-${statusMeta.color}`"
        >
          <VIcon
            size="14"
            class="me-1"
          >
            {{ statusMeta.icon }}
          </VIcon>
          {{ statusMeta.label }}
        </div>
        <div class="text-caption text-medium-emphasis text-no-wrap task-time">
          {{ formatTaskTime(task) }}
        </div>
      </div>

      <VBtn
        v-if="task.status === 'ready'"
        icon="tabler-external-link"
        variant="text"
        size="x-small"
        color="primary"
        class="ms-1"
        aria-label="Mở kết quả"
        @click="emit('open', task)"
      />
      <VMenu
        v-else-if="task.status === 'failed' || canCancel"
        location="start"
      >
        <template #activator="{ props: menuProps }">
          <VBtn
            v-bind="menuProps"
            icon="tabler-dots-vertical"
            variant="text"
            size="x-small"
            color="secondary"
            class="ms-1"
            aria-label="Thao tác task"
          />
        </template>
        <VList
          density="compact"
          min-width="160"
        >
          <VListItem
            v-if="task.status === 'failed'"
            prepend-icon="tabler-alert-circle"
            title="Xem lỗi"
            @click="emit('error', task)"
          />
          <VListItem
            v-if="canCancel"
            prepend-icon="tabler-ban"
            title="Hủy tác vụ"
            :disabled="props.cancellingId === task.id"
            @click="emit('cancel', task.id)"
          />
        </VList>
      </VMenu>
    </div>
  </div>
</template>

<style scoped>
.gap-2 { gap: 8px; }

.task-item {
  border: 1px solid transparent;
  transition: background-color 0.2s ease, border-color 0.2s ease;
}

.task-item:hover {
  border-color: rgba(var(--v-theme-primary), 0.12);
  background-color: rgba(var(--v-theme-primary), 0.04);
}

.task-item__identity {
  max-inline-size: 50%;
  min-inline-size: 0;
}

.task-item__status { min-inline-size: 0; }

.task-item__subtitle { font-size: 11px !important; }

.task-time { font-size: 10px !important; }

.task-progress { inline-size: 75px; }

@media (max-width: 600px) {
  .task-item__identity { max-inline-size: 42%; }
}
</style>
