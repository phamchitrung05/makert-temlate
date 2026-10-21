<!--
  =====================================================================
  CHỨC NĂNG FILE: Trang tạo Visual Style / prompt mô tả từ hình ảnh tải lên.
  =====================================================================
  Luồng khớp dialog analyze cũ: người dùng tải ảnh mẫu, đặt tên, chọn model
  Vision và gửi phân tích lên backend — VisualStyleAnalyzerService queue job,
  worker gọi Vision trả Style DNA + suggested_shots, style hiện trong danh sách.
  Panel phải hiển thị kết quả/summary từ profile sau khi task AI ready.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - triggerFileInput(): mở hộp chọn tệp ảnh trên máy tính.
  - handleFileSelect()/handleDrop(): nhận ảnh và đặt preview + file để gửi API.
  - copyPromptText(): sao chép nội dung prompt vào clipboard.
  - generatePrompt(): validate và gọi visualStylesService.analyze() → queue.
  - loadResult(): đọc lại profile đã phân tích khi task AI ready.
  - modelOptions: option {title,value} model Vision+Text từ useAiProviderSettings.
  - watcher styleId/onMounted: nạp catalog model + nghe AI_TASK_READY_EVENT.
  - props/emits: không có; page tự quản lý state + API qua service.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : ảnh upload, tên style, model Vision, (tỷ lệ — UI-only chưa gửi API).
  - OUTPUT: style queued + panel kết quả (summary/prompt/tags) sau khi phân tích.
  - SIDE EFFECT: POST multipart `/admin/ai/visual-styles/analyze`; GET show;
  ObjectURL preview; listener task-ready; không gọi provider trực tiếp.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Analyze payload keys follow the Laravel API contract. */
import { computed, onMounted, onScopeDispose, ref } from 'vue'
import { visualStylesService } from '@/services/visualStyles'
import { AI_TASK_READY_EVENT } from '@/composables/useAiTaskQueue'
import { useAiProviderSettings } from '@/composables/useAiProviderSettings'

definePage({ meta: { action: 'manage', subject: 'ai_settings' } })

// Provider catalog để chọn model Vision phân tích (giống StyleAnalyzeDialog)
const { providers, load: loadProviders } = useAiProviderSettings()

// Chỉ model Vision + Text của driver openai/openai-compatible/gemini (khớp backend)
const ALLOWED_DRIVERS = ['openai', 'openai-compatible', 'gemini']

const modelOptions = computed(() => providers.value
  .filter(provider => provider.is_active && provider.has_api_key && ALLOWED_DRIVERS.includes(provider.driver))
  .flatMap(provider => (provider.models ?? [])
    .filter(model => model.is_enabled && model.is_available
      && (model.capabilities ?? []).includes('vision')
      && (model.capabilities ?? []).includes('text_generation'))
    .map(model => ({ title: `${provider.name} · ${model.label || model.remote_model_id}`, value: model.id }))))

// State form + kết quả
const fileInputRef = ref(null)
const copied = ref(false)
const submitting = ref(false)
const error = ref('')
const snackbar = ref({ show: false, text: '', color: 'success' })

const defaultImage = 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=900'
const uploadedImage = ref('')
const imageFile = ref(null)
const promptName = ref('')
const modelId = ref(null)
const selectedRatio = ref('16:9')
const styleId = ref(null)
const analyzing = ref(false)

// Các tùy chọn tỷ lệ khung hình hiển thị trong lưới (UI-only; chưa gửi API)
const aspectRatios = [
  { ratio: '1:1', label: 'Vuông', width: 20, height: 20 },
  { ratio: '16:9', label: 'Ngang', width: 26, height: 16 },
  { ratio: '9:16', label: 'Dọc', width: 15, height: 24 },
  { ratio: '4:3', label: 'Ngang', width: 24, height: 18 },
  { ratio: '3:4', label: 'Dọc', width: 18, height: 24 },
]

