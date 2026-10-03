<!--
  =====================================================================
  CHỨC NĂNG FILE: Xác nhận xóa hoặc chọn nhóm field để tạo lại content AI.
  CÁC HÀM/METHOD TRONG FILE: watcher action, emit confirm/close.
  INPUT/OUTPUT CỦA CLASS (tổng thể): action/item/busy/error -> options xác nhận.
  =====================================================================
-->
<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  action: { type: Object, default: null },
  busy: { type: Boolean, default: false },
  error: { type: String, default: '' },
})

const emit = defineEmits(['confirm', 'close'])
const fields = ref([])
const instructions = ref('')
const removing = computed(() => props.action?.kind === 'remove')

const fieldOptions = [
  { title: 'Tiêu đề', value: 'title' },
  { title: 'Tóm tắt', value: 'excerpt' },
  { title: 'Nội dung', value: 'content' },
  { title: 'SEO', value: 'seo' },
  { title: 'Danh mục / tag', value: 'taxonomy' },
  { title: 'Thumbnail', value: 'thumbnail' },
]

// Input: mở action mới. Output: reset tùy chọn, không giữ dữ liệu của item trước.
watch(() => props.action, () => { fields.value = []; instructions.value = '' })
</script>

<template>
  <VDialog
    :model-value="Boolean(props.action)"
    :persistent="props.busy"
    max-width="580"
    @update:model-value="!$event && emit('close')"
  >
    <DialogCloseBtn
      :disabled="props.busy"
      aria-label="Đóng hộp thoại content AI"
      @click="emit('close')"
    />
    <VCard :title="removing ? 'Xóa content AI' : 'Tạo lại content AI'">
      <VCardText>
        <p class="font-weight-medium text-wrap">
          {{ props.action?.item.title }}
        </p>
        <p v-if="removing">
          Xóa bản content này khỏi danh sách. Tài nguyên đã được duyệt vẫn được giữ.
        </p>
        <template v-else>
          <p>Bản mới dùng nguồn và model của bản đã chọn. Bản cũ vẫn được giữ để đối chiếu.</p>
          <AppSelect
            v-model="fields"
            :items="fieldOptions"
            label="Phần cần tạo lại"
            placeholder="Toàn bộ bài"
            multiple
            chips
            clearable
            :disabled="props.busy"
            class="mb-4"
          />
          <AppTextarea
            v-model="instructions"
            label="Yêu cầu bổ sung"
            placeholder="Để trống để giữ yêu cầu cũ"
            maxlength="4000"
            rows="3"
            :disabled="props.busy"
          />
        </template>
        <VAlert
          v-if="props.error"
          type="error"
          variant="tonal"
          class="mt-4"
        >
          {{ props.error }}
        </VAlert>
      </VCardText>
      <VCardText class="d-flex justify-end gap-3">
        <VBtn
          variant="tonal"
          color="secondary"
          :disabled="props.busy"
          @click="emit('close')"
        >
          Hủy
        </VBtn>
        <VBtn
          :color="removing ? 'error' : 'primary'"
          :prepend-icon="removing ? 'tabler-trash' : 'tabler-refresh'"
          :loading="props.busy"
          :disabled="props.busy"
          @click="emit('confirm', { fields: fields ?? [], instructions })"
        >
          {{ removing ? 'Xóa' : 'Tạo lại' }}
        </VBtn>
      </VCardText>
    </VCard>
  </VDialog>
</template>
