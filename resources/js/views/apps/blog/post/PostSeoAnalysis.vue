<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị điểm và checklist SEO realtime.
  CÁC HÀM/METHOD TRONG FILE: color (computed): màu theo score.
  INPUT/OUTPUT CỦA CLASS (tổng thể): analysis -> checklist, không sửa state.
  =====================================================================
-->
<script setup>
import { computed } from 'vue'

const props = defineProps({ analysis: { type: Object, required: true } })
const color = computed(() => props.analysis.score >= 80 ? 'success' : props.analysis.score >= 50 ? 'warning' : 'error')
</script>

<template>
  <VCard
    title="SEO Analysis"
    class="mb-6"
  >
    <VCardText>
      <div class="d-flex align-center ga-3 mb-4">
        <VProgressCircular
          :model-value="props.analysis.score"
          :color="color"
          :size="60"
          :width="6"
        >
          <span data-testid="seo-score">{{ props.analysis.score }}</span>
        </VProgressCircular>
        <div class="text-caption">
          Điểm hướng dẫn biên tập, không bảo đảm thứ hạng. Không chặn lưu bài.
        </div>
      </div>
      <ul class="seo-checks pa-0 ma-0">
        <li
          v-for="check in props.analysis.rules"
          :key="check.id"
          class="d-flex ga-2 mb-3"
          :data-testid="`seo-${check.id}`"
          :data-status="check.status"
        >
          <VIcon
            :icon="check.status === 'passed' ? 'tabler-circle-check-filled' : 'tabler-circle'"
            :color="check.status === 'passed' ? 'success' : 'secondary'"
            size="18"
          />
          <div class="text-caption">
            <div>{{ check.status === 'passed' ? 'Đạt: ' : check.status === 'na' ? 'N/A: ' : 'Chưa đạt: ' }}{{ check.label }} <strong>({{ check.progress }})</strong></div>
            <div
              v-if="check.status === 'pending'"
              class="text-medium-emphasis"
            >
              {{ check.hint }}
            </div>
          </div>
        </li>
      </ul>
    </VCardText>
  </VCard>
</template>

<style scoped>
.seo-checks { list-style: none; }
</style>
