<!--
  =====================================================================
  Header/footer cố định qua AppDialogLayout; chỉ content ở giữa được cuộn.
  CHỨC NĂNG FILE: Bọc TinyMCE cho nội dung Post, giữ HTML đồng bộ với SEO.
  CÁC HÀM/METHOD TRONG FILE: onMounted(): tải runtime; editorReady(): kết thúc chờ;
  editorFailed(): báo lỗi và giữ HTML; ensureEditable(): fallback khi editor bị khóa;
  setup(): bắt lỗi tài nguyên và thay đổi trạng thái chỉnh sửa;
  fallbackCaret(): vị trí textarea; usePostInlineMedia(): picker/upload/ref/alt/caption;
  editorDialogChanged(): báo cửa sổ TinyMCE để dialog cha nhường focus tạm thời;
  onBeforeUnmount(): dọn bộ đếm thời gian và media scope.
  INPUT/OUTPUT CỦA CLASS (tổng thể): HTML/disabled/placeholder -> HTML, media-busy, editor-dialog;
  chọn/upload từ editor, backend xác nhận MediaAsset ID/URL khi lưu.
  Chỉ bật self-host khi chủ dự án cấu hình GPL rõ ràng, hoặc dùng Tiny Cloud key.
  =====================================================================
-->
<script setup>
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import { onBeforeUnmount, onMounted, reactive, shallowRef, useTemplateRef } from 'vue'
import Editor from '@tinymce/tinymce-vue'
import MediaLibraryDialog from '@/views/apps/media/field/MediaLibraryDialog.vue'
import { usePostInlineMedia } from '@/composables/usePostInlineMedia'

const props = defineProps({ disabled: { type: Boolean, default: false }, placeholder: { type: String, default: 'Start writing your post...' } })
const emit = defineEmits(['mediaBusy', 'editorDialog'])
const content = defineModel({ type: String, default: '' })
const fallback = useTemplateRef('fallback')

/**
 * =====================================================================
 * Input: textarea fallback hiện tại. Output: caret để chèn ảnh tại chỗ đang nhập.
 * =====================================================================
 */
const fallbackCaret = () => {
  const input = fallback.value?.$el?.querySelector?.('textarea')

  return input ? { start: input.selectionStart, end: input.selectionEnd } : null
}

const media = reactive(usePostInlineMedia({ content, disabled: () => props.disabled, fallbackCaret, onBusy: value => emit('mediaBusy', value) }))
const apiKey = import.meta.env.VITE_TINYMCE_API_KEY?.trim() || ''
const licenseKey = import.meta.env.VITE_TINYMCE_LICENSE_KEY?.trim() || ''
const selfHosted = licenseKey === 'gpl'
const configured = selfHosted || Boolean(apiKey)
const ready = shallowRef(false)
const initialized = shallowRef(false)
const error = shallowRef('')
let disposed = false
let initTimeout
let openWindows = 0

/** Input: OpenWindow/CloseWindow. Output: dialog cha nhường focus đúng thời gian cửa sổ Tiny mở. */
const editorDialogChanged = delta => {
  if (disposed) return
  openWindows = Math.max(0, openWindows + delta)
  emit('editorDialog', openWindows > 0)
}

const editorOptions = shallowRef({
  height: 420,

  // Menu/popup đi cùng editor trong vùng scroll và nằm trong lớp VDialog hiện tại.
  // Tiny mặc định gắn popup vào body nên chúng có thể bị scrim của dialog che.
  'ui_mode': 'split',
  menubar: 'edit view insert format tools table',
  plugins: 'lists link image table code wordcount',
  toolbar: 'undo redo | blocks | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | bullist numlist | link projectimage projectimageedit table | removeformat code',
  'block_formats': 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4',
  placeholder: props.placeholder,
  promotion: false,
  'convert_urls': false,
  'automatic_uploads': true,
  'paste_data_images': true,
  'images_file_types': 'jpg,jpeg,png,gif,webp,avif',
  'images_upload_handler': media.upload,
  'extended_valid_elements': 'img[src|alt|title|width|height|class|style|data-media-asset-id],figure[class],figcaption[class]',
  contextmenu: 'link table projectimageedit',
  menu: { insert: { title: 'Insert', items: 'projectimage projectimageedit link table' } },
  'image_description': true,
  'content_style': 'body { font-family: sans-serif; font-size: 16px; } img { max-width: 100%; height: auto; }',

  /** Input: instance TinyMCE. Output: bắt lỗi tài nguyên và editor chỉ đọc ngoài ý muốn. */
  setup: editor => {
    media.setEditor(editor)
    editor.on('SkinLoadError PluginLoadError ThemeLoadError ModelLoadError', editorFailed)
    editor.on('SwitchMode DisabledStateChange', () => ensureEditable(editor))
    editor.on('change SetContent NodeChange input', media.annotate)
    editor.on('OpenWindow', () => editorDialogChanged(1))
    editor.on('CloseWindow', () => editorDialogChanged(-1))
    editor.ui?.registry?.addButton('projectimage', { icon: 'image', tooltip: 'Chọn/upload ảnh MediaLibrary', onAction: media.openLibrary })
    editor.ui?.registry?.addButton('projectimageedit', { icon: 'edit-block', tooltip: 'Sửa mô tả và chú thích ảnh', onAction: media.editImage })
    editor.ui?.registry?.addMenuItem('projectimage', { icon: 'image', text: 'Ảnh MediaLibrary', onAction: media.openLibrary })
    editor.ui?.registry?.addMenuItem('projectimageedit', { icon: 'edit-block', text: 'Mô tả và chú thích ảnh', onAction: media.editImage })
  },
})

