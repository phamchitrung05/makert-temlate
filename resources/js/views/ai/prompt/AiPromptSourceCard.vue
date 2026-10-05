<!--
  =====================================================================
  CHỨC NĂNG FILE: Nhập tên văn phong và nguồn bài tham khảo cho trang Ai Prompt.
  =====================================================================
  Tái sử dụng Tiptap của project để nhập HTML; page cha quản lý toàn bộ
  trạng thái nguồn, model và các API preview/phân tích qua props và emits.

  CÁC HÀM/METHOD TRONG FILE:
  - defineModel(): đồng bộ HTML/văn bản, tên, tab, URL, file và model với page.
  - getFieldErrors(field): chuẩn hóa thông báo validation để hiển thị tại ô nhập.
  - resetSource(): xóa lựa chọn URL/file, về tab dán nội dung và yêu cầu khôi phục nguồn.
  - clearSource(): xóa lựa chọn URL/file và yêu cầu xóa nội dung nguồn.

  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : v-model nguồn/tên/model, catalog, lỗi, số từ và trạng thái tác vụ từ page.
  - OUTPUT: cập nhật models; phát reset/clear/preview/analyze/reloadCatalog cho page.
  - SIDE EFFECT: khóa ô nhập khi đang xử lý; không tự tải URL hoặc gọi API/model.
  =====================================================================
-->
<script setup>
import { computed } from 'vue'
import TiptapEditor from '@core/components/TiptapEditor.vue'

const props = defineProps({
  wordCount: { type: Number, required: true },
  modelOptions: { type: Array, default: () => [] },
  catalogLoading: { type: Boolean, default: false },
  catalogError: { type: String, default: '' },
  busy: { type: Boolean, default: false },
  previewing: { type: Boolean, default: false },
  canPreview: { type: Boolean, default: false },
  sourcePrepared: { type: Boolean, default: false },
  errors: { type: Object, default: () => ({}) },
  sourceNotice: { type: String, default: '' },
})

const emit = defineEmits(['reset', 'clear', 'preview', 'analyze', 'reloadCatalog'])
const sourceHtml = defineModel({ type: String, required: true })
const profileName = defineModel('profileName', { type: String, required: true })
const sourceTab = defineModel('sourceTab', { type: String, default: 'paste' })
const sourceUrl = defineModel('sourceUrl', { type: String, default: '' })
const sourceFile = defineModel('sourceFile', { type: [Object, Array], default: null })
const sourceText = defineModel('sourceText', { type: String, default: '' })
const modelId = defineModel('modelId', { type: Number, default: null })
const inputLocked = computed(() => props.busy || props.previewing)
const selectedFile = computed(() => Array.isArray(sourceFile.value) ? sourceFile.value[0] : sourceFile.value)
const isTextFile = computed(() => /\.txt$/i.test(selectedFile.value?.name ?? ''))
const canReadFile = computed(() => Boolean(selectedFile.value) && (props.canPreview || isTextFile.value))
const canAnalyze = computed(() => !inputLocked.value && Boolean(profileName.value.trim()) && props.wordCount > 0 && (sourceTab.value === 'paste' || props.sourcePrepared))

/**
 * =====================================================================
 * Chuẩn hóa lỗi field nhận từ page để các input dùng chung cách hiển thị.
 * Input: Tên field của request; lỗi có thể là chuỗi hoặc danh sách chuỗi.
 * Output: Mảng thông báo hợp lệ; trả mảng rỗng khi field không có lỗi.
 * =====================================================================
 */
const getFieldErrors = field => {
  const messages = props.errors[field]

  return Array.isArray(messages) ? messages : messages ? [messages] : []
}

/**
 * =====================================================================
 * Khôi phục nguồn mẫu từ trang cha và đưa giao diện về tab dán nội dung.
 * Input: Không có; được gọi khi người dùng bấm nút khôi phục.
 * Output: Xóa URL/file cục bộ và phát sự kiện reset; không gọi API.
 * =====================================================================
 */
const resetSource = () => {
  sourceTab.value = 'paste'
  sourceUrl.value = ''
  sourceFile.value = null
  emit('reset')
}

/**
 * =====================================================================
 * Yêu cầu trang cha xóa nội dung đang dùng làm nguồn phân tích.
 * Input: Không có; được gọi khi người dùng bấm nút xóa nội dung.
 * Output: Xóa URL/file cục bộ và phát sự kiện clear; không gọi API.
 * =====================================================================
 */
const clearSource = () => {
  sourceUrl.value = ''
  sourceFile.value = null
  emit('clear')
}
</script>

