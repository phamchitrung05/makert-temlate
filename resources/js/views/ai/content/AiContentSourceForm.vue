<!--
  =====================================================================
  CHỨC NĂNG FILE: Form nguồn và tùy chọn tạo AI dùng component của project.
  =====================================================================
  Dùng AppTextField/AppTextarea/AppSelect cùng Tabler và theme Vuetify.

  CÁC HÀM/METHOD TRONG FILE:
  - update(): phát bản sao state của field được sửa.
  - updateProvider(): đổi provider, bỏ model cũ để composable chọn model phù hợp.
  - useAiSourcePreview(): đọc URL/raw HTML/file, invalidate preview khi nguồn đổi.

  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : v-model nguồn/tùy chọn, disabled và catalog từ AI Settings.
  - OUTPUT: update:modelValue và reload-catalog khi người dùng tải lại.
  - SIDE EFFECT: preview/catalog qua API hiện có; không gọi model trực tiếp.
  =====================================================================
-->
<script setup>
import { computed, shallowRef } from 'vue'
import AiWritingPreferences from '@/views/ai/shared/AiWritingPreferences.vue'
import AiManualTaxonomyFields from '@/views/ai/shared/AiManualTaxonomyFields.vue'
import AiSourcePreview from '@/views/ai/shared/AiSourcePreview.vue'
import AiThumbnailOptions from '@/views/ai/shared/AiThumbnailOptions.vue'
import { useAiSourcePreview } from '@/composables/ai/useAiSourcePreview'

