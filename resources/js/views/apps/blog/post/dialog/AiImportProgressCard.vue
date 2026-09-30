<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị tiến trình từng bước của AI import.
  =====================================================================

  Component chỉ trình bày trạng thái queue do parent polling; không tự gọi API
  và không thay đổi lifecycle job.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - Không có hàm/computed/watcher; chỉ render props steps và elapsedTime.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : steps và elapsedTime từ dialog AI.
  - OUTPUT: progress card; không emit và không có side effect mạng.
  =====================================================================
-->
<script setup>
const props = defineProps({
  steps: {
    type: Array,
    default: () => [],
  },
  elapsedTime: {
    type: String,
    default: '00:00:00',
  },
})
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
          icon="tabler-robot"
          size="18"
          color="primary"
          class="me-2"
        />
      </template>
      <VCardTitle class="text-subtitle-2">
        Đang xử lý...
      </VCardTitle>
      <template #append>
        <span class="text-caption font-weight-medium text-medium-emphasis font-monospace">
          {{ props.elapsedTime }}
        </span>
      </template>
    </VCardItem>

    <VCardText>
      <VList
        lines="two"
        density="compact"
        class="bg-transparent pa-0"
      >
        <VListItem
          v-for="step in props.steps"
          :key="step.id"
          rounded="lg"
          class="mb-1 px-2"
          :class="{ 'process-step-active': step.status === 'processing' }"
        >
          <template #prepend>
            <VIcon
              v-if="step.status === 'done'"
              icon="tabler-circle-check"
              color="success"
              size="20"
              class="me-2"
            />
            <VAvatar
              v-else
              size="20"
              rounded="circle"
              variant="tonal"
              :color="step.status === 'processing' ? 'primary' : 'default'"
              class="me-2"
            >
              <span class="text-caption font-weight-bold">
                {{ step.id }}
              </span>
            </VAvatar>
          </template>

          <VListItemTitle class="text-caption font-weight-bold">
            {{ step.title }}
          </VListItemTitle>
          <VListItemSubtitle class="text-caption">
            {{ step.subtitle }}
          </VListItemSubtitle>

          <template #append>
            <span
              v-if="step.time"
              class="text-caption text-medium-emphasis font-monospace"
            >
              {{ step.time }}
            </span>
            <VProgressCircular
              v-else-if="step.status === 'processing'"
              indeterminate
              size="16"
              width="2"
              color="primary"
            />
          </template>
        </VListItem>
      </VList>
    </VCardText>
  </VCard>
</template>

<style scoped>
.process-step-active {
  background-color: rgba(var(--v-theme-primary), 0.08);
}
</style>
