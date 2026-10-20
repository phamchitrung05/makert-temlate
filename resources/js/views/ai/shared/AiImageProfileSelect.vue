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
      hint="Tạo và bật prompt từ ảnh mẫu trong Ai Prompt → Prompt tạo ảnh."
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
