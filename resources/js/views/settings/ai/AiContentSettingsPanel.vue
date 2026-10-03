<!--
  CHỨC NĂNG FILE: Form AI & Content dùng component/label chuẩn của project.
  INPUT: form/options và trạng thái API; OUTPUT: change-field và retry.
  SIDE EFFECT: chỉ phát sự kiện; cấu hình và thao tác API thuộc composable.
-->
<script setup>
import { getAlertColor } from '@/config/alertColors'

defineProps({
  form: { type: Object, required: true },
  providerOptions: { type: Array, required: true },
  modelOptions: { type: Array, required: true },
  imageModelOptions: { type: Array, required: true },
  loading: Boolean,
  loaded: Boolean,
  saving: Boolean,
  error: { type: String, default: '' },
  catalogNotice: { type: String, default: '' },
  imageCatalogNotice: { type: String, default: '' },
  fieldErrors: { type: Object, default: () => ({}) },
  notice: { type: Object, default: null },
})

const emit = defineEmits(['changeField', 'retry'])
</script>

<template>
  <div>
    <div class="d-flex align-center mb-1">
      <VIcon
        color="primary"
        size="22"
        class="me-2"
        icon="tabler-sparkles"
      />
      <h2 class="text-h5 font-weight-medium text-high-emphasis">
        Thiết lập AI &amp; Tạo nội dung
      </h2>
    </div>
    <div class="text-caption text-medium-emphasis mb-6">
      Cấu hình mô hình sinh bài viết, prompt mẫu mặc định và các tự động hóa.
    </div>

    <div
      v-if="loading"
      role="status"
      aria-live="polite"
      class="d-flex align-center gap-3 py-6"
    >
      <VProgressCircular
        indeterminate
        color="primary"
        size="24"
      />
      <span>Đang tải thiết lập AI &amp; Content...</span>
    </div>
    <VAlert
      v-else-if="error"
      type="error"
      :color="getAlertColor('error')"
      variant="tonal"
    >
      <div>{{ error }}</div>
      <VBtn
        class="mt-3"
        variant="outlined"
        size="small"
        prepend-icon="tabler-refresh"
        @click="emit('retry')"
      >
        Tải lại
      </VBtn>
    </VAlert>
    <div v-else-if="loaded">
      <VAlert
        v-if="notice"
        :type="notice.type"
        :color="getAlertColor(notice.type)"
        variant="tonal"
        class="mb-5"
        role="status"
      >
        {{ notice.message }}
      </VAlert>
      <VAlert
        v-if="!providerOptions.length || catalogNotice"
        type="warning"
        :color="getAlertColor('warning')"
        variant="tonal"
        class="mb-5"
      >
        <div>
          {{ catalogNotice || 'Chưa có provider với mô hình tạo nội dung khả dụng. Bạn vẫn có thể lưu các thiết lập nội dung còn lại.' }}
        </div>
        <VBtn
          :to="{ name: 'settings-ai-providers' }"
          variant="text"
          size="small"
          class="mt-2"
          prepend-icon="tabler-plug-connected"
        >
          Quản lý AI Providers
        </VBtn>
      </VAlert>

      <VRow dense>
        <VCol
          cols="12"
          sm="6"
        >
          <AppSelect
            :model-value="form.defaultProviderId"
            label="AI Provider mặc định"
            :items="providerOptions"
            :disabled="saving || !providerOptions.length"
            placeholder="Chọn provider"
            clearable
            variant="outlined"
            @update:model-value="emit('changeField', 'defaultProviderId', $event)"
          />
        </VCol>
        <VCol
          cols="12"
          sm="6"
        >
          <AppSelect
            :model-value="form.defaultTextModelId"
            label="Model AI tạo nội dung"
            :items="modelOptions"
            :disabled="saving || !modelOptions.length"
            :error-messages="fieldErrors.default_text_model_id"
            placeholder="Chọn mô hình"
            clearable
            variant="outlined"
            @update:model-value="emit('changeField', 'defaultTextModelId', $event)"
          />
        </VCol>
        <VCol cols="12">
          <AppSelect
            :model-value="form.defaultImageModelId"
            label="Model AI tạo ảnh thumbnail"
            :items="imageModelOptions"
            :disabled="saving || !imageModelOptions.length"
            :error-messages="fieldErrors.default_image_model_id"
            placeholder="Chọn model tạo ảnh"
            hint="Model ảnh được dùng khi chọn tạo thumbnail bằng AI."
            persistent-hint
            clearable
            variant="outlined"
            @update:model-value="emit('changeField', 'defaultImageModelId', $event)"
          />
          <VAlert
            v-if="!imageModelOptions.length || imageCatalogNotice"
            type="warning"
            :color="getAlertColor('warning')"
            variant="tonal"
            class="mt-3"
          >
            {{ imageCatalogNotice || 'Chưa có model tạo ảnh khả dụng. Hãy cấu hình model hỗ trợ tạo ảnh trong AI Providers.' }}
            <div>
              <VBtn
                :to="{ name: 'settings-ai-providers' }"
                variant="text"
                size="small"
                class="mt-2"
                prepend-icon="tabler-plug-connected"
              >
                Quản lý AI Providers
              </VBtn>
            </div>
          </VAlert>
        </VCol>
        <VCol
          cols="12"
          sm="6"
        >
          <VLabel
            for="settings-ai-temperature"
            class="text-body-2 text-high-emphasis text-wrap mb-1 d-block"
          >
            Nhiệt độ sáng tạo (Temperature: {{ form.temperature }})
          </VLabel>
          <VSlider
            id="settings-ai-temperature"
            :model-value="form.temperature"
            :disabled="saving"
            :error-messages="fieldErrors.default_temperature"
            name="Nhiệt độ sáng tạo"
            aria-label="Nhiệt độ sáng tạo"
            min="0"
            max="2"
            step="0.1"
            color="primary"
            thumb-label
            hide-details="auto"
            @update:model-value="emit('changeField', 'temperature', $event)"
          />
        </VCol>
        <VCol
          cols="12"
          sm="6"
        >
          <AppTextField
            :model-value="form.minWordCount"
            :disabled="saving"
            :error-messages="fieldErrors.min_word_count"
            label="Số từ khuyến nghị tối thiểu"
            type="number"
            min="0"
            max="10000"
            step="1"
            suffix="từ"
            variant="outlined"
            @update:model-value="emit('changeField', 'minWordCount', $event)"
          />
        </VCol>
        <VCol cols="12">
          <AppTextarea
            :model-value="form.systemPrompt"
            :disabled="saving"
            :error-messages="fieldErrors.default_system_prompt"
            label="System Prompt mặc định"
            variant="outlined"
            rows="3"
            @update:model-value="emit('changeField', 'systemPrompt', $event)"
          />
        </VCol>
      </VRow>

      <VDivider class="my-5" />
      <div class="text-subtitle-2 font-weight-bold mb-3 text-high-emphasis">
        Tính năng tự động
      </div>
      <VRow dense>
        <VCol
          cols="12"
          sm="6"
        >
          <VSwitch
            :model-value="form.autoThumbnail"
            :disabled="saving"
            :error-messages="fieldErrors.auto_thumbnail"
            label="Tự động xử lý thumbnail khi import link"
            color="primary"
            density="compact"
            inset
            hide-details="auto"
            @update:model-value="emit('changeField', 'autoThumbnail', $event)"
          />
        </VCol>
        <VCol
          cols="12"
          sm="6"
        >
          <VSwitch
            :model-value="form.autoSeo"
            :disabled="saving"
            :error-messages="fieldErrors.auto_seo"
            label="Tự động tạo thẻ Meta và tối ưu điểm SEO"
            color="primary"
            density="compact"
            inset
            hide-details="auto"
            @update:model-value="emit('changeField', 'autoSeo', $event)"
          />
        </VCol>
      </VRow>
    </div>
  </div>
</template>
