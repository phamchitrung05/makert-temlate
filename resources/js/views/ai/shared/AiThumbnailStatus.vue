<!--
  CHỨC NĂNG FILE: Preview/progress/lỗi thumbnail AI và thao tác ảnh độc lập.
  HÀM: pending (computed). INPUT/OUTPUT: asset/generation -> emit retry/check/cancel.
  SIDE EFFECT: không API; lỗi ảnh giữ nội dung bài viết.
-->
<script setup>
import { computed } from 'vue'

const props = defineProps({ asset: { type: Object, default: null }, generation: { type: Object, default: null },
  preview: { type: Boolean, default: true }, interactive: Boolean, busy: Boolean })

const emit = defineEmits(['retry', 'check', 'cancel'])
const pending = computed(() => ['queued', 'generating'].includes(props.generation?.status))
</script>

<template>
  <div
    v-if="props.generation || (props.preview && props.asset)"
    class="mt-3"
    role="status"
    aria-live="polite"
  >
    <VImg
      v-if="props.preview && props.asset?.file"
      :src="props.asset.file.preview_url || props.asset.file.url"
      :alt="props.asset.alt_text || 'Ảnh đại diện'"
      max-height="240"
      rounded
      class="mb-2"
    />
    <template v-if="pending">
      <div class="text-caption text-primary">
        {{ props.generation.status === 'queued' ? 'Đang chờ tạo thumbnail AI…' : 'Đang tạo thumbnail AI…' }}
      </div>
      <VProgressLinear
        :model-value="props.generation.progress ?? 0"
        :indeterminate="props.generation.status === 'queued'"
        class="mt-2"
      />
      <template v-if="props.interactive">
        <VBtn
          size="small"
          variant="text"
          :disabled="props.busy"
          @click="emit('check')"
        >
          Cập nhật ảnh
        </VBtn>
        <VBtn
          size="small"
          variant="text"
          color="secondary"
          :disabled="props.busy"
          @click="emit('cancel')"
        >
          Hủy tạo ảnh
        </VBtn>
      </template>
    </template>
    <div
      v-else-if="props.generation?.status === 'ready'"
      class="text-caption text-success"
    >
      Đã tạo thumbnail AI.
    </div>
    <template v-else-if="props.generation">
      <div class="text-caption text-warning text-wrap">
        {{ props.generation.error || 'Chưa tạo được thumbnail. Nội dung bài viết vẫn được giữ.' }}
      </div>
      <VBtn
        v-if="props.interactive && props.generation.job_id"
        size="small"
        variant="text"
        :loading="props.busy"
        :disabled="props.busy"
        @click="emit('retry')"
      >
        Thử lại ảnh
      </VBtn>
      <div
        v-else-if="!props.generation.job_id"
        class="text-caption"
      >
        Chọn Tạo lại và hạng mục Ảnh đại diện để thử với model ảnh khác.
      </div>
    </template>
  </div>
</template>
