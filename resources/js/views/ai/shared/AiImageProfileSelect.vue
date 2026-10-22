<!--
  =====================================================================
  CHỨC NĂNG FILE: Select prompt phong cách ảnh cho form AI image.
  =====================================================================
  Tải danh sách image profile (name → id) qua aiImageProfilesService.options()
  để gắn vào một v-model<number>. Dùng trong các form tạo/chỉnh nội dung AI.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - load(): GET options; lỗi ghi vào `error`, nút "Tải lại" gọi lại.
  - onMounted(load): nạp danh sách khi mount.
  - props/emits: `disabled` vào; v-model (id profile) ra.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : prop disabled.
  - OUTPUT: v-model cập nhật id image profile đã chọn.
  - SIDE EFFECT: GET /admin/ai/image-profiles options; không gọi provider.
  =====================================================================
-->
<script setup>
import { onMounted, ref } from 'vue'
import { aiImageProfilesService } from '@/services/aiImageProfiles'

defineProps({ disabled: Boolean })

const model = defineModel({ type: Number, default: null })
const options = ref([])
const loading = ref(false)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try { options.value = (await aiImageProfilesService.options()).map(item => ({ title: item.name, value: item.id })) }
  catch { error.value = 'Không tải được prompt ảnh.' }
  finally { loading.value = false }
}

onMounted(load)
</script>

<template>
  <div>
    <AppSelect
      v-model="model"
      :items="options"
      label="Prompt phong cách ảnh (tùy chọn)"
      clearable
      :disabled="disabled || loading"
      :loading="loading"
      hint="Tạo và bật style từ ảnh mẫu trong Visual Styles → Tạo style mới."
      persistent-hint
    />
    <div
      v-if="error"
      class="text-error mt-2"
    >
      {{ error }} <VBtn
        size="small"
        variant="text"
        :disabled="disabled || loading"
        @click="load"
      >
        Tải lại
      </VBtn>
    </div>
  </div>
</template>
