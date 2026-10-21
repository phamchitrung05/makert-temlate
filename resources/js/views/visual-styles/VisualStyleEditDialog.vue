<!--
  =====================================================================
  CHỨC NĂNG FILE: Dialog xem chi tiết và chỉnh sửa Visual Style (2 cột).
  =====================================================================
  Hiển thị và chỉnh sửa thông tin phong cách hình ảnh, gallery ảnh tham chiếu,
  cấu hình thông số Style DNA theo các tab, tóm tắt phân tích và danh sách
  Shot Recipes. Tuân thủ chuẩn AppDialogLayout (header/footer cố định, chỉ
  body cuộn, nút đóng DialogCloseBtn nổi góc phải ngoài card), hệ thống
  typography, bo góc (6px) và form wrapper App* của dự án.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - syncFromStyle(): đồng bộ dữ liệu từ prop `style` (kèm fallback mặc định).
  - loadStyleDetail(): gọi API lấy chi tiết style + shots khi mở dialog.
  - statusMeta: computed nhãn và màu trạng thái lifecycle của style.
  - dnaJsonPreview: computed chuỗi JSON định dạng đẹp khi bật chế độ xem JSON.
  - triggerAddImage(): mở bộ chọn ảnh mẫu thêm vào gallery.
  - addKeyword(): thêm từ khóa vào danh sách keywords.
  - submitMeta(): validate tên style và emit 'save' lên page cha.
  - close()/onAfterLeave(): đóng dialog và dọn state sau hiệu ứng đóng.
  - watcher [modelValue, style]: nạp dữ liệu khi mở dialog.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : props `modelValue`, `style`, `saving`, `errorMessage`.
  - OUTPUT: emit `update:modelValue`, `save`, `close`, `afterLeave`.
  - SIDE EFFECT: gọi `visualStylesService.show()` để nạp đủ shots khi mở.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Payload/DNA keys follow the Laravel Visual Style API contract. */
import { computed, reactive, ref, watch } from 'vue'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import { visualStylesService } from '@/services/visualStyles'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  style: { type: Object, default: null },
  saving: { type: Boolean, default: false },
  errorMessage: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'save', 'close', 'afterLeave'])

const DEFAULT_GALLERY = [
  'https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=800',
  'https://images.unsplash.com/photo-1447933601403-0c6688de566e?w=400',
  'https://images.unsplash.com/photo-1554118811-1e0d58224f24?w=400',
  'https://images.unsplash.com/photo-1517701550927-30cf4ba1dba5?w=400',
]

const DEFAULT_SUMMARY = 'Hình ảnh là cà phê với latte art trên bàn gỗ, hạt cà phê rải xung quanh, ánh sáng buổi sáng ấm áp, không gian quán cà phê, phong cách chụp ảnh sản phẩm chuyên nghiệp, tự nhiên và gần gũi. Toàn bộ ảnh phù hợp để tái tạo phong cách thương mại (bố cục cân đối, chủ thể ở giữa, nền có chiều sâu, ánh sáng mềm, bokeh nhẹ, màu nâu ấm).'

const DEFAULT_SHOT_RECIPES = [
  {
    code: 'hero_front',
    name: 'Hero Front View',
    desc: 'Straight-on centered hero shot of the product.',
    ratio: '1:1',
    active: true,
    thumb: 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=150',
  },
  {
    code: 'detail_macro',
    name: 'Detail Macro',
    desc: 'Tight macro crop highlighting surface and texture.',
    ratio: '4:5',
    active: true,
    thumb: 'https://images.unsplash.com/photo-1447933601403-0c6688de566e?w=150',
  },
  {
    code: 'flat_lay_top',
    name: 'Flat Lay Top Down',
    desc: 'Overhead flat lay arrangement on a table.',
    ratio: '4:5',
    active: false,
    thumb: 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=150',
  },
  {
    code: 'three_quarter_angle',
    name: 'Three Quarter Angle',
    desc: 'Three-quarter perspective at eye level.',
    ratio: '4:5',
    active: true,
    thumb: 'https://images.unsplash.com/photo-1514432324607-a09d9b4aefdd?w=150',
  },
]

const CATEGORY_OPTIONS = ['Đồ uống', 'Lifestyle', 'Ẩm thực', 'Thương mại', 'Tối giản']

const STATUS_META = {
  draft: { label: 'Nháp', color: 'secondary' },
  queued: { label: 'Chờ phân tích', color: 'warning' },
  analyzing: { label: 'Đang phân tích', color: 'info' },
  analyzed: { label: 'Đã phân tích', color: 'success' },
  ready: { label: 'Sẵn sàng', color: 'info' },
  active: { label: 'Hoạt động', color: 'success' },
  failed: { label: 'Lỗi', color: 'error' },
  archived: { label: 'Đã lưu trữ', color: 'secondary' },
}