<template>
  <VCard>
    <VCardItem>
      <template #prepend>
        <VAvatar
          rounded
          color="primary"
          variant="tonal"
          icon="tabler-file-text"
        />
      </template>

      <VCardTitle>Nguồn bài viết</VCardTitle>
      <VCardSubtitle class="text-wrap">
        Chọn bài tham khảo có cách viết bạn muốn sử dụng lại.
      </VCardSubtitle>
    </VCardItem>

    <VCardText>
      <AppTextField
        v-model="profileName"
        label="Tên văn phong *"
        placeholder="Ví dụ: Giải thích dễ hiểu, Kể chuyện gần gũi"
        prepend-inner-icon="tabler-writing"
        maxlength="160"
        :counter="160"
        hint="Đặt tên để nhận diện và chọn lại mẫu khi tạo bài. Bạn có thể sửa tên trước khi lưu."
        persistent-hint
        required
        aria-required="true"
        :disabled="inputLocked"
        :error-messages="getFieldErrors('name')"
        class="mb-6"
      />

      <AppSelect
        v-model="modelId"
        :items="modelOptions"
        label="Model phân tích"
        placeholder="Dùng model mặc định"
        clearable
        :loading="catalogLoading"
        :disabled="inputLocked"
        :error-messages="getFieldErrors('model_id')"
        hint="Để trống để dùng model văn bản mặc định của hệ thống."
        persistent-hint
        class="mb-6"
      />

      <VAlert
        v-if="catalogError"
        type="warning"
        variant="tonal"
        class="mb-5"
      >
        <div class="d-flex flex-wrap align-center justify-space-between gap-3">
          <span>{{ catalogError }}</span>
          <VBtn
            variant="text"
            size="small"
            :disabled="inputLocked || catalogLoading"
            @click="emit('reloadCatalog')"
          >
            Tải lại model
          </VBtn>
        </div>
      </VAlert>

      <VTabs
        v-model="sourceTab"
        color="primary"
        show-arrows
        aria-label="Loại nguồn bài tham khảo"
        class="mb-5"
      >
        <VTab
          value="url"
          :disabled="inputLocked"
        >
          <VIcon
            start
            size="20"
            icon="tabler-link"
          />
          Từ URL
        </VTab>
        <VTab
          value="paste"
          :disabled="inputLocked"
        >
          <VIcon
            start
            size="20"
            icon="tabler-clipboard-text"
          />
          Dán nội dung
        </VTab>
        <VTab
          value="file"
          :disabled="inputLocked"
        >
          <VIcon
            start
            size="20"
            icon="tabler-file-upload"
          />
          Từ file
        </VTab>
      </VTabs>

      <VWindow v-model="sourceTab">
        <VWindowItem value="url">
          <div class="source-input-panel">
            <AppTextField
              v-model="sourceUrl"
              label="URL bài tham khảo"
              placeholder="https://example.com/bai-viet"
              type="url"
              prepend-inner-icon="tabler-link"
              hint="Nhập liên kết tới bài viết bạn muốn phân tích văn phong."
              persistent-hint
              :disabled="inputLocked"
              :error-messages="getFieldErrors('url')"
            />
            <VBtn
              class="mt-5"
              variant="tonal"
              prepend-icon="tabler-download"
              :loading="previewing"
              :disabled="inputLocked || !canPreview || !sourceUrl.trim()"
              @click="emit('preview')"
            >
              Lấy nội dung
            </VBtn>
            <p class="text-body-2 text-medium-emphasis mt-3 mb-0">
              Xem nội dung sau khi làm sạch trước khi gửi bài cho AI phân tích.
            </p>
            <p
              v-if="!canPreview"
              class="text-body-2 text-warning mt-3 mb-0"
            >
              Tài khoản chưa có quyền lấy nguồn URL. Bạn có thể dán nội dung hoặc đọc file .txt.
            </p>
          </div>
        </VWindowItem>

        <VWindowItem value="paste">
          <div
            class="ai-prompt-source__editor rounded"
            role="group"
            aria-label="Trình soạn thảo bài tham khảo"
            :inert="inputLocked"
            :aria-busy="inputLocked"
            :class="{ 'ai-prompt-source__editor--locked': inputLocked }"
          >
            <TiptapEditor
              v-model="sourceHtml"
              placeholder="Dán bài tham khảo hoặc nhập nội dung muốn phân tích văn phong..."
            />
          </div>
        </VWindowItem>

        <VWindowItem value="file">
          <div class="source-input-panel">
            <VFileInput
              v-model="sourceFile"
              label="File bài tham khảo"
              accept=".txt,.html,.htm,text/plain,text/html"
              prepend-icon=""
              prepend-inner-icon="tabler-file-upload"
              hint="Chọn file văn bản hoặc HTML chứa bài viết tham khảo."
              persistent-hint
              show-size
              clearable
              :disabled="inputLocked"
              :error-messages="getFieldErrors('file')"
            />
            <VBtn
              class="mt-5"
              variant="tonal"
              prepend-icon="tabler-file-import"
              :loading="previewing"
              :disabled="inputLocked || !canReadFile"
              @click="emit('preview')"
            >
              Đọc nội dung file
            </VBtn>
            <p class="text-body-2 text-medium-emphasis mt-3 mb-0">
              Đọc file văn bản hoặc làm sạch HTML để xem nguồn trước khi phân tích.
            </p>
            <p
              v-if="!canPreview && !isTextFile"
              class="text-body-2 text-warning mt-3 mb-0"
            >
              Tài khoản chưa có quyền làm sạch file HTML. File .txt vẫn có thể đọc trực tiếp.
            </p>
          </div>
        </VWindowItem>
      </VWindow>

      <AppTextarea
        v-if="sourceTab !== 'paste' && sourceText"
        v-model="sourceText"
        label="Nội dung đã đọc"
        :disabled="inputLocked"
        rows="10"
        auto-grow
        hint="Kiểm tra và chỉnh sửa văn bản này trước khi gửi AI phân tích văn phong."
        persistent-hint
        class="mt-5"
      />

      <VAlert
        v-if="getFieldErrors('reference_text').length"
        type="error"
        variant="tonal"
        class="mt-5"
      >
        <div
          v-for="message in getFieldErrors('reference_text')"
          :key="message"
        >
          {{ message }}
        </div>
      </VAlert>

      <VAlert
        v-if="sourceNotice"
        type="info"
        variant="tonal"
        class="mt-5"
      >
        {{ sourceNotice }}
      </VAlert>
    </VCardText>

    <VDivider />

    <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-3">
      <span class="text-body-2 text-medium-emphasis">
        Số từ: <span class="font-weight-medium text-high-emphasis">{{ wordCount }}</span>
      </span>

      <div class="d-flex flex-wrap align-center gap-2">
        <IconBtn
          aria-label="Khôi phục bài tham khảo mẫu"
          :disabled="inputLocked"
          @click="resetSource"
        >
          <VIcon icon="tabler-refresh" />
          <VTooltip
            activator="parent"
            location="top"
          >
            Khôi phục bài mẫu
          </VTooltip>
        </IconBtn>
        <IconBtn
          aria-label="Xóa nội dung bài tham khảo"
          :disabled="inputLocked"
          @click="clearSource"
        >
          <VIcon icon="tabler-trash" />
          <VTooltip
            activator="parent"
            location="top"
          >
            Xóa nội dung
          </VTooltip>
        </IconBtn>
        <VBtn
          prepend-icon="tabler-sparkles"
          :loading="busy"
          :disabled="!canAnalyze"
          @click="emit('analyze')"
        >
          Phân tích bài viết
        </VBtn>
      </div>
    </VCardText>
  </VCard>
</template>

<style scoped>
.ai-prompt-source__editor {
  overflow: hidden;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.ai-prompt-source__editor--locked {
  opacity: var(--v-disabled-opacity);
}

.ai-prompt-source__editor :deep(.editor) {
  background: rgba(var(--v-theme-on-surface), 0.02);
}

.ai-prompt-source__editor :deep(.ProseMirror) {
  padding: 1.5rem;
  min-block-size: 280px;
  color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity));
  font-size: 0.9375rem;
  line-height: 1.7;
  overflow-wrap: anywhere;
}

.ai-prompt-source__editor :deep(.ProseMirror h2),
.ai-prompt-source__editor :deep(.ProseMirror h3) {
  margin-block-end: 0.75rem;
  font-size: 1.125rem;
  font-weight: 500;
}

.ai-prompt-source__editor :deep(.ProseMirror p:not(:last-child)) {
  margin-block-end: 1rem;
}

.ai-prompt-source__editor :deep(.ProseMirror p.is-editor-empty:first-child::before) {
  color: rgba(var(--v-theme-on-surface), var(--v-disabled-opacity));
}

.source-input-panel {
  min-block-size: 220px;
  padding-block-end: 1rem;
}
</style>