/** Input: lỗi khởi tạo hoặc timeout. Output: giữ HTML trong textarea, dừng editor lỗi. */
const editorFailed = () => {
  clearTimeout(initTimeout)
  error.value = 'Không tải được TinyMCE. Kiểm tra kết nối/cấu hình và tải lại trang; nội dung HTML vẫn được giữ bên dưới.'
  ready.value = false
  media.setEditor(null)
}

/** Input: editor TinyMCE. Output: giữ HTML trong textarea khi editor bị khóa ngoài props.disabled. */
const ensureEditable = editor => {
  if (disposed || props.disabled)
    return
  if (editor?.mode?.isReadOnly?.() || editor?.options?.get?.('disabled')) {
    clearTimeout(initTimeout)
    error.value = 'TinyMCE đang bị khóa chỉnh sửa. Kiểm tra cấu hình/key của editor; bạn có thể tiếp tục chỉnh sửa HTML bên dưới.'
    ready.value = false
    media.setEditor(null)
  }
}

/** Input: sự kiện init và instance TinyMCE. Output: dọn timeout, kiểm tra khả năng chỉnh sửa. */
const editorReady = (_event, editor) => {
  clearTimeout(initTimeout)
  initialized.value = true
  media.setEditor(editor)
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
  emit('editorDialog', false)
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
      ref="fallback"
      v-model="content"
      label="Content (HTML)"
      :placeholder="props.placeholder"
      :disabled="props.disabled || (configured && !error)"
      rows="12"
    />
    <VBtn
      v-if="!ready"
      variant="tonal"
      prepend-icon="tabler-photo"
      class="mt-3"
      :disabled="props.disabled"
      @click="media.openLibrary"
    >
      Chọn ảnh MediaLibrary
    </VBtn>
    <VAlert
      v-if="media.error"
      type="error"
      variant="tonal"
      class="mt-3"
    >
      {{ media.error }}
    </VAlert>
    <VAlert
      v-if="media.busy && !media.libraryOpen && !media.detailsOpen"
      type="info"
      variant="tonal"
      class="mt-3"
    >
      Ảnh đang upload hoặc còn URL tạm. Nội dung được giữ; chờ ảnh upload xong hoặc xóa ảnh tạm trước khi lưu.
    </VAlert>
    <MediaLibraryDialog
      v-if="media.libraryOpen"
      v-model:open="media.libraryOpen"
      kind="image"
      field="post.content_images"
      visibility="public"
      @select="media.selectAsset"
    />
    <VDialog
      scrollable
      :model-value="media.detailsOpen"
      max-width="560"
      @update:model-value="!$event && media.closeDetails()"
    >
      <AppDialogLayout
        title="Ảnh trong bài viết"
        @close="media.closeDetails()"
      >
        <VCardText>
          <VImg
            :src="media.selectedAsset?.file?.url"
            max-height="200"
            contain
            class="mb-4"
          />
          <AppTextField
            v-model="media.draft.alt"
            label="Mô tả ảnh (alt)"
            placeholder="Nhập mô tả nội dung ảnh"
            maxlength="1000"
            class="mb-4"
          />
          <AppTextarea
            v-model="media.draft.caption"
            label="Chú thích dưới ảnh"
            placeholder="Nhập chú thích hiển thị dưới ảnh"
            maxlength="2000"
            rows="2"
          />
          <VAlert
            v-if="media.error"
            type="error"
            variant="tonal"
            class="mt-3"
          >
            {{ media.error }}
          </VAlert>
        </VCardText>
        <template #footer>
          <VCardActions>
            <VSpacer /><VBtn
              variant="tonal"
              @click="media.closeDetails"
            >
              Hủy
            </VBtn><VBtn
              variant="flat"
              :disabled="props.disabled"
              @click="media.commit"
            >
              Lưu ảnh vào bài
            </VBtn>
          </VCardActions>
        </template>
      </AppDialogLayout>
    </VDialog>
  </div>
</template>

<style>
/* Cửa sổ TinyMCE gắn vào body; cao hơn stack VDialog 2400 của ứng dụng.
   Menu split vẫn ở trong editor, nên dialog MediaLibrary lồng nhau giữ đúng lớp. */
.tox.tox-tinymce-aux {
  z-index: 2500;
}
</style>