const activeDnaTab = ref('composition')
const showJson = ref(false)
const localError = ref('')

// Danh sách ảnh trong Gallery
const galleryImages = ref([...DEFAULT_GALLERY])
const selectedThumb = ref(galleryImages.value[0])

// Dữ liệu Thông tin style (Cột trái)
const styleInfo = reactive({
  name: 'Cà phê sáng – Năng lượng tích cực',
  categories: ['Đồ uống', 'Lifestyle'],
  enableGeneration: true,
  description:
    'Phong cách ảnh sản phẩm đồ uống cà phê trong không gian quán, ánh sáng tự nhiên ấm áp, bokeh, tone nâu ấm, mang cảm giác gần gũi và thư giãn.',
  keywords: [
    'coffee',
    'latte',
    'warm light',
    'bokeh',
    'cozy atmosphere',
    'cafe interior',
    'product photography',
  ],
  palette: ['#3B1E14', '#8C4A27', '#F39C42', '#F5E1D3', '#E5E5E5'],
})

const styleSummary = ref(DEFAULT_SUMMARY)

// Danh sách các Tab trong Style DNA (dùng bộ icon Tabler chuẩn dự án)
const dnaTabs = [
  { value: 'composition', label: 'Bố cục', icon: 'tabler-layout-dashboard' },
  { value: 'camera', label: 'Máy ảnh', icon: 'tabler-camera' },
  { value: 'lighting', label: 'Ánh sáng', icon: 'tabler-bulb' },
  { value: 'setting', label: 'Bối cảnh', icon: 'tabler-photo' },
  { value: 'color', label: 'Màu & Tâm trạng', icon: 'tabler-palette' },
  { value: 'preservation', label: 'Bảo toàn', icon: 'tabler-shield-check' },
  { value: 'constraints', label: 'Ràng buộc', icon: 'tabler-lock' },
]

// Thông số cấu hình Style DNA
const dnaConfig = reactive({
  framing: 'medium',
  subjectPosition: 'center',
  orientation: 'square',
  balance: 'symmetric',
  negativeSpace: 'moderate',
  subjectScale: 0.5,
  depthLayers: 'subject, background',
  shotType: 'lifestyle',
  angle: 'three_quarter',
  lensLook: 'standard',
  depthOfField: 'shallow',
  focus: 'selective',
  perspective: 'three_quarter',
  lightingType: 'natural_window',
  lightingDirection: 'side',
  lightingQuality: 'soft',
  lightingIntensity: 'medium',
  lightingTemperature: 'warm',
  shadows: 'soft',
  highlights: 'controlled',
  environmentType: 'lifestyle_interior',
  backgroundStyle: 'bokeh',
  backgroundBlur: 'strong',
  backgroundDetail: 'Không gian quán cà phê ấm cúng với ánh sáng cửa sổ tự nhiên',
  props: 'Mặt bàn gỗ sồi, hạt cà phê rang, khăn linen màu be',
  environmentAdaptation: true,
  saturation: 'natural',
  contrast: 'moderate',
  mood: 'cozy, warm, artisanal',
  preserveShape: true,
  preserveColor: true,
  preserveLogo: true,
  preserveText: true,
  preserveMaterial: true,
  allowPerspectiveChange: true,
  allowOcclusion: false,
  photorealistic: true,
  commercialQuality: true,
  avoid3dRender: true,
  avoidIllustration: true,
  avoidUnrealisticHands: true,
})

// Danh sách Shot Recipes
const shotRecipes = ref(DEFAULT_SHOT_RECIPES.map(item => ({ ...item })))

const statusMeta = computed(() => {
  const status = props.style?.status || 'analyzed'

  return STATUS_META[status] ?? { label: status, color: 'secondary' }
})

const dnaJsonPreview = computed(() => JSON.stringify({
  composition: {
    framing: dnaConfig.framing,
    subject_position: dnaConfig.subjectPosition,
    orientation: dnaConfig.orientation,
    visual_balance: dnaConfig.balance,
    negative_space: dnaConfig.negativeSpace,
    subject_scale: dnaConfig.subjectScale,
    depth_layers: dnaConfig.depthLayers.split(',').map(s => s.trim()).filter(Boolean),
  },
  camera: {
    shot_type: dnaConfig.shotType,
    angle: dnaConfig.angle,
    lens_look: dnaConfig.lensLook,
    depth_of_field: dnaConfig.depthOfField,
    focus: dnaConfig.focus,
    perspective: dnaConfig.perspective,
  },
  lighting: {
    type: dnaConfig.lightingType,
    direction: dnaConfig.lightingDirection,
    quality: dnaConfig.lightingQuality,
    intensity: dnaConfig.lightingIntensity,
    temperature: dnaConfig.lightingTemperature,
    shadows: dnaConfig.shadows,
    highlights: dnaConfig.highlights,
  },
  environment: {
    type: dnaConfig.environmentType,
    background_style: dnaConfig.backgroundStyle,
    background_blur: dnaConfig.backgroundBlur,
    background_detail: dnaConfig.backgroundDetail,
    props: dnaConfig.props,
    environment_adaptation: dnaConfig.environmentAdaptation,
  },
  color_mood: {
    saturation: dnaConfig.saturation,
    contrast: dnaConfig.contrast,
    palette: styleInfo.palette,
    mood: dnaConfig.mood.split(',').map(s => s.trim()).filter(Boolean),
  },
}, null, 2))

