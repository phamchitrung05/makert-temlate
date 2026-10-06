<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị lịch sử chỉnh sửa/duyệt/từ chối đã lưu tại server.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: eventLabel/dateLabel; emits reload/more.
  INPUT/OUTPUT CỦA CLASS (tổng thể): items/pagination/loading/error -> lịch sử và retry.
  SIDE EFFECT: không tự gọi API, không dựng lịch sử từ trạng thái UI.
  =====================================================================
-->
<script setup>
const props = defineProps({ items: { type: Array, default: () => [] }, pagination: { type: Object, default: () => ({}) }, loading: Boolean, error: { type: String, default: '' } })
const emit = defineEmits(['reload', 'more'])

/** Input: mã Activitylog. Output: nhãn thao tác đã ghi. */
const eventLabel = value => ({ 'candidate.edited': 'Chỉnh sửa nội dung', 'candidate.approved': 'Duyệt thành Post nháp', 'candidate.rejected': 'Từ chối' }[value] ?? value)

/** Input: thời điểm server. Output: ngày giờ local hoặc nhãn thiếu dữ liệu. */
const dateLabel = value => value ? new Date(value).toLocaleString('vi-VN') : 'Chưa có thời điểm'
</script>

<template>
  <div class="mt-6">
    <h3 class="text-subtitle-1 mb-3">
      Lịch sử biên tập
    </h3>
    <VProgressLinear
      v-if="props.loading"
      indeterminate
      class="mb-3"
    />
    <VAlert
      v-if="props.error"
      type="error"
      variant="tonal"
      class="mb-3"
    >
      {{ props.error }}
      <VBtn
        variant="text"
        :disabled="props.loading"
        @click="emit('reload')"
      >
        Tải lại lịch sử
      </VBtn>
    </VAlert>
    <p
      v-if="!props.items.length && !props.loading && !props.error"
      class="text-body-2 text-medium-emphasis"
    >
      Chưa có thao tác biên tập được ghi nhận.
    </p>
    <div
      v-for="item in props.items"
      :key="item.id"
      class="ai-review-history__item"
    >
      <div class="text-body-2 font-weight-medium">
        {{ eventLabel(item.event) }}
      </div>
      <div class="text-caption text-medium-emphasis">
        {{ item.actor?.name || 'Tài khoản không còn tồn tại' }} · {{ dateLabel(item.at) }}
      </div>
      <p
        v-if="item.reason"
        class="text-body-2 mb-0"
      >
        Lý do: {{ item.reason }}
      </p>
      <div
        v-if="item.post_id"
        class="text-caption"
      >
        Post nháp #{{ item.post_id }}
      </div>
    </div>
    <VBtn
      v-if="props.pagination.current_page < props.pagination.last_page"
      variant="text"
      :loading="props.loading"
      @click="emit('more')"
    >
      Xem lịch sử trước
    </VBtn>
  </div>
</template>

<style scoped>
.ai-review-history__item {
  border-block-end: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  overflow-wrap: anywhere;
  padding-block: 10px;
}
</style>
