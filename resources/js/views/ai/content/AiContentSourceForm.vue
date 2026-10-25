<!--
  =====================================================================
  CHỨC NĂNG FILE: Form nguồn tạo AI (card trên) — tab loại nguồn và input.
  =====================================================================
  Giữ tabs chọn kiểu nguồn (URL/File/HTML/Text/Viết tự do) và input tương ứng.
  Nhóm tùy chọn/brief/phân loại/hình ảnh đã tách sang AiContentOptionsForm (card
  "Tùy chọn" bên dưới). Field social (nền tảng, encoding) theo targetType.

  CÁC HÀM/METHOD TRONG FILE:
  - update(): phát bản sao state của field được sửa.
  - useAiSourcePreview(): đọc URL/raw HTML/file, invalidate preview khi nguồn đổi.
  - inputTabs/isSocial: danh sách tab nguồn và cờ social theo catalog/target.

  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : v-model nguồn, disabled và catalog từ AI Settings.
  - OUTPUT: update:modelValue và reload-catalog khi người dùng tải lại.
  - SIDE EFFECT: preview/catalog qua API hiện có; không gọi model trực tiếp.
  =====================================================================
-->
<script setup>
import { computed } from 'vue'
import AiSourcePreview from '@/views/ai/shared/AiSourcePreview.vue'
import { useAiSourcePreview } from '@/composables/ai/useAiSourcePreview'

const props = defineProps({
  catalog: { type: Object, required: true },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['reloadCatalog'])
const source = defineModel({ type: Object, required: true })
const preview = useAiSourcePreview(source)

const allInputTabs = [
  { value: 'url', title: 'Link nguồn', icon: 'tabler-link' },
  { value: 'file', title: 'File HTML', icon: 'tabler-file-code' },
  { value: 'html', title: 'HTML', icon: 'tabler-code' },
  { value: 'text', title: 'Nội dung nguồn', icon: 'tabler-file-text' },
  { value: 'prompt', title: 'Viết tự do', icon: 'tabler-pencil' },
]

const inputTabs = computed(() => allInputTabs.filter(tab => (props.catalog.inputTypes ?? ['url', 'text']).includes(tab.value)))
const isSocial = computed(() => source.value.targetType === 'social_post')

/** Input: key/value của field. Output: state mới; không mutate object của parent. */
const update = (key, value) => { source.value = { ...source.value, [key]: value } }
</script>

<template>
  <div>
    <VTabs
      :model-value="source.type"
      :disabled="props.disabled"
      aria-label="Loại nguồn AI"
      class="mb-4"
      @update:model-value="update('type', $event)"
    >
      <VTab
        v-for="tab in inputTabs"
        :key="tab.value"
        :value="tab.value"
        :prepend-icon="tab.icon"
      >
        {{ tab.title }}
      </VTab>
    </VTabs>
    <VWindow :model-value="source.type">
      <VWindowItem value="url">
        <AppTextField
          :model-value="source.url"
          label="URL nguồn"
          placeholder="https://..."
          prepend-inner-icon="tabler-link"
          type="url"
          @update:model-value="update('url', $event)"
        />
      </VWindowItem>
      <VWindowItem value="file">
        <VFileInput
          :model-value="source.file"
          label="File HTML nguồn"
          accept=".html,.htm,text/html"
          prepend-icon=""
          prepend-inner-icon="tabler-file-upload"
          show-size
          clearable
          @update:model-value="update('file', $event)"
        />
      </VWindowItem>
      <VWindowItem value="text">
        <AppTextarea
          :model-value="source.text"
          label="Nội dung nguồn"
          placeholder="Dán nội dung cần viết lại..."
          rows="4"
          @update:model-value="update('text', $event)"
        />
      </VWindowItem>
      <VWindowItem value="html">
        <AppTextarea
          :model-value="source.html"
          label="HTML nguồn"
          placeholder="Dán HTML nguyên bản của bài..."
          rows="6"
          :disabled="props.disabled"
          @update:model-value="update('html', $event)"
        />
      </VWindowItem>
      <VWindowItem value="prompt">
        <AppTextarea
          :model-value="source.prompt"
          label="Yêu cầu viết bài"
          placeholder="Mô tả chủ đề, đối tượng đọc và yêu cầu nội dung..."
          rows="4"
          @update:model-value="update('prompt', $event)"
        />
      </VWindowItem>
    </VWindow>

    <AppSelect
      v-if="isSocial && props.catalog.platformOptions?.length"
      :model-value="source.platform"
      :items="props.catalog.platformOptions"
      label="Nền tảng social"
      class="mb-4"
      :disabled="props.disabled || props.catalog.loading"
      @update:model-value="update('platform', $event)"
    />
    <AppSelect
      v-if="['file', 'html'].includes(source.type)"
      :model-value="source.sourceEncoding"
      :items="['UTF-8', 'Windows-1252', 'ISO-8859-1']"
      label="Encoding nguồn"
      class="my-4"
      :disabled="props.disabled"
      @update:model-value="update('sourceEncoding', $event)"
    />
    <AiSourcePreview
      v-if="source.type !== 'prompt'"
      :snapshot="preview.snapshot.value"
      :busy="preview.busy.value"
      :error="preview.error.value"
      :disabled="props.disabled"
      class="mt-4"
      @read="preview.read"
    />
  </div>
</template>
