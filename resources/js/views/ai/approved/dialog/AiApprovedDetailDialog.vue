<!--
  =====================================================================
  CHỨC NĂNG FILE: Dialog đọc chi tiết bản AI gốc đã duyệt.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: close().
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : detail/loading/error từ composable archive.
  - OUTPUT: nội dung source và bản AI gốc chỉ đọc; emit close/retry/after-leave.
  - SIDE EFFECT: không ghi archive/Post; HTML hiển thị qua component đã lọc.
  =====================================================================
-->
<script setup>
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import AiContentComparison from '@/views/ai/content/AiContentComparison.vue'

const props = defineProps({
  modelValue: Boolean,
  detail: { type: Object, default: null },
  loading: Boolean,
  error: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'close', 'retry', 'afterLeave'])

/** Input: nút đóng. Output: yêu cầu page đóng và cleanup sau transition. */
function close() {
  emit('update:modelValue', false)
  emit('close')
}
</script>

<template>
  <VDialog
    :model-value="props.modelValue"
    max-width="1280"
    scrollable
    @update:model-value="value => !value && close()"
    @after-leave="emit('afterLeave')"
  >
    <AppDialogLayout
      title="Bài AI đã duyệt"
      subtitle="Bản snapshot gốc được lưu dài hạn để tra cứu về sau."
      close-label="Đóng"
      @close="close"
    >
      <VAlert
        v-if="props.error"
        type="error"
        variant="tonal"
        class="mb-4"
      >
        {{ props.error }}
        <template #append>
          <VBtn
            variant="text"
            :loading="props.loading"
            @click="emit('retry')"
          >
            Tải lại
          </VBtn>
        </template>
      </VAlert>
      <VProgressLinear
        v-if="props.loading"
        indeterminate
        color="primary"
        class="mb-4"
      />
      <template v-if="props.detail">
        <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-5">
          <div>
            <h3 class="text-h5 font-weight-medium">
              {{ props.detail.title }}
            </h3>
            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ props.detail.context?.provider || 'Provider không xác định' }} ·
              {{ props.detail.context?.model || 'Model không xác định' }}
            </div>
            <VChip
              color="secondary"
              variant="tonal"
              size="small"
              class="mt-2"
            >
              {{ props.detail.target_type }} #{{ props.detail.target_id || '—' }}
            </VChip>
          </div>
          <VBtn
            v-if="props.detail.post"
            :to="{ name: 'apps-blog-post-list' }"
            variant="tonal"
            prepend-icon="tabler-news"
          >
            Xem danh sách Post #{{ props.detail.post.id }}
          </VBtn>
        </div>
        <VAlert
          v-if="!props.detail.original_available"
          type="info"
          variant="tonal"
          class="mb-4"
        >
          Bản AI gốc không còn đủ dữ liệu để đối chiếu. Archive vẫn giữ metadata duyệt và liên kết Post.
        </VAlert>
        <AiContentComparison
          :source="props.detail.source"
          :draft="props.detail.draft"
          :loading="props.loading"
          :active="Boolean(props.detail.original_available)"
          original
        />
        <VRow class="mt-2">
          <VCol
            cols="12"
            md="6"
          >
            <VCard
              variant="outlined"
              class="pa-4 h-100"
            >
              <div class="text-subtitle-2 mb-3">
                Thông tin lưu kho
              </div>
              <dl class="text-body-2 d-grid gap-2">
                <div>
                  <dt class="text-medium-emphasis d-inline">
                    Ngày duyệt:
                  </dt><dd class="d-inline">
                    {{ props.detail.approved_at || '—' }}
                  </dd>
                </div>
                <div>
                  <dt class="text-medium-emphasis d-inline">
                    Generation:
                  </dt><dd class="d-inline">
                    #{{ props.detail.generation_no }}
                  </dd>
                </div>
                <div>
                  <dt class="text-medium-emphasis d-inline">
                    Nguồn gốc:
                  </dt><dd class="d-inline">
                    {{ props.detail.content_origin }}
                  </dd>
                </div>
              </dl>
            </VCard>
          </VCol>
          <VCol
            cols="12"
            md="6"
          >
            <VCard
              variant="outlined"
              class="pa-4 h-100"
            >
              <div class="text-subtitle-2 mb-3">
                Model liên kết
              </div>
              <div class="text-body-2">
                {{ props.detail.target_type === 'post' ? (props.detail.post ? `#${props.detail.post.id} · ${props.detail.post.title}` : 'Post đã bị xóa; snapshot vẫn còn trong kho.') : 'Target này chưa có màn hình liên kết riêng.' }}
              </div>
              <VChip
                v-if="props.detail.post"
                size="small"
                variant="tonal"
                color="primary"
                class="mt-3"
              >
                {{ props.detail.post.status }}
              </VChip>
            </VCard>
          </VCol>
        </VRow>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>