/**
 * =====================================================================
 * CHỨC NĂNG: Đồng bộ state hiển thị từ dữ liệu style truyền vào.
 * INPUT: object style từ prop hoặc từ API show().
 * OUTPUT: cập nhật styleInfo, dnaConfig, styleSummary và shotRecipes.
 * SIDE EFFECT: chỉ cập nhật reactive state cục bộ.
 * =====================================================================
 */
function syncFromStyle(data) {
  if (!data) return

  styleInfo.name = data.name || 'Cà phê sáng – Năng lượng tích cực'
  styleInfo.categories = data.category
    ? String(data.category).split(',').map(item => item.trim()).filter(Boolean)
    : ['Đồ uống', 'Lifestyle']
  styleInfo.enableGeneration = Boolean(data.is_enabled)

  const dna = data.dna_json ?? {}
  if (dna.identity?.description)
    styleInfo.description = dna.identity.description
  if (Array.isArray(dna.identity?.keywords) && dna.identity.keywords.length)
    styleInfo.keywords = [...dna.identity.keywords]

  if (Array.isArray(dna.color_mood?.palette) && dna.color_mood.palette.length) {
    const hexMatches = dna.color_mood.palette
      .map(item => String(item).match(/#[0-9a-f]{3,8}/i)?.[0] || (String(item).startsWith('#') ? item : null))
      .filter(Boolean)

    if (hexMatches.length)
      styleInfo.palette = hexMatches
  }

  styleSummary.value = data.summary || DEFAULT_SUMMARY

  if (dna.composition) {
    dnaConfig.framing = dna.composition.framing ?? dnaConfig.framing
    dnaConfig.subjectPosition = dna.composition.subject_position ?? dnaConfig.subjectPosition
    dnaConfig.orientation = dna.composition.orientation ?? dnaConfig.orientation
    dnaConfig.balance = dna.composition.visual_balance ?? dnaConfig.balance
    dnaConfig.negativeSpace = dna.composition.negative_space ?? dnaConfig.negativeSpace
    dnaConfig.subjectScale = dna.composition.subject_scale ?? dnaConfig.subjectScale
    if (Array.isArray(dna.composition.depth_layers) && dna.composition.depth_layers.length)
      dnaConfig.depthLayers = dna.composition.depth_layers.join(', ')
  }

  if (Array.isArray(data.shots) && data.shots.length) {
    shotRecipes.value = data.shots.map((shot, idx) => ({
      id: shot.id,
      code: shot.code,
      name: shot.name,
      desc: shot.description || 'Góc chụp giữ nguyên Style DNA.',
      ratio: shot.suggested_aspect_ratio || '1:1',
      active: shot.is_active !== false,
      thumb: DEFAULT_GALLERY[idx % DEFAULT_GALLERY.length],
    }))
  }
  else {
    shotRecipes.value = DEFAULT_SHOT_RECIPES.map(item => ({ ...item }))
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Tải chi tiết Visual Style từ API khi mở dialog.
 * INPUT: id của style.
 * OUTPUT: đồng bộ đầy đủ DNA và danh sách shots.
 * SIDE EFFECT: gọi GET `/admin/ai/visual-styles/{id}`.
 * =====================================================================
 */
async function loadStyleDetail(id) {
  if (!id) return
  try {
    const detail = await visualStylesService.show(id)
    if (props.modelValue && props.style?.id === id)
      syncFromStyle(detail)
  }
  catch {
    // Giữ dữ liệu tóm tắt từ prop `style` nếu gọi chi tiết lỗi
  }
}

watch(
  () => [props.modelValue, props.style],
  ([visible, currentStyle]) => {
    if (!visible) return
    localError.value = ''
    if (currentStyle) {
      syncFromStyle(currentStyle)
      if (currentStyle.id)
        void loadStyleDetail(currentStyle.id)
    }
  },
  { immediate: true },
)

/**
 * =====================================================================
 * CHỨC NĂNG: Thao tác thêm ảnh mẫu vào gallery.
 * INPUT: sự kiện click.
 * OUTPUT: không có.
 * =====================================================================
 */
function triggerAddImage() {
  // Mở chọn ảnh mẫu bổ sung khi tích hợp upload nhiều ảnh
}

/**
 * =====================================================================
 * CHỨC NĂNG: Thêm từ khóa mẫu vào danh sách keywords của style.
 * INPUT: sự kiện click nút Thêm.
 * OUTPUT: cập nhật mảng `styleInfo.keywords`.
 * =====================================================================
 */
function addKeyword() {
  if (!styleInfo.keywords.includes('natural wood'))
    styleInfo.keywords.push('natural wood')
}

/**
 * =====================================================================
 * CHỨC NĂNG: Validate và phát sự kiện lưu thông tin style lên page.
 * INPUT: dữ liệu trên `styleInfo`.
 * OUTPUT: emit `save` với `{ id, name, category, is_enabled }`.
 * =====================================================================
 */
function submitMeta() {
  if (!styleInfo.name?.trim()) {
    localError.value = 'Tên style là bắt buộc.'

    return
  }
  localError.value = ''
  if (!props.style?.id) {
    close()

    return
  }
  emit('save', {
    id: props.style.id,
    name: styleInfo.name.trim(),
    category: styleInfo.categories.join(', ').trim() || null,
    is_enabled: Boolean(styleInfo.enableGeneration),
  })
}

/**
 * =====================================================================
 * CHỨC NĂNG: Đóng dialog theo chuẩn AppDialogLayout.
 * INPUT: sự kiện đóng từ nút X hoặc nút Đóng ở footer.
 * OUTPUT: emit `update:modelValue` = false và `close`.
 * =====================================================================
 */
function close() {
  if (props.saving) return
  emit('update:modelValue', false)
  emit('close')
}

/**
 * =====================================================================
 * CHỨC NĂNG: Dọn trạng thái tạm sau khi hiệu ứng đóng hoàn tất.
 * INPUT: sự kiện `@after-leave` của VDialog.
 * OUTPUT: reset tab/error và emit `afterLeave`.
 * =====================================================================
 */
function onAfterLeave() {
  localError.value = ''
  showJson.value = false
  activeDnaTab.value = 'composition'
  emit('afterLeave')
}
</script>

<template>
  <VDialog
    :model-value="props.modelValue"
    max-width="1440"
    scrollable
    :persistent="props.saving"
    @update:model-value="!$event && close()"
    @after-leave="onAfterLeave"
  >
    <AppDialogLayout
      close-label="Đóng chi tiết Visual Style"
      :close-disabled="props.saving"
      @close="close"
    >
      <!-- ================= 1. HEADER DIALOG (CỐ ĐỊNH) ================= -->
      <template #header>
        <VCardItem class="px-6 py-4">
          <div class="d-flex flex-wrap align-center justify-space-between gap-4 w-100">
            <div class="min-w-0">
              <div class="d-flex align-center flex-wrap gap-2">
                <VCardTitle class="pa-0">
                  Chi tiết Visual Style
                </VCardTitle>
                <VChip
                  size="small"
                  :color="statusMeta.color"
                  variant="tonal"
                >
                  {{ statusMeta.label }}
                </VChip>
              </div>
              <VCardSubtitle class="pa-0 mt-1 text-wrap">
                Xem và chỉnh sửa thông tin phong cách hình ảnh, Style DNA và các Shot Recipes.
              </VCardSubtitle>
            </div>

            <!-- Cụm nút hành động bên phải (nút X dùng DialogCloseBtn chuẩn của AppDialogLayout) -->
            <div class="d-flex align-center flex-wrap gap-2">
              <VBtn
                icon="tabler-dots"
                variant="tonal"
                color="secondary"
                size="small"
                aria-label="Tùy chọn khác"
              />
              <VBtn
                variant="tonal"
                color="secondary"
                size="small"
                prepend-icon="tabler-copy"
                :disabled="props.saving"
              >
                Nhân bản
              </VBtn>
              <VBtn
                variant="tonal"
                color="secondary"
                size="small"
                prepend-icon="tabler-edit"
                :disabled="props.saving"
                @click="submitMeta"
              >
                Chỉnh sửa
              </VBtn>
              <VBtn
                color="primary"
                size="small"
                prepend-icon="tabler-sparkles"
                :disabled="props.saving"
              >
                Tạo ảnh từ style
              </VBtn>
            </div>
          </div>
        </VCardItem>
      </template>

      <!-- ================= 2. BODY DIALOG (2 CỘT - CHỈ VÙNG NÀY CUỘN) ================= -->
      <VCardText class="pa-6">
        <VAlert
          v-if="localError || props.errorMessage"
          type="error"
          variant="tonal"
          class="mb-6"
          density="compact"
        >
          {{ localError || props.errorMessage }}
        </VAlert>

        <VRow>
          <!-- ---------------- CỘT TRÁI: GALLERY & THÔNG TIN STYLE ---------------- -->
          <VCol
            cols="12"
            lg="4"
          >
            <!-- Khung Gallery ảnh mẫu -->
            <VCard
              variant="outlined"
              class="mb-6"
            >
              <VCardText class="pa-4">
                <!-- Ảnh chính lớn -->
                <div class="position-relative rounded overflow-hidden mb-3">
                  <VImg
                    :src="selectedThumb"
                    height="250"
                    cover
                    class="rounded gallery-main-image"
                  />
                  <VBtn
                    icon="tabler-arrows-maximize"
                    size="x-small"
                    variant="flat"
                    color="surface"
                    class="expand-btn"
                    aria-label="Phóng to ảnh"
                  />
                </div>

                <!-- Hàng 5 ô Thumbnail bên dưới -->
                <div class="thumb-grid">
                  <div
                    v-for="(img, idx) in galleryImages"
                    :key="idx"
                    class="thumb-item rounded overflow-hidden cursor-pointer"
                    :class="{ 'thumb-active': selectedThumb === img }"
                    @click="selectedThumb = img"
                  >
                    <VImg
                      :src="img"
                      height="62"
                      cover
                      class="gallery-thumb-image"
                    />
                  </div>

                  <!-- Nút + Thêm ảnh -->
                  <div
                    class="add-thumb-box rounded d-flex flex-column align-center justify-center cursor-pointer"
                    @click="triggerAddImage"
                  >
                    <VIcon
                      icon="tabler-plus"
                      color="primary"
                      size="18"
                    />
                    <span class="text-caption font-weight-medium text-primary">
                      Thêm ảnh
                    </span>
                  </div>
                </div>
              </VCardText>
            </VCard>

            <!-- Khung Thông tin style -->
            <VCard
              title="Thông tin style"
              variant="outlined"
            >
              <VCardText class="d-flex flex-column gap-4">
                <!-- Tên style -->
                <AppTextField
                  v-model="styleInfo.name"
                  label="Tên style"
                  placeholder="Nhập tên phong cách..."
                />

                <!-- Nhóm phong cách -->
                <AppCombobox
                  v-model="styleInfo.categories"
                  :items="CATEGORY_OPTIONS"
                  label="Nhóm phong cách"
                  multiple
                  chips
                  closable-chips
                />

                <!-- Switch Bật dùng cho generation -->
                <div>
                  <VSwitch
                    v-model="styleInfo.enableGeneration"
                    label="Bật dùng cho generation"
                    color="primary"
                    hide-details
                  />
                  <div class="text-caption text-medium-emphasis mt-1">
                    Chỉ bật khi style đã được phân tích và kiểm tra.
                  </div>
                </div>

                <!-- Mô tả ngắn -->
                <AppTextarea
                  v-model="styleInfo.description"
                  label="Mô tả ngắn"
                  rows="3"
                />

                <!-- Từ khóa -->
                <div>
                  <VLabel class="mb-2 text-body-2 text-high-emphasis d-block">
                    Từ khóa
                  </VLabel>
                  <div class="d-flex flex-wrap gap-2">
                    <VChip
                      v-for="kw in styleInfo.keywords"
                      :key="kw"
                      size="small"
                      color="primary"
                      variant="tonal"
                    >
                      {{ kw }}
                    </VChip>
                    <VChip
                      size="small"
                      variant="outlined"
                      color="secondary"
                      prepend-icon="tabler-plus"
                      class="cursor-pointer"
                      @click="addKeyword"
                    >
                      Thêm
                    </VChip>
                  </div>
                </div>

                <!-- Bảng màu chủ đạo -->
                <div>
                  <VLabel class="mb-2 text-body-2 text-high-emphasis d-block">
                    Bảng màu chủ đạo
                  </VLabel>
                  <div class="d-flex align-center flex-wrap gap-2">
                    <div
                      v-for="color in styleInfo.palette"
                      :key="color"
                      class="palette-swatch rounded-circle"
                      :style="{ backgroundColor: color }"
                      :title="color"
                    />
                  </div>
                </div>
              </VCardText>
            </VCard>
          </VCol>

          <!-- ---------------- CỘT PHẢI: STYLE DNA & SHOT RECIPES ---------------- -->
          <VCol
            cols="12"
            lg="8"
          >
            <!-- 1. Card Style DNA -->
            <VCard
              variant="outlined"
              class="mb-6"
            >
              <VCardItem>
                <template #title>
                  Style DNA
                </template>
                <template #append>
                  <VBtn
                    color="primary"
                    size="small"
                  >
                    Lưu DNA
                  </VBtn>
                </template>
              </VCardItem>

              <VCardText>
                <!-- Thanh Tabs DNA & Nút JSON -->
                <div class="d-flex justify-space-between align-center border-b pb-2 mb-4 flex-wrap gap-2">
                  <VTabs
                    v-model="activeDnaTab"
                    color="primary"
                    density="comfortable"
                    show-arrows
                    class="flex-grow-1"
                  >
                    <VTab
                      v-for="tab in dnaTabs"
                      :key="tab.value"
                      :value="tab.value"
                    >
                      <VIcon
                        :icon="tab.icon"
                        size="18"
                        class="me-1"
                      />
                      {{ tab.label }}
                    </VTab>
                  </VTabs>

                  <VBtn
                    variant="tonal"
                    color="secondary"
                    size="small"
                    :prepend-icon="showJson ? 'tabler-forms' : 'tabler-code'"
                    @click="showJson = !showJson"
                  >
                    {{ showJson ? 'Form' : 'JSON' }}
                  </VBtn>
                </div>

                <!-- Chế độ xem JSON -->
                <div
                  v-if="showJson"
                  class="dna-json-viewer pa-4 rounded mb-4"
                >
                  <pre class="text-body-2 mb-0 font-mono">{{ dnaJsonPreview }}</pre>
                </div>

                <!-- Form cấu hình thông số theo Tab -->
                <VWindow
                  v-else
                  v-model="activeDnaTab"
                  class="mb-4"
                >
                  <!-- Tab Bố cục -->
                  <VWindowItem value="composition">
                    <VRow>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.framing"
                          label="Khung hình"
                          :items="['extreme_close_up', 'close_up', 'medium_close_up', 'medium', 'medium_wide', 'wide', 'extreme_wide']"
                        />
                      </VCol>

                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.subjectPosition"
                          label="Vị trí chủ thể"
                          :items="['center', 'rule_of_thirds_left', 'rule_of_thirds_right', 'top_third', 'bottom_third', 'off_center']"
                        />
                      </VCol>

                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.orientation"
                          label="Hướng ảnh"
                          :items="['square', 'landscape', 'portrait']"
                        />
                      </VCol>

                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.balance"
                          label="Cân bằng"
                          :items="['symmetric', 'asymmetric', 'dynamic', 'static']"
                        />
                      </VCol>

                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.negativeSpace"
                          label="Khoảng trống"
                          :items="['none', 'minimal', 'moderate', 'dominant']"
                        />
                      </VCol>

                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <div class="d-flex justify-space-between align-center mb-1">
                          <VLabel class="text-body-2 text-high-emphasis">
                            Kích thước chủ thể (0–1)
                          </VLabel>
                          <VChip
                            size="x-small"
                            variant="tonal"
                            color="primary"
                          >
                            {{ dnaConfig.subjectScale }}
                          </VChip>
                        </div>
                        <VSlider
                          v-model="dnaConfig.subjectScale"
                          :min="0"
                          :max="1"
                          :step="0.05"
                          color="primary"
                          hide-details
                        />
                      </VCol>

                      <VCol cols="12">
                        <AppTextField
                          v-model="dnaConfig.depthLayers"
                          label="Lớp chiều sâu (phân cách bởi dấu phẩy)"
                          placeholder="foreground, midground, background"
                        />
                      </VCol>
                    </VRow>
                  </VWindowItem>

                  <!-- Tab Máy ảnh -->
                  <VWindowItem value="camera">
                    <VRow>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.shotType"
                          label="Kiểu shot"
                          :items="['macro', 'detail', 'product_single', 'product_group', 'lifestyle', 'flat_lay', 'hero']"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.angle"
                          label="Góc máy"
                          :items="['eye_level', 'high_angle', 'low_angle', 'birds_eye', 'worms_eye', 'dutch', 'three_quarter']"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.lensLook"
                          label="Ống kính"
                          :items="['wide', 'standard', 'telephoto', 'macro']"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.depthOfField"
                          label="Độ sâu trường ảnh"
                          :items="['shallow', 'moderate', 'deep']"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.focus"
                          label="Lấy nét"
                          :items="['sharp_all', 'selective', 'soft']"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.perspective"
                          label="Phối cảnh"
                          :items="['frontal', 'three_quarter', 'profile', 'top_down', 'isometric']"
                        />
                      </VCol>
                    </VRow>
                  </VWindowItem>

                  <!-- Tab Ánh sáng -->
                  <VWindowItem value="lighting">
                    <VRow>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.lightingType"
                          label="Kiểu ánh sáng"
                          :items="['studio_softbox', 'natural_window', 'golden_hour', 'dramatic', 'backlit', 'rim', 'flat', 'mixed']"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.lightingDirection"
                          label="Hướng sáng"
                          :items="['front', 'front_side', 'side', 'back', 'top', 'bottom', 'surround']"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.lightingQuality"
                          label="Chất lượng sáng"
                          :items="['soft', 'hard', 'diffused', 'specular']"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.lightingIntensity"
                          label="Cường độ"
                          :items="['low', 'medium', 'high']"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.lightingTemperature"
                          label="Nhiệt độ màu"
                          :items="['warm', 'neutral', 'cool']"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.shadows"
                          label="Bóng đổ"
                          :items="['none', 'soft', 'defined', 'hard']"
                        />
                      </VCol>
                    </VRow>
                  </VWindowItem>

                  <!-- Tab Bối cảnh -->
                  <VWindowItem value="setting">
                    <VRow>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.environmentType"
                          label="Kiểu bối cảnh"
                          :items="['studio_solid', 'studio_gradient', 'lifestyle_interior', 'lifestyle_exterior', 'abstract', 'surface_texture']"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.backgroundStyle"
                          label="Kiểu nền"
                          :items="['solid_color', 'gradient', 'textured', 'scene', 'bokeh', 'prop_styled']"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.backgroundBlur"
                          label="Làm mờ nền"
                          :items="['none', 'slight', 'moderate', 'strong']"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppTextField
                          v-model="dnaConfig.props"
                          label="Đạo cụ"
                        />
                      </VCol>
                      <VCol cols="12">
                        <AppTextField
                          v-model="dnaConfig.backgroundDetail"
                          label="Chi tiết nền"
                        />
                      </VCol>
                    </VRow>
                  </VWindowItem>

                  <!-- Tab Màu & Tâm trạng -->
                  <VWindowItem value="color">
                    <VRow>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.saturation"
                          label="Độ bão hòa"
                          :items="['desaturated', 'muted', 'natural', 'vivid', 'high']"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.contrast"
                          label="Độ tương phản"
                          :items="['low', 'moderate', 'high']"
                        />
                      </VCol>
                      <VCol cols="12">
                        <AppTextField
                          v-model="dnaConfig.mood"
                          label="Tâm trạng (phân cách bởi dấu phẩy)"
                        />
                      </VCol>
                    </VRow>
                  </VWindowItem>

                  <!-- Tab Bảo toàn -->
                  <VWindowItem value="preservation">
                    <VRow>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <VSwitch
                          v-model="dnaConfig.preserveShape"
                          label="Giữ hình dáng"
                          color="primary"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <VSwitch
                          v-model="dnaConfig.preserveColor"
                          label="Giữ màu sắc"
                          color="primary"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <VSwitch
                          v-model="dnaConfig.preserveLogo"
                          label="Giữ logo"
                          color="primary"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <VSwitch
                          v-model="dnaConfig.preserveText"
                          label="Giữ chữ"
                          color="primary"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <VSwitch
                          v-model="dnaConfig.preserveMaterial"
                          label="Giữ chất liệu"
                          color="primary"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <VSwitch
                          v-model="dnaConfig.allowPerspectiveChange"
                          label="Cho đổi phối cảnh"
                          color="primary"
                        />
                      </VCol>
                    </VRow>
                  </VWindowItem>

                  <!-- Tab Ràng buộc -->
                  <VWindowItem value="constraints">
                    <VRow>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <VSwitch
                          v-model="dnaConfig.photorealistic"
                          label="Ảnh thật (Photorealistic)"
                          color="primary"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <VSwitch
                          v-model="dnaConfig.commercialQuality"
                          label="Chất lượng thương mại"
                          color="primary"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <VSwitch
                          v-model="dnaConfig.avoid3dRender"
                          label="Tránh 3D render"
                          color="primary"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <VSwitch
                          v-model="dnaConfig.avoidIllustration"
                          label="Tránh minh họa"
                          color="primary"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <VSwitch
                          v-model="dnaConfig.avoidUnrealisticHands"
                          label="Tránh tay phi thực tế"
                          color="primary"
                        />
                      </VCol>
                    </VRow>
                  </VWindowItem>
                </VWindow>

                <!-- Khung Tóm tắt phân tích -->
                <div class="analysis-summary-box pa-4 rounded d-flex align-start">
                  <VAvatar
                    color="primary"
                    variant="tonal"
                    size="32"
                    rounded
                    class="me-3 flex-shrink-0"
                  >
                    <VIcon
                      icon="tabler-info-circle"
                      size="18"
                    />
                  </VAvatar>
                  <div>
                    <div class="text-subtitle-2 font-weight-medium text-high-emphasis mb-1">
                      Tóm tắt phân tích
                    </div>
                    <p class="text-body-2 text-medium-emphasis mb-0">
                      {{ styleSummary }}
                    </p>
                  </div>
                </div>
              </VCardText>
            </VCard>

            <!-- 2. Card Shot Recipes -->
            <VCard variant="outlined">
              <VCardItem>
                <template #title>
                  <div class="d-flex align-center gap-2">
                    <span>Shot Recipes</span>
                    <VChip
                      size="small"
                      color="primary"
                      variant="tonal"
                    >
                      {{ shotRecipes.length }}
                    </VChip>
                  </div>
                </template>
                <template #append>
                  <VBtn
                    color="primary"
                    size="small"
                    prepend-icon="tabler-plus"
                  >
                    Thêm Recipe
                  </VBtn>
                </template>
              </VCardItem>

              <VCardText class="pt-0">
                <!-- Bảng danh sách Shot Recipes -->
                <VTable
                  density="comfortable"
                  class="recipes-table"
                >
                  <thead>
                    <tr class="text-uppercase text-caption text-medium-emphasis">
                      <th style="inline-size: 56px;">
                        #
                      </th>
                      <th style="inline-size: 200px;">
                        CODE
                      </th>
                      <th>
                        TÊN
                      </th>
                      <th style="inline-size: 84px;">
                        RATIO
                      </th>
                      <th style="inline-size: 92px;">
                        ACTIVE
                      </th>
                      <th
                        class="text-end"
                        style="inline-size: 96px;"
                      >
                        THAO TÁC
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="(recipe, index) in shotRecipes"
                      :key="recipe.code"
                    >
                      <!-- Thứ tự & Kéo thả -->
                      <td class="text-body-2 text-medium-emphasis">
                        <div class="d-flex align-center">
                          <VIcon
                            size="16"
                            icon="tabler-grip-vertical"
                            class="me-1 text-disabled cursor-grab"
                          />
                          <span>{{ index + 1 }}</span>
                        </div>
                      </td>

                      <!-- Thumbnail & Code -->
                      <td>
                        <div class="d-flex align-center py-2">
                          <VAvatar
                            rounded
                            size="38"
                            class="me-3 border"
                          >
                            <VImg
                              :src="recipe.thumb"
                              cover
                            />
                          </VAvatar>
                          <code class="text-body-2 font-mono text-high-emphasis">
                            {{ recipe.code }}
                          </code>
                        </div>
                      </td>

                      <!-- Tên & Mô tả góc máy -->
                      <td>
                        <div class="py-1">
                          <div class="text-body-2 font-weight-medium text-high-emphasis">
                            {{ recipe.name }}
                          </div>
                          <div class="text-caption text-medium-emphasis">
                            {{ recipe.desc }}
                          </div>
                        </div>
                      </td>

                      <!-- Tỷ lệ khung hình -->
                      <td class="text-body-2 font-weight-medium text-high-emphasis">
                        {{ recipe.ratio }}
                      </td>

                      <!-- Trạng thái Active -->
                      <td>
                        <VSwitch
                          v-model="recipe.active"
                          color="primary"
                          density="compact"
                          hide-details
                        />
                      </td>

                      <!-- Nút Sửa / Xóa -->
                      <td class="text-end">
                        <div class="d-inline-flex align-center gap-1">
                          <VBtn
                            icon="tabler-edit"
                            variant="text"
                            size="small"
                            color="default"
                            aria-label="Sửa recipe"
                          />
                          <VBtn
                            icon="tabler-trash"
                            variant="text"
                            size="small"
                            color="error"
                            aria-label="Xóa recipe"
                          />
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VCardText>

      <!-- ================= 3. FOOTER DIALOG (CỐ ĐỊNH) ================= -->
      <template #footer>
        <VCardActions class="justify-end">
          <VBtn
            color="secondary"
            variant="tonal"
            :disabled="props.saving"
            @click="close"
          >
            Đóng
          </VBtn>
          <VBtn
            color="primary"
            :loading="props.saving"
            :disabled="props.saving"
            @click="submitMeta"
          >
            Lưu thay đổi
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>

<style scoped>
/* Nút phóng to góc phải trên ảnh chính */
.expand-btn {
  position: absolute;
  inset-block-start: 10px;
  inset-inline-end: 10px;
  z-index: 2;
  opacity: 0.9;
}

.gallery-main-image,
.gallery-thumb-image {
  background-color: rgba(var(--v-theme-on-surface), 0.04);
}

/* Lưới 5 ô thumbnail dưới ảnh chính */
.thumb-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 8px;
}

.thumb-item {
  border: 2px solid transparent;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.thumb-active {
  border-color: rgb(var(--v-theme-primary));
  box-shadow: 0 2px 8px rgba(var(--v-theme-primary), 0.24);
}

.add-thumb-box {
  block-size: 62px;
  border: 1.5px dashed rgba(var(--v-theme-primary), 0.35);
  background-color: rgba(var(--v-theme-primary), 0.04);
  transition: background-color 0.2s ease, border-color 0.2s ease;
}

.add-thumb-box:hover {
  background-color: rgba(var(--v-theme-primary), 0.08);
  border-color: rgb(var(--v-theme-primary));
}

/* Bảng màu chủ đạo (Swatches) */
.palette-swatch {
  inline-size: 28px;
  block-size: 28px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

/* Hộp Tóm tắt phân tích & JSON viewer */
.analysis-summary-box {
  background-color: rgba(var(--v-theme-primary), 0.06);
  border: 1px solid rgba(var(--v-theme-primary), 0.16);
}

.dna-json-viewer {
  background-color: rgba(var(--v-theme-on-surface), 0.04);
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  max-block-size: 360px;
  overflow: auto;
  white-space: pre-wrap;
}

/* Font monospace cho cột CODE */
.font-mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

.cursor-grab {
  cursor: grab;
}
</style>
