<!--
  =====================================================================
  CHỨC NĂNG FILE: Thẻ hiển thị một Visual Style trong lưới danh sách.
  =====================================================================
  Card tóm tắt style: thumbnail (ảnh tham chiếu khi có, icon placeholder khi
  không có URL), tên, category, chip trạng thái lifecycle, số shot recipe và
  hành động view/edit/duplicate/archive. Không gọi API — phát event lên page.
  Dùng trong `pages/visual-styles/index.vue`.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - statusMeta: computed map status → nhãn/màu chip.
  - thumbnailUrl: computed URL ảnh mẫu nếu style có (backend trả `reference_url`).
  - props/emits: `style` vào; edit/duplicate/archive ra.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : prop `style` (VisualStyleResource) và `busy` khóa hành động.
  - OUTPUT: UI card + emit 'edit' | 'duplicate' | 'archive' | 'remove' (edit mở dialog).
  =====================================================================
-->
<script setup>
import { computed } from 'vue'

const props = defineProps({
  style: { type: Object, required: true },
  busy: { type: Boolean, default: false },
})

const emit = defineEmits(['edit', 'duplicate', 'archive', 'remove'])

// Map trạng thái lifecycle style → nhãn/màu chip hiển thị
const STATUS_META = {
  draft: { label: 'Nháp', color: 'secondary' },
  queued: { label: 'Chờ phân tích', color: 'warning' },
  analyzing: { label: 'Đang phân tích', color: 'info' },
  analyzed: { label: 'Đã phân tích', color: 'info' },
  ready: { label: 'Sẵn sàng', color: 'info' },
  active: { label: 'Hoạt động', color: 'success' },
  failed: { label: 'Lỗi', color: 'error' },
  archived: { label: 'Đã lưu trữ', color: 'secondary' },
}

const statusMeta = computed(() => STATUS_META[props.style?.status] ?? { label: props.style?.status ?? '—', color: 'secondary' })

// URL ảnh tham chiếu — backend trả khi có; giữ placeholder khi không có
const thumbnailUrl = computed(() => props.style?.reference_url ?? props.style?.thumbnail_url ?? null)

const canArchive = computed(() => !['archived', 'queued', 'analyzing'].includes(props.style?.status))
const isProcessing = computed(() => ['queued', 'analyzing'].includes(props.style?.status))
</script>

<template>
  <VCard class="h-100 d-flex flex-column">
    <!-- Thumbnail: ảnh mẫu hoặc placeholder tonal -->
    <div class="visual-style-card__thumb position-relative">
      <VImg
        v-if="thumbnailUrl"
        :src="thumbnailUrl"
        cover
        height="168"
      />
      <div
        v-else
        class="d-flex flex-column align-center justify-center text-medium-emphasis visual-style-card__placeholder"
      >
        <VIcon
          size="40"
          :icon="isProcessing ? 'tabler-loader-2' : 'tabler-photo'"
        />
        <span class="text-caption mt-1">{{ isProcessing ? 'Đang phân tích...' : 'Style DNA' }}</span>
      </div>

      <VChip
        :color="statusMeta.color"
        variant="tonal"
        size="small"
        class="visual-style-card__status"
      >
        {{ statusMeta.label }}
      </VChip>
    </div>

    <VCardText class="pb-2">
      <div class="text-subtitle-1 font-weight-medium text-truncate">
        {{ style.name }}
      </div>
      <div class="text-body-2 text-medium-emphasis text-truncate">
        {{ style.category || 'Chưa phân nhóm' }}
      </div>
      <div
        v-if="style.error_message"
        class="text-caption text-error mt-1 text-truncate"
      >
        {{ style.error_message }}
      </div>
    </VCardText>

    <VCardText class="pt-0 d-flex align-center justify-space-between">
      <div class="d-flex align-center text-body-2 text-medium-emphasis">
        <VIcon
          size="18"
          icon="tabler-camera"
          class="me-1"
        />
        {{ style.shots_count ?? style.shots?.length ?? 0 }} recipes
      </div>

      <div class="d-flex align-center gap-1">
        <VTooltip text="Chỉnh sửa">
          <template #activator="{ props: tip }">
            <VBtn
              v-bind="tip"
              icon="tabler-edit"
              variant="text"
              size="small"
              :disabled="busy"
              @click="emit('edit', style)"
            />
          </template>
        </VTooltip>
        <VTooltip text="Nhân bản">
          <template #activator="{ props: tip }">
            <VBtn
              v-bind="tip"
              icon="tabler-copy"
              variant="text"
              size="small"
              :disabled="busy"
              @click="emit('duplicate', style)"
            />
          </template>
        </VTooltip>
        <VTooltip text="Lưu trữ">
          <template #activator="{ props: tip }">
            <VBtn
              v-bind="tip"
              icon="tabler-archive"
              variant="text"
              size="small"
              :disabled="busy || !canArchive"
              @click="emit('archive', style)"
            />
          </template>
        </VTooltip>
        <VTooltip text="Xóa">
          <template #activator="{ props: tip }">
            <VBtn
              v-bind="tip"
              icon="tabler-trash"
              variant="text"
              size="small"
              color="error"
              :disabled="busy || isProcessing"
              @click="emit('remove', style)"
            />
          </template>
        </VTooltip>
      </div>
    </VCardText>
  </VCard>
</template>

<style scoped>
.visual-style-card__thumb {
  background-color: rgba(var(--v-theme-on-surface), 0.04);
}

.visual-style-card__placeholder {
  block-size: 168px;
}

.visual-style-card__status {
  position: absolute;
  inset-block-start: 10px;
  inset-inline-end: 10px;
}
</style>