// Nội dung prompt / summary / tags — đổ vào sau khi task AI phân tích xong
const generatedPrompt = ref('')
const promptTags = ref([])
const summaryText = ref('')
const promptStatus = ref('')

const showSnackbar = (text, color = 'success') => {
  snackbar.value = { show: true, text, color }
}

const triggerFileInput = () => {
  fileInputRef.value?.click()
}

const pickFile = file => {
  if (!file || !file.type?.startsWith('image/')) {
    error.value = 'Chọn file ảnh JPG, PNG hoặc WEBP.'

    return
  }
  if (file.size > 5 * 1024 * 1024) {
    error.value = 'Ảnh tối đa 5MB.'

    return
  }
  error.value = ''
  imageFile.value = file
  uploadedImage.value = URL.createObjectURL(file)
}

const handleFileSelect = event => {
  pickFile(event.target.files?.[0])
  event.target.value = ''
}

const handleDrop = event => {
  pickFile(event.dataTransfer.files?.[0])
}

const copyPromptText = () => {
  if (!generatedPrompt.value) return
  navigator.clipboard.writeText(generatedPrompt.value)
  copied.value = true
  setTimeout(() => {
    copied.value = false
  }, 2000)
}

// =====================================================================
// Input: ảnh + tên + model Vision. Output: queue analyze → snackbar.
// Side effect: POST multipart analyze; lỗi ghi vào `error`.
// =====================================================================
const generatePrompt = async () => {
  error.value = ''
  if (!imageFile.value) {
    error.value = 'Cần ảnh tham chiếu để phân tích.'

    return
  }
  if (!promptName.value?.trim()) {
    error.value = 'Tên style là bắt buộc.'

    return
  }
  if (!modelId.value) {
    error.value = 'Chọn model Vision để phân tích.'

    return
  }
  submitting.value = true
  analyzing.value = true
  try {
    const style = await visualStylesService.analyze({
      name: promptName.value.trim(),
      model_id: modelId.value,
      image: imageFile.value,
    })

    styleId.value = style?.id ?? null
    promptStatus.value = 'queued'
    generatedPrompt.value = ''
    promptTags.value = []
    summaryText.value = ''
    showSnackbar('Đã xếp hàng phân tích phong cách. Theo dõi trong trung tâm tác vụ AI.')
  }
  catch (reason) {
    analyzing.value = false
    error.value = reason?.data?.message ?? 'Phân tích thất bại.'
  }
  finally {
    submitting.value = false
  }
}

// =====================================================================
// Input: styleId đã queue. Output: đổ summary/prompt/tags vào panel phải.
// Side effect: GET show; bỏ qua khi chưa ready.
// =====================================================================
const loadResult = async () => {
  if (!styleId.value) return
  try {
    const style = await visualStylesService.show(styleId.value)

    promptStatus.value = style?.status ?? ''
    if (style?.status === 'failed') {
      analyzing.value = false
      error.value = style?.error_message ?? 'Phân tích thất bại.'

      return
    }
    if (!['analyzed', 'active', 'ready'].includes(style?.status)) return

    analyzing.value = false
    summaryText.value = style?.summary ?? ''
    generatedPrompt.value = style?.prompt || style?.summary || ''

    const keywords = style?.dna_json?.identity?.keywords

    promptTags.value = Array.isArray(keywords) ? keywords : (style?.category ? [style.category] : [])
  }
  catch {
    /* giữ nguyên trạng thái; user tải lại bằng nút */
  }
}

// Task AI ready trong popup → đọc lại profile
const handleTaskReady = () => {
  void loadResult()
}

onMounted(() => {
  if (!providers.value.length) {
    loadProviders().catch(() => {})
  }
  window.addEventListener(AI_TASK_READY_EVENT, handleTaskReady)
})

onScopeDispose(() => {
  if (typeof window !== 'undefined')
    window.removeEventListener(AI_TASK_READY_EVENT, handleTaskReady)
})
</script>