const props = defineProps({
  catalog: { type: Object, required: true },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['reloadCatalog'])
const source = defineModel({ type: Object, required: true })
const openOptions = shallowRef(0)
const preview = useAiSourcePreview(source)

const inputTabs = [
  { value: 'url', title: 'Link nguồn', icon: 'tabler-link' },
  { value: 'file', title: 'File HTML', icon: 'tabler-file-code' },
  { value: 'html', title: 'HTML', icon: 'tabler-code' },
  { value: 'text', title: 'Nội dung nguồn', icon: 'tabler-file-text' },
  { value: 'prompt', title: 'Viết tự do', icon: 'tabler-pencil' },
]

const generatesTitle = computed(() => source.value.outputs?.includes('title') ?? false)

/** Input: key/value của field. Output: state mới; không mutate object của parent. */
const update = (key, value) => { source.value = { ...source.value, [key]: value } }

/** Input: provider key. Output: bỏ model của provider cũ; composable đồng bộ catalog mới. */
const updateProvider = provider => {
  source.value = { ...source.value, provider, model: '' }
}
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
    <AiWritingPreferences
      :model-value="source.writing"
      :disabled="props.disabled"
      class="mt-4"
      @update:model-value="update('writing', $event)"
    />
    <AppTextarea
      :model-value="source.instructions"
      label="Yêu cầu bổ sung cho bài này"
      placeholder="Ví dụ: Giải thích cho người mới, giữ code và số liệu nguồn."
      rows="3"
      maxlength="4000"
      class="mb-4"
      :disabled="props.disabled"
      @update:model-value="update('instructions', $event)"
    />
    <AiManualTaxonomyFields
      v-if="source.targetType === 'post'"
      :categories="source.category_ids"
      :tags="source.tag_ids"
      :disabled="props.disabled"
      @update:categories="update('category_ids', $event)"
      @update:tags="update('tag_ids', $event)"
    />
    <VExpansionPanels
      v-model="openOptions"
      variant="accordion"
      class="mt-6 mb-6"
    >
      <VExpansionPanel>
        <VExpansionPanelTitle>
          <VIcon
            icon="tabler-adjustments-horizontal"
            color="primary"
            size="20"
            class="me-2"
          />
          Tùy chọn AI
        </VExpansionPanelTitle>
        <VExpansionPanelText>
          <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
            <span class="text-body-2 text-medium-emphasis">Provider và model từ AI Settings</span>
            <VBtn
              size="small"
              variant="text"
              prepend-icon="tabler-refresh"
              :loading="props.catalog.loading"
              :disabled="props.disabled"
              @click="emit('reloadCatalog')"
            >
              Tải lại model
            </VBtn>
          </div>
          <VAlert
            v-if="props.catalog.error"
            type="error"
            variant="tonal"
            class="mb-4"
          >
            {{ props.catalog.error }}
          </VAlert>
          <VAlert
            v-else-if="!props.catalog.loading && !props.catalog.providerOptions.length"
            type="info"
            variant="tonal"
            class="mb-4"
          >
            Chưa có provider hoạt động với API key. Thêm provider và sync model trong AI Settings.
          </VAlert>
          <VRow>
            <VCol
              cols="12"
              sm="6"
            >
              <AppSelect
                id="ai-content-provider"
                :model-value="source.provider"
                label="Provider"
                aria-label="Provider"
                :items="props.catalog.providerOptions"
                :loading="props.catalog.loading"
                :disabled="props.disabled || props.catalog.loading || !props.catalog.providerOptions.length"
                placeholder="Chọn provider"
                prepend-inner-icon="tabler-brain"
                @update:model-value="updateProvider"
              />
            </VCol>
            <VCol
              cols="12"
              sm="6"
            >
              <AppSelect
                id="ai-content-model"
                :model-value="source.model"
                label="Model"
                aria-label="Model"
                :items="props.catalog.modelOptions"
                :loading="props.catalog.loading"
                :disabled="props.disabled || props.catalog.loading || !props.catalog.modelOptions.length"
                :hint="!props.catalog.loading && source.provider && !props.catalog.modelOptions.length ? 'Provider chưa có model đã bật và khả dụng. Hãy sync trong AI Settings.' : ''"
                persistent-hint
                placeholder="Chọn model"
                @update:model-value="update('model', $event)"
              />
            </VCol>
            <VCol
              cols="12"
              sm="6"
            >
              <AppSelect
                id="ai-content-language"
                :model-value="source.language"
                :disabled="props.disabled"
                label="Ngôn ngữ đầu ra"
                aria-label="Ngôn ngữ đầu ra"
                :items="[{ title: 'Tiếng Việt', value: 'vi' }, { title: 'Tiếng Anh', value: 'en' }]"
                prepend-inner-icon="tabler-language"
                @update:model-value="update('language', $event)"
              />
            </VCol>
            <VCol
              cols="12"
              sm="6"
            >
              <AppSelect
                id="ai-content-length"
                :model-value="source.length"
                :disabled="props.disabled"
                label="Độ dài bài viết"
                aria-label="Độ dài bài viết"
                :items="[{ title: 'Ngắn', value: 'short' }, { title: 'Trung bình', value: 'medium' }, { title: 'Dài', value: 'long' }]"
                @update:model-value="update('length', $event)"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                id="ai-content-outputs"
                :model-value="source.outputs ?? []"
                :items="props.catalog.outputOptions ?? []"
                :disabled="props.disabled || props.catalog.loading || !props.catalog.outputOptions?.length"
                label="AI sẽ tạo"
                aria-label="AI sẽ tạo"
                placeholder="Chọn các hạng mục cần tạo"
                multiple
                chips
                closable-chips
                @update:model-value="update('outputs', $event)"
              />
            </VCol>
            <VCol
              v-if="props.catalog.outputOptions?.some(option => option.value === 'thumbnail')"
              cols="12"
            >
              <AiThumbnailOptions
                v-model="source"
                :catalog="props.catalog"
                :disabled="props.disabled"
              />
            </VCol>
            <VCol
              v-if="!generatesTitle && !props.catalog.loading"
              cols="12"
            >
              <AppTextField
                :model-value="source.title"
                :disabled="props.disabled"
                label="Tiêu đề tài nguyên"
                maxlength="255"
                placeholder="Tiêu đề AI sẽ sử dụng khi viết bài..."
                @update:model-value="update('title', $event)"
              />
            </VCol>
          </VRow>
        </VExpansionPanelText>
      </VExpansionPanel>
    </VExpansionPanels>
  </div>
</template>
