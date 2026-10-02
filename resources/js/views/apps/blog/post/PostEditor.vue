<!--
  =====================================================================
  CHỨC NĂNG FILE: Bọc TinyMCE cho nội dung Post, giữ HTML đồng bộ với SEO.
  CÁC HÀM/METHOD TRONG FILE: onMounted(): tải runtime; editorReady(): kết thúc chờ;
  editorFailed(): báo lỗi và giữ HTML; ensureEditable(): fallback khi editor bị khóa;
  setup(): bắt lỗi tài nguyên và thay đổi trạng thái chỉnh sửa;
  onBeforeUnmount(): dọn bộ đếm thời gian.
  INPUT/OUTPUT CỦA CLASS (tổng thể): HTML/disabled/placeholder -> v-model HTML.
  Chỉ bật self-host khi chủ dự án cấu hình GPL rõ ràng, hoặc dùng Tiny Cloud key.
  =====================================================================
-->
<script setup>
import { onBeforeUnmount, onMounted, shallowRef } from 'vue'
import Editor from '@tinymce/tinymce-vue'

const props = defineProps({ disabled: { type: Boolean, default: false }, placeholder: { type: String, default: 'Start writing your post...' } })
const content = defineModel({ type: String, default: '' })
const apiKey = import.meta.env.VITE_TINYMCE_API_KEY?.trim() || ''
const licenseKey = import.meta.env.VITE_TINYMCE_LICENSE_KEY?.trim() || ''
const selfHosted = licenseKey === 'gpl'
const configured = selfHosted || Boolean(apiKey)
const ready = shallowRef(false)
const initialized = shallowRef(false)
const error = shallowRef('')
let disposed = false
let initTimeout

const editorOptions = shallowRef({
  height: 420,
  menubar: 'edit view insert format tools table',
  plugins: 'lists link image table code wordcount',
  toolbar: 'undo redo | blocks | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | bullist numlist | link image table | removeformat code',
  'block_formats': 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4',
  placeholder: props.placeholder,
  promotion: false,
  'convert_urls': false,
  'automatic_uploads': false,
  'paste_data_images': false,
  'image_description': true,
  'content_style': 'body { font-family: sans-serif; font-size: 16px; } img { max-width: 100%; height: auto; }',

  /** Input: instance TinyMCE. Output: bắt lỗi tài nguyên và editor chỉ đọc ngoài ý muốn. */
  setup: editor => {
    editor.on('SkinLoadError PluginLoadError ThemeLoadError ModelLoadError', editorFailed)
    editor.on('SwitchMode DisabledStateChange', () => ensureEditable(editor))
  },
})

/** Input: lỗi khởi tạo hoặc timeout. Output: giữ HTML trong textarea, dừng editor lỗi. */
const editorFailed = () => {
  clearTimeout(initTimeout)
  error.value = 'Không tải được TinyMCE. Kiểm tra kết nối/cấu hình và tải lại trang; nội dung HTML vẫn được giữ bên dưới.'
  ready.value = false
}

/** Input: editor TinyMCE. Output: giữ HTML trong textarea khi editor bị khóa ngoài props.disabled. */
const ensureEditable = editor => {
  if (disposed || props.disabled)
    return
  if (editor?.mode?.isReadOnly?.() || editor?.options?.get?.('disabled')) {
    clearTimeout(initTimeout)
    error.value = 'TinyMCE đang bị khóa chỉnh sửa. Kiểm tra cấu hình/key của editor; bạn có thể tiếp tục chỉnh sửa HTML bên dưới.'
    ready.value = false
  }
}

/** Input: sự kiện init và instance TinyMCE. Output: dọn timeout, kiểm tra khả năng chỉnh sửa. */
const editorReady = (_event, editor) => {
  clearTimeout(initTimeout)
  initialized.value = true
  ensureEditable(editor)
}

/** Input: cấu hình build. Output: tải runtime self-host khi được chọn; lỗi không làm mất nội dung. */
onMounted(async () => {
  if (!configured)
    return
  try {
    if (selfHosted) {
      const { bundledStyles } = await import('./tinyMceRuntime')

      editorOptions.value = { ...editorOptions.value, skin: false, 'content_css': false, 'content_style': bundledStyles + '\n' + editorOptions.value.content_style }
    }
    if (disposed)
      return
    ready.value = true
    initTimeout = setTimeout(editorFailed, 20000)
  }
  catch { if (!disposed) editorFailed() }
})

/** Input: component sắp unmount. Output: dừng timeout và bỏ qua import đang chờ. */
onBeforeUnmount(() => {
  disposed = true
  clearTimeout(initTimeout)
})
</script>

<template>
  <div class="post-editor">
    <VAlert
      v-if="!configured || error"
      type="warning"
      variant="tonal"
      class="mb-4"
      role="alert"
    >
      {{ error || 'TinyMCE chưa được cấu hình. Chủ dự án cần chọn GPL self-host (VITE_TINYMCE_LICENSE_KEY=gpl) hoặc Tiny Cloud (VITE_TINYMCE_API_KEY), rồi khởi động lại Vite/build. Tạm thời có thể nhập HTML bên dưới.' }}
    </VAlert>
    <p
      v-else-if="!initialized"
      role="status"
    >
      Đang tải TinyMCE…
    </p>
    <!-- Giữ DOM TinyMCE trong wrapper riêng để teardown không xóa anchor của fallback. -->
    <div v-if="ready">
      <Editor
        v-model="content"
        :api-key="apiKey"
        :license-key="selfHosted ? licenseKey : undefined"
        cloud-channel="8"
        :init="editorOptions"
        :disabled="props.disabled"
        model-events="input change undo redo"
        @init="editorReady"
      />
    </div>
    <AppTextarea
      v-else
      v-model="content"
      label="Content (HTML)"
      :placeholder="props.placeholder"
      :disabled="props.disabled || (configured && !error)"
      rows="12"
    />
  </div>
</template>