<template>
  <div class="image-to-prompt-page">
    <!-- ================= 1. HEADER TRANG ================= -->
    <div class="d-flex flex-wrap justify-space-between gap-y-4 gap-x-6 mb-6">
      <div>
        <h4 class="text-h4 font-weight-medium">
          Tạo Prompt từ hình ảnh
        </h4>
        <div class="text-body-1">
          Tải ảnh lên, đặt tên prompt, chọn tỷ lệ và nhận prompt mô tả chi tiết để dùng lại.
        </div>
      </div>
      <div class="d-flex flex-wrap align-center gap-4">
        <VBtn
          color="secondary"
          variant="tonal"
          prepend-icon="tabler-history"
          :to="{ name: 'visual-styles' }"
        >
          Lịch sử style
        </VBtn>
      </div>
    </div>

    <!-- ================= 2. NỘI DUNG CHÍNH (2 CỘT) ================= -->
    <VRow>
      <!-- ---------------- CỘT TRÁI: CẤU HÌNH & TẢI ẢNH ---------------- -->
      <VCol
        cols="12"
        lg="6"
      >
        <VCard class="h-100 d-flex flex-column justify-space-between">
          <VCardText>
            <!-- Bước 1: Tải hình ảnh -->
            <div class="mb-6">
              <div class="d-flex align-center mb-3">
                <VAvatar
                  color="primary"
                  variant="tonal"
                  rounded
                  size="38"
                  class="me-3"
                >
                  <VIcon icon="tabler-photo" />
                </VAvatar>
                <div>
                  <div class="text-subtitle-1 font-weight-medium">
                    1. Tải hình ảnh
                  </div>
                  <div class="text-body-2 text-medium-emphasis">
                    Chọn ảnh bạn muốn tạo prompt mô tả
                  </div>
                </div>
              </div>

              <!-- Khung bao ngoài nét đứt chứa Ảnh preview & Dropzone -->
              <div class="upload-area-wrapper pa-3 rounded-lg">
                <VRow
                  dense
                  align="stretch"
                >
                  <!-- Ảnh đã chọn bên trái -->
                  <VCol
                    cols="12"
                    sm="7"
                  >
                    <div class="position-relative rounded-lg overflow-hidden h-100 upload-preview-box">
                      <VImg
                        v-if="uploadedImage"
                        :src="uploadedImage"
                        height="210"
                        class="rounded-lg"
                      />
                      <div
                        v-else
                        class="d-flex flex-column align-center justify-center h-100 text-medium-emphasis upload-preview-empty"
                      >
                        <VIcon
                          size="40"
                          icon="tabler-photo"
                          class="mb-1"
                        />
                        <span class="text-caption">Chưa có hình ảnh</span>
                      </div>

                      <!-- Nút Xóa ảnh -->
                      <VBtn
                        v-if="uploadedImage"
                        icon="tabler-x"
                        size="26"
                        variant="flat"
                        class="remove-img-btn"
                        @click="uploadedImage = ''"
                      />
                    </div>
                  </VCol>

                  <!-- Ô kéo thả chọn ảnh bên phải -->
                  <VCol
                    cols="12"
                    sm="5"
                  >
                    <div
                      class="dropzone-box h-100 rounded-lg pa-4 d-flex flex-column align-center justify-center text-center"
                      @dragover.prevent
                      @drop.prevent="handleDrop"
                    >
                      <VIcon
                        size="34"
                        icon="tabler-cloud-upload"
                        class="mb-2 text-medium-emphasis"
                      />
                      <div class="text-body-2 font-weight-medium">
                        Kéo thả ảnh vào đây
                      </div>
                      <div class="text-caption text-medium-emphasis mb-3">
                        hoặc
                      </div>

                      <VBtn
                        color="primary"
                        size="small"
                        class="mb-3"
                        @click="triggerFileInput"
                      >
                        Chọn ảnh
                      </VBtn>
                      <input
                        ref="fileInputRef"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        class="d-none"
                        @change="handleFileSelect"
                      >

                      <div class="text-caption text-disabled">
                        JPG, PNG, WEBP<br>Tối đa 5MB
                      </div>
                    </div>
                  </VCol>
                </VRow>
              </div>
            </div>

            <!-- Bước 2: Chọn tên prompt -->
            <div class="mb-6">
              <div class="d-flex align-center mb-3">
                <VAvatar
                  color="primary"
                  variant="tonal"
                  rounded
                  size="38"
                  class="me-3"
                >
                  <VIcon icon="tabler-wand" />
                </VAvatar>
                <div>
                  <div class="text-subtitle-1 font-weight-medium">
                    2. Chọn tên prompt
                  </div>
                  <div class="text-body-2 text-medium-emphasis">
                    Lưu prompt với tên để dễ tìm lại sau này
                  </div>
                </div>
              </div>

              <VTextField
                v-model="promptName"
                placeholder="Nhập tên gợi nhớ cho prompt..."
                maxlength="100"
                hide-details
              />
              <div class="text-right text-caption text-medium-emphasis mt-1">
                {{ promptName.length }}/100
              </div>
            </div>

            <!-- Bước 3: Chọn model Vision -->
            <div class="mb-6">
              <div class="d-flex align-center mb-3">
                <VAvatar
                  color="primary"
                  variant="tonal"
                  rounded
                  size="38"
                  class="me-3"
                >
                  <VIcon icon="tabler-brain" />
                </VAvatar>
                <div>
                  <div class="text-subtitle-1 font-weight-medium">
                    3. Chọn model Vision
                  </div>
                  <div class="text-body-2 text-medium-emphasis">
                    Model có Vision + Text để AI phân tích ảnh
                  </div>
                </div>
              </div>

              <AppSelect
                v-model="modelId"
                :items="modelOptions"
                placeholder="Chọn model có Vision + Text"
                :loading="!providers.length"
                hide-details
              />
            </div>

            <!-- Bước 4: Chọn tỷ lệ khung hình -->
            <div class="mb-6">
              <div class="d-flex align-center mb-3">
                <VAvatar
                  color="primary"
                  variant="tonal"
                  rounded
                  size="38"
                  class="me-3"
                >
                  <VIcon icon="tabler-aspect-ratio" />
                </VAvatar>
                <div>
                  <div class="text-subtitle-1 font-weight-medium">
                    4. Chọn tỷ lệ khung hình
                  </div>
                  <div class="text-body-2 text-medium-emphasis">
                    Chọn tỷ lệ phù hợp với nhu cầu tạo ảnh
                  </div>
                </div>
              </div>

              <!-- Lưới 5 tỷ lệ khung hình -->
              <div class="aspect-ratio-grid">
                <div
                  v-for="item in aspectRatios"
                  :key="item.ratio"
                  class="ratio-card pa-3 rounded-lg border text-center cursor-pointer d-flex flex-column align-center justify-center"
                  :class="{ 'ratio-card-active': selectedRatio === item.ratio }"
                  @click="selectedRatio = item.ratio"
                >
                  <!-- Biểu tượng mô phỏng khung hình -->
                  <div class="ratio-shape-box mb-2 d-flex align-center justify-center">
                    <div
                      class="ratio-shape"
                      :class="{ 'ratio-shape-active': selectedRatio === item.ratio }"
                      :style="{ width: item.width + 'px', height: item.height + 'px' }"
                    />
                  </div>

                  <div
                    class="text-body-2 font-weight-medium"
                    :class="selectedRatio === item.ratio ? 'text-primary' : ''"
                  >
                    {{ item.ratio }}
                  </div>
                  <div
                    class="text-caption"
                    :class="selectedRatio === item.ratio ? 'text-primary' : 'text-medium-emphasis'"
                  >
                    {{ item.label }}
                  </div>
                </div>
              </div>
            </div>
          </VCardText>

          <!-- Nút Tạo Prompt từ ảnh -->
          <VCardText class="pt-0">
            <VAlert
              v-if="error"
              type="error"
              variant="tonal"
              density="compact"
              class="mb-3"
            >
              {{ error }}
            </VAlert>
            <VBtn
              block
              color="primary"
              size="large"
              prepend-icon="tabler-sparkles"
              :loading="submitting"
              :disabled="submitting || analyzing"
              @click="generatePrompt"
            >
              {{ analyzing ? 'Đang phân tích…' : 'Tạo Prompt từ ảnh' }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <!-- ---------------- CỘT PHẢI: KẾT QUẢ PROMPT ---------------- -->
      <VCol
        cols="12"
        lg="6"
      >
        <VCard class="h-100 d-flex flex-column justify-space-between">
          <VCardText>
            <!-- Header Kết quả & Nút Sao chép -->
            <div class="d-flex justify-space-between align-center mb-4">
              <div class="d-flex align-center">
                <VAvatar
                  color="primary"
                  variant="tonal"
                  rounded
                  size="38"
                  class="me-3"
                >
                  <VIcon icon="tabler-sparkles" />
                </VAvatar>
                <div>
                  <div class="text-subtitle-1 font-weight-medium">
                    Kết quả Prompt
                  </div>
                  <div class="text-body-2 text-medium-emphasis">
                    Prompt mô tả chi tiết từ hình ảnh của bạn
                  </div>
                </div>
              </div>

              <VBtn
                color="primary"
                variant="tonal"
                prepend-icon="tabler-copy"
                size="small"
                :disabled="!generatedPrompt"
                @click="copyPromptText"
              >
                {{ copied ? 'Đã chép' : 'Sao chép' }}
              </VBtn>
            </div>

            <!-- Ảnh lớn xem trước -->
            <VImg
              :src="uploadedImage || defaultImage"
              height="295"
              rounded="lg"
              class="mb-4 border result-preview-img"
            />

            <!-- Trạng thái đang phân tích -->
            <VAlert
              v-if="analyzing"
              type="info"
              variant="tonal"
              density="compact"
              class="mb-4"
            >
              <VProgressCircular
                indeterminate
                size="16"
                width="2"
                class="me-2"
              />
              AI đang phân tích phong cách ảnh — kết quả hiện ở đây khi xong.
            </VAlert>

            <!-- Hộp văn bản Prompt / Summary kết quả -->
            <div class="prompt-result-box pa-4 rounded-lg mb-4">
              <p
                v-if="generatedPrompt"
                class="text-body-2 mb-0 prompt-result-text"
              >
                {{ generatedPrompt }}
              </p>
              <p
                v-else
                class="text-body-2 mb-0 text-medium-emphasis"
              >
                {{ analyzing ? 'Đang chờ kết quả phân tích…' : 'Prompt mô tả sẽ hiện ở đây sau khi phân tích.' }}
              </p>
            </div>

            <!-- Summary tiếng Việt từ AI -->
            <VAlert
              v-if="summaryText"
              type="warning"
              variant="tonal"
              density="compact"
              class="mb-4"
            >
              <div class="text-caption font-weight-medium mb-1">
                Tóm tắt phong cách
              </div>
              <div class="text-body-2">
                {{ summaryText }}
              </div>
            </VAlert>

            <!-- Danh sách Tags từ khóa -->
            <div
              v-if="promptTags.length"
              class="d-flex flex-wrap gap-2 mb-4"
            >
              <VChip
                v-for="tag in promptTags"
                :key="tag"
                size="small"
                color="primary"
                variant="tonal"
              >
                {{ tag }}
              </VChip>
            </div>
          </VCardText>

          <!-- Thông báo trạng thái lưu -->
          <VCardText class="pt-0">
            <div class="saved-notice-box pa-4 rounded-lg d-flex align-center">
              <VIcon
                color="primary"
                size="20"
                :icon="analyzing ? 'tabler-loader' : 'tabler-info-circle'"
                class="me-3"
              />
              <div class="text-body-2">
                <template v-if="analyzing">
                  Đang phân tích “{{ promptName || 'style' }}” qua AI…
                </template>
                <template v-else-if="styleId && promptStatus">
                  Style đã lưu với trạng thái
                  <strong class="text-primary">“{{ promptStatus }}”</strong> —
                  xem trong danh sách Visual Styles.
                </template>
                <template v-else>
                  Tải ảnh, đặt tên và chọn model Vision rồi bấm Tạo Prompt.
                </template>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Snackbar -->
    <VSnackbar
      v-model="snackbar.show"
      :color="snackbar.color"
      location="bottom end"
      :timeout="3200"
    >
      {{ snackbar.text }}
    </VSnackbar>
  </div>
</template>

<style scoped>
/* Khung bao phần upload ảnh (Bước 1) */
.upload-area-wrapper {
  border: 1.5px dashed rgba(var(--v-theme-primary), 0.35);
  background-color: rgba(var(--v-theme-primary), 0.04);
}

/* Hộp ảnh preview giữ chiều cao tối thiểu */
.upload-preview-box {
  background-color: rgba(var(--v-theme-on-surface), 0.04);
}
.upload-preview-empty {
  min-height: 210px;
}

/* Nút Xóa trên góc phải ảnh preview (scrim tối, icon sáng theo surface) */
.remove-img-btn {
  position: absolute;
  top: 8px;
  right: 8px;
  z-index: 2;
  color: rgb(var(--v-theme-surface));
  background-color: rgba(var(--v-theme-on-surface), 0.55);
}

/* Hộp kéo thả bên phải ảnh preview */
.dropzone-box {
  border: 1.5px dashed rgba(var(--v-theme-primary), 0.35);
  background-color: rgba(var(--v-theme-primary), 0.04);
  transition: border-color 0.2s ease, background-color 0.2s ease;
}
.dropzone-box:hover {
  border-color: rgb(var(--v-theme-primary));
  background-color: rgba(var(--v-theme-primary), 0.08);
}

/* Lưới 5 cột cho tỷ lệ khung hình */
.aspect-ratio-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 10px;
}
@media (max-width: 600px) {
  .aspect-ratio-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

.ratio-card {
  border-color: rgba(var(--v-border-color), var(--v-border-opacity)) !important;
  transition: border-color 0.2s ease, background-color 0.2s ease;
}
.ratio-card:hover {
  border-color: rgb(var(--v-theme-primary)) !important;
  background-color: rgba(var(--v-theme-primary), 0.04);
}
.ratio-card-active {
  border: 1.5px solid rgb(var(--v-theme-primary)) !important;
  background-color: rgba(var(--v-theme-primary), 0.08);
}

/* Hình khối mô phỏng tỷ lệ */
.ratio-shape-box {
  height: 28px;
}
.ratio-shape {
  border: 1.8px solid rgba(var(--v-theme-on-surface), 0.5);
  border-radius: 4px;
  position: relative;
}
.ratio-shape-active {
  border-color: rgb(var(--v-theme-primary));
  background-color: rgba(var(--v-theme-primary), 0.12);
}

/* Nền khung ảnh kết quả khi ảnh không lấp đầy */
.result-preview-img {
  background-color: rgba(var(--v-theme-on-surface), 0.04);
}

/* Hộp hiển thị kết quả Prompt */
.prompt-result-box {
  background-color: rgba(var(--v-theme-primary), 0.04);
  border: 1px solid rgba(var(--v-theme-primary), 0.18);
}
.prompt-result-text {
  line-height: 1.65;
}

/* Hộp thông báo lưu lịch sử ở đáy cột phải */
.saved-notice-box {
  background-color: rgba(var(--v-theme-primary), 0.04);
  border: 1px solid rgba(var(--v-theme-primary), 0.18);
}
</style>
