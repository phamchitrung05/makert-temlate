<!--
  =====================================================================
  CHỨC NĂNG FILE: Form nguồn và tùy chọn tạo AI dùng component của project.
  =====================================================================
  Dùng AppTextField/AppTextarea/AppSelect cùng Tabler và theme Vuetify.

  CÁC HÀM/METHOD TRONG FILE:
  - update(): phát bản sao state của field được sửa.
  - updateProvider(): đổi provider, bỏ model cũ để composable chọn model phù hợp.

  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : v-model nguồn/tùy chọn, disabled và catalog từ AI Settings.
  - OUTPUT: update:modelValue và reload-catalog khi người dùng tải lại.
  - SIDE EFFECT: không đọc file, tải URL hoặc gọi provider.
  =====================================================================
-->
<script setup>
import { shallowRef } from 'vue'

const props = defineProps({
  catalog: { type: Object, required: true },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['reloadCatalog'])
const source = defineModel({ type: Object, required: true })
const openOptions = shallowRef(0)

const inputTabs = [
  { value: 'url', title: 'Link nguồn', icon: 'tabler-link' },
  { value: 'file', title: 'File HTML', icon: 'tabler-file-code' },
  { value: 'text', title: 'Nội dung nguồn', icon: 'tabler-file-text' },
  { value: 'prompt', title: 'Viết tự do', icon: 'tabler-pencil' },
]

const optionChecks = [
  { key: 'autoTitle', label: 'Tự động tạo tiêu đề' },
  { key: 'autoThumbnail', label: 'Lấy thumbnail từ nguồn URL' },
  { key: 'optimizeSeo', label: 'Tối ưu SEO' },
  { key: 'rewrite', label: 'Viết lại nội dung nguồn' },
]

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
            <VCol
              v-for="option in optionChecks"
              :key="option.key"
              cols="12"
              sm="6"
            >
              <VCheckbox
                :model-value="source[option.key]"
                :label="option.label"
                :disabled="props.disabled || (option.key === 'autoThumbnail' && source.type !== 'url')"
                @update:model-value="update(option.key, $event)"
              />
            </VCol>
            <VCol
              v-if="!source.autoTitle"
              cols="12"
            >
              <AppTextField
                :model-value="source.title"
                :disabled="props.disabled"
                label="Tiêu đề bài mới"
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
