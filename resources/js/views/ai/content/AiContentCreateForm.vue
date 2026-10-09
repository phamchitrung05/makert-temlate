<!--
  =====================================================================
  CHỨC NĂNG FILE: Cột tạo bài AI mới bằng nguồn và tùy chọn của project.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE:
  - emit('generate'): yêu cầu gửi nguồn mới vào hàng đợi.
  - emit('retryRun'): yêu cầu thử lại run đã thất bại.
  - emit('reloadCatalog'): tải lại cấu hình/model cho nguồn hiện tại.
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : nguồn, catalog và lifecycle từ page/composable.
  - OUTPUT: sự kiện gửi nguồn và lỗi request; chỉ khóa các trường trong lúc POST.
  =====================================================================
-->
<script setup>
import AiContentSourceForm from './AiContentSourceForm.vue'
import AiPipelineReport from '@/views/ai/shared/AiPipelineReport.vue'

const props = defineProps({
  catalog: { type: Object, required: true },
  generation: { type: Object, required: true },
})

const emit = defineEmits(['generate', 'retryRun', 'reloadCatalog'])
const source = defineModel({ type: Object, required: true })
</script>

<template>
  <VCard>
    <VCardItem
      title="Tạo bài viết AI mới"
      subtitle="Nhập nguồn và chọn model để AI viết bài. Kết quả được lưu trong danh sách chờ duyệt."
    >
      <template #prepend>
        <VAvatar
          color="primary"
          variant="tonal"
          rounded
          class="me-3"
        >
          <VIcon icon="tabler-wand" />
        </VAvatar>
      </template>
    </VCardItem>
    <VCardText>
      <fieldset
        :disabled="props.generation.busy"
        class="ai-content-create__fields"
      >
        <AppSelect
          id="ai-content-target"
          :model-value="source.targetType"
          :items="props.catalog.targetOptions ?? []"
          label="Tài nguyên sẽ tạo"
          placeholder="Chọn tài nguyên"
          :disabled="props.generation.busy || props.catalog.loading"
          class="mb-4"
          @update:model-value="source = { ...source, targetType: $event }"
        />
        <AiContentSourceForm
          v-model="source"
          :catalog="props.catalog"
          :disabled="props.generation.busy"
          @reload-catalog="emit('reloadCatalog')"
        />
      </fieldset>
      <VAlert
        v-if="props.generation.error"
        type="error"
        variant="tonal"
        class="mb-4"
        role="alert"
      >
        {{ props.generation.error }}
        <VBtn
          v-if="props.generation.canRetry"
          variant="text"
          size="small"
          prepend-icon="tabler-refresh"
          class="mt-2"
          @click="emit('retryRun')"
        >
          Thử lại tác vụ
        </VBtn>
      </VAlert>
      <div
        v-if="props.generation.busy"
        class="mb-4"
        role="status"
        aria-live="polite"
      >
        <div class="text-body-2 mb-2">
          Đang gửi nguồn vào hàng đợi…
        </div>
        <VProgressLinear
          color="primary"
          rounded
          height="6"
          indeterminate
          :model-value="0"
        />
      </div>
      <div
        v-if="!props.generation.busy && props.generation.blockedReason"
        class="text-body-2 text-medium-emphasis mb-3"
        role="status"
      >
        {{ props.generation.blockedReason }}
      </div>
      <div class="d-flex justify-end">
        <VBtn
          prepend-icon="tabler-wand"
          :disabled="!props.generation.canGenerate"
          :loading="props.generation.busy"
          @click="emit('generate')"
        >
          Phân tích &amp; Tạo content
        </VBtn>
      </div>
      <AiPipelineReport :session="props.generation.session" />
    </VCardText>
  </VCard>
</template>

<style scoped>
.ai-content-create__fields {
  padding: 0;
  border: 0;
  min-inline-size: 0;
}
</style>
