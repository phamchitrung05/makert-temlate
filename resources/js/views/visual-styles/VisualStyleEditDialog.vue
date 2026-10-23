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
  - syncFromStyle(): đồng bộ dữ liệu từ prop `style` sang mọi tab DNA
  (composition/camera/lighting/environment/color_mood/subject_rules/style_constraints).
  - loadStyleDetail(): gọi API lấy chi tiết style + shots khi mở dialog.
  - statusMeta: computed nhãn và màu trạng thái lifecycle của style.
  - dnaJsonPreview: computed chuỗi JSON định dạng đẹp khi bật chế độ xem JSON.
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
import { storeToRefs } from 'pinia'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import { useVisualStyleStore } from '@/stores/visualStyle'
import { useCategoryStore } from '@/stores/category'
import { apiErrorMessage, apiMessage } from '@/config/messages'
import { useSnackbar } from '@/composables/useSnackbar'
import { aiArticleTaxonomyService } from '@/services/aiArticleTaxonomy'
import { usePrivateImage } from '@/composables/usePrivateImage'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  styleId: { type: [Number, String], default: null },
})

const emit = defineEmits(['update:modelValue', 'saved', 'close', 'afterLeave'])

const store = useVisualStyleStore()
const categoryStore = useCategoryStore()
const { isDetailLoading, isMutating: saving, detailError } = storeToRefs(store)
const { snackbar, success: notifySuccess } = useSnackbar()

const DEFAULT_SUMMARY = 'Hình ảnh là cà phê với latte art trên bàn gỗ, hạt cà phê rải xung quanh, ánh sáng buổi sáng ấm áp, không gian quán cà phê, phong cách chụp ảnh sản phẩm chuyên nghiệp, tự nhiên và gần gũi. Toàn bộ ảnh phù hợp để tái tạo phong cách thương mại (bố cục cân đối, chủ thể ở giữa, nền có chiều sâu, ánh sáng mềm, bokeh nhẹ, màu nâu ấm).'

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

// Enum + nhãn Style DNA từ config backend qua dnaMeta(); options được build
// động khi dialog mở thay vì hardcode để enum/label sửa ở config là đồng bộ.
const dnaMeta = ref(null)

/**
 * =====================================================================
 * CHỨC NĂNG: Build option {title,value} cho một field enum từ dnaMeta.
 * INPUT: tên section và field enum. OUTPUT: [{title, value}].
 * SIDE EFFECT: chỉ đọc dnaMeta; trả key gốc làm title khi thiếu label.
 * =====================================================================
 */
function fieldOptions(section, field) {
  const enums = dnaMeta.value?.enums?.[section]?.[field] ?? []
  const labels = dnaMeta.value?.labels?.[section]?.[field] ?? {}

  return enums.map(value => ({ value, title: labels[value] ?? value }))
}

const OPT = computed(() => ({
  framing: fieldOptions('composition', 'framing'),
  subject_position: fieldOptions('composition', 'subject_position'),
  orientation: fieldOptions('composition', 'orientation'),
  visual_balance: fieldOptions('composition', 'visual_balance'),
  negative_space: fieldOptions('composition', 'negative_space'),
  shot_type: fieldOptions('camera', 'shot_type'),
  angle: fieldOptions('camera', 'angle'),
  lens_look: fieldOptions('camera', 'lens_look'),
  depth_of_field: fieldOptions('camera', 'depth_of_field'),
  focus: fieldOptions('camera', 'focus'),
  perspective: fieldOptions('camera', 'perspective'),
  lighting_type: fieldOptions('lighting', 'type'),
  lighting_direction: fieldOptions('lighting', 'direction'),
  lighting_quality: fieldOptions('lighting', 'quality'),
  lighting_intensity: fieldOptions('lighting', 'intensity'),
  lighting_temperature: fieldOptions('lighting', 'temperature'),
  shadows: fieldOptions('lighting', 'shadows'),
  highlights: fieldOptions('lighting', 'highlights'),
  environment_type: fieldOptions('environment', 'type'),
  background_style: fieldOptions('environment', 'background_style'),
  background_blur: fieldOptions('environment', 'background_blur'),
  saturation: fieldOptions('color_mood', 'saturation'),
  contrast: fieldOptions('color_mood', 'contrast'),
}))

const activeDnaTab = ref('composition')
const showJson = ref(false)
const localError = ref('')

// Danh mục dùng chung (bảng categories) + Tag dùng chung (bảng tags)
const categoryOptions = ref([])
const tagOptions = ref([])
const taxonomyLoading = ref(false)

// Ảnh tham chiếu của style (local private qua reference-image endpoint);
// DEFAULT_GALLERY chỉ dùng khi style chưa có reference_image_url.
const galleryImages = ref([])
const selectedThumb = ref('')

// objectURL của ảnh tham chiếu private (endpoint cần auth — fetch blob rồi objectURL)
const { url: selectedThumbUrl } = usePrivateImage(selectedThumb)

// Dữ liệu Thông tin style (Cột trái)
const styleInfo = reactive({
  name: 'Cà phê sáng – Năng lượng tích cực',
  categoryIds: [],
  tagIds: [],
  enableGeneration: true,
  description:
    'Phong cách ảnh sản phẩm đồ uống cà phê trong không gian quán, ánh sáng tự nhiên ấm áp, bokeh, tone nâu ấm, mang cảm giác gần gũi và thư giãn.',
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

// Danh sách Shot Recipes (lấy từ API; không dùng mock cứng).
const shotRecipes = ref([])

const statusMeta = computed(() => {
  const status = store.current?.status || 'analyzed'

  return STATUS_META[status] ?? { label: status, color: 'secondary' }
})

const isProcessing = computed(() => ['queued', 'analyzing'].includes(store.current?.status))

const dnaJsonPreview = computed(() => JSON.stringify({
  identity: {
    description: styleInfo.description || undefined,
  },
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
  subject_rules: {
    preserve_shape: dnaConfig.preserveShape,
    preserve_color: dnaConfig.preserveColor,
    preserve_logo: dnaConfig.preserveLogo,
    preserve_text: dnaConfig.preserveText,
    preserve_material: dnaConfig.preserveMaterial,
    allow_perspective_change: dnaConfig.allowPerspectiveChange,
    allow_occlusion: dnaConfig.allowOcclusion,
  },
  style_constraints: {
    photorealistic: dnaConfig.photorealistic,
    commercial_quality: dnaConfig.commercialQuality,
    avoid_3d_render: dnaConfig.avoid3dRender,
    avoid_illustration: dnaConfig.avoidIllustration,
    avoid_unrealistic_hands: dnaConfig.avoidUnrealisticHands,
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
function syncFromStyle(data, { includeShots = true } = {}) {
  if (!data) return

  styleInfo.name = data.name || 'Cà phê sáng – Năng lượng tích cực'
  styleInfo.categoryIds = Array.isArray(data.category_ids) ? [...data.category_ids] : []
  styleInfo.tagIds = Array.isArray(data.tag_ids) ? [...data.tag_ids] : []
  styleInfo.enableGeneration = Boolean(data.is_enabled)

  const dna = data.dna_json ?? {}
  if (dna.identity?.description)
    styleInfo.description = dna.identity.description

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

  if (dna.camera) {
    dnaConfig.shotType = dna.camera.shot_type ?? dnaConfig.shotType
    dnaConfig.angle = dna.camera.angle ?? dnaConfig.angle
    dnaConfig.lensLook = dna.camera.lens_look ?? dnaConfig.lensLook
    dnaConfig.depthOfField = dna.camera.depth_of_field ?? dnaConfig.depthOfField
    dnaConfig.focus = dna.camera.focus ?? dnaConfig.focus
    dnaConfig.perspective = dna.camera.perspective ?? dnaConfig.perspective
  }

  if (dna.lighting) {
    dnaConfig.lightingType = dna.lighting.type ?? dnaConfig.lightingType
    dnaConfig.lightingDirection = dna.lighting.direction ?? dnaConfig.lightingDirection
    dnaConfig.lightingQuality = dna.lighting.quality ?? dnaConfig.lightingQuality
    dnaConfig.lightingIntensity = dna.lighting.intensity ?? dnaConfig.lightingIntensity
    dnaConfig.lightingTemperature = dna.lighting.temperature ?? dnaConfig.lightingTemperature
    dnaConfig.shadows = dna.lighting.shadows ?? dnaConfig.shadows
    dnaConfig.highlights = dna.lighting.highlights ?? dnaConfig.highlights
  }

  if (dna.environment) {
    dnaConfig.environmentType = dna.environment.type ?? dnaConfig.environmentType
    dnaConfig.backgroundStyle = dna.environment.background_style ?? dnaConfig.backgroundStyle
    dnaConfig.backgroundBlur = dna.environment.background_blur ?? dnaConfig.backgroundBlur
    if (typeof dna.environment.background_detail === 'string' && dna.environment.background_detail)
      dnaConfig.backgroundDetail = dna.environment.background_detail
    if (typeof dna.environment.props === 'string' && dna.environment.props)
      dnaConfig.props = dna.environment.props
    if (typeof dna.environment.environment_adaptation === 'boolean')
      dnaConfig.environmentAdaptation = dna.environment.environment_adaptation
  }

  if (dna.color_mood) {
    dnaConfig.saturation = dna.color_mood.saturation ?? dnaConfig.saturation
    dnaConfig.contrast = dna.color_mood.contrast ?? dnaConfig.contrast
    if (Array.isArray(dna.color_mood.mood) && dna.color_mood.mood.length)
      dnaConfig.mood = dna.color_mood.mood.join(', ')
  }

  if (dna.subject_rules) {
    dnaConfig.preserveShape = dna.subject_rules.preserve_shape ?? dnaConfig.preserveShape
    dnaConfig.preserveColor = dna.subject_rules.preserve_color ?? dnaConfig.preserveColor
    dnaConfig.preserveLogo = dna.subject_rules.preserve_logo ?? dnaConfig.preserveLogo
    dnaConfig.preserveText = dna.subject_rules.preserve_text ?? dnaConfig.preserveText
    dnaConfig.preserveMaterial = dna.subject_rules.preserve_material ?? dnaConfig.preserveMaterial
    dnaConfig.allowPerspectiveChange = dna.subject_rules.allow_perspective_change ?? dnaConfig.allowPerspectiveChange
    dnaConfig.allowOcclusion = dna.subject_rules.allow_occlusion ?? dnaConfig.allowOcclusion
  }

  if (dna.style_constraints) {
    dnaConfig.photorealistic = dna.style_constraints.photorealistic ?? dnaConfig.photorealistic
    dnaConfig.commercialQuality = dna.style_constraints.commercial_quality ?? dnaConfig.commercialQuality
    dnaConfig.avoid3dRender = dna.style_constraints.avoid_3d_render ?? dnaConfig.avoid3dRender
    dnaConfig.avoidIllustration = dna.style_constraints.avoid_illustration ?? dnaConfig.avoidIllustration
    dnaConfig.avoidUnrealisticHands = dna.style_constraints.avoid_unrealistic_hands ?? dnaConfig.avoidUnrealisticHands
  }

  // Ảnh tham chiếu thật của style (có thể null khi chưa analyze).
  if (typeof data.reference_image_url === 'string' && data.reference_image_url)
    galleryImages.value = [data.reference_image_url]
  else
    galleryImages.value = []
  selectedThumb.value = galleryImages.value[0] ?? ''

  // Map shots khi được phép VÀ data thật sự có mảng shots. Giữ nguyên bảng
  // hiện có khi prop/data không mang shots (list chỉ trả shots_count) để
  // tránh nháy rỗng trong lúc chờ fetchStyle() trả về.
  if (includeShots && Array.isArray(data.shots)) {
    shotRecipes.value = data.shots.map(shot => ({
      id: shot.id,
      code: shot.code,
      name: shot.name,
      desc: shot.description || 'Góc chụp giữ nguyên Style DNA.',
      ratio: shot.suggested_aspect_ratio || '1:1',
      active: shot.is_active !== false,
    }))
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Tải chi tiết Visual Style + meta DNA + taxonomy khi dialog mở.
 * =====================================================================
 * INPUT: styleId prop. OUTPUT: syncFromStyle với data fresh từ store.current,
 *   loadDnaMeta (cache), fetchCategories.
 * SIDE EFFECT: store.fetchStyle + store.fetchShots; dialog tự quản request.
 * =====================================================================
 */
async function loadStyleDetail() {
  if (!props.styleId) return

  const detail = await store.fetchStyle(props.styleId)

  if (detail !== null && props.modelValue) syncFromStyle(detail)
}

async function loadTaxonomy() {
  if (categoryOptions.value.length && tagOptions.value.length) return
  taxonomyLoading.value = true
  if (!dnaMeta.value) {
    void store.loadDnaMeta().then(meta => {
      if (meta) dnaMeta.value = meta
    })
  }
  try {
    const [catsResult, tags] = await Promise.all([
      categoryStore.fetchCategories({ perPage: 100 }),
      aiArticleTaxonomyService.all('tags'),
    ])

    const catItems = catsResult === null ? [] : (catsResult.items ?? [])

    categoryOptions.value = catItems.map(item => ({ title: item.name, value: item.id }))
    tagOptions.value = (Array.isArray(tags) ? tags : tags?.items ?? []).map(item => ({ title: item.name, value: item.id }))
  }
  catch {
    /* giữ nguyên options rỗng; user thấy select trống */
  }
  finally {
    taxonomyLoading.value = false
  }
}

watch(
  () => [props.modelValue, props.styleId],
  ([visible, styleId]) => {
    if (!visible || !styleId) return
    localError.value = ''
    void loadTaxonomy()
    void loadStyleDetail()
  },
  { immediate: true },
)

/**
 * =====================================================================
 * CHỨC NĂNG: Thêm từ khóa mẫu vào danh sách keywords của style (tạm giữ).
 * INPUT: sự kiện click nút Thêm.
 * OUTPUT: không còn dùng — giữ cho tương lai khi nâng cấp gallery đa ảnh.
 * =====================================================================
 */
// Bỏ triggerAddImage() — style chỉ giữ 1 ảnh tham chiếu reference_path.
/**
 * =====================================================================
 * CHỨC NĂNG: Validate và phát sự kiện lưu thông tin style lên page.
 * INPUT: dữ liệu trên `styleInfo`.
 * OUTPUT: emit `save` với `{ id, name, category, is_enabled }`.
 * =====================================================================
 */
/**
 * =====================================================================
 * CHỨC NĂNG: Lưu metadata + is_active shots trực tiếp qua store.
 * =====================================================================
 * INPUT: styleInfo + shotRecipes draft. OUTPUT: emit 'saved' để page reload,
 *   localError khi validation/lỗi API; giữ dialog mở để sửa tiếp.
 * SIDE EFFECT: PUT style + PUT từng shot.is_active qua store.
 * =====================================================================
 */
async function submitMeta() {
  if (!styleInfo.name?.trim()) {
    localError.value = 'Tên style là bắt buộc.'

    return
  }
  localError.value = ''
  if (!props.styleId) {
    close()

    return
  }

  try {
    const result = await store.updateStyle(props.styleId, {
      name: styleInfo.name.trim(),
      category: null,
      category_ids: [...styleInfo.categoryIds],
      tag_ids: [...styleInfo.tagIds],
      is_enabled: Boolean(styleInfo.enableGeneration),
      shots: shotRecipes.value.map(shot => ({ id: shot.id, is_active: Boolean(shot.active) })),
    })

    notifySuccess(apiMessage(result, 'Đã lưu style.'))
    emit('saved', result)
  }
  catch (reason) {
    localError.value = apiErrorMessage(reason, 'Lưu style thất bại.')
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Đóng dialog theo chuẩn AppDialogLayout.
 * INPUT: sự kiện đóng từ nút X hoặc nút Đóng ở footer.
 * OUTPUT: emit `update:modelValue` = false và `close`.
 * =====================================================================
 */
function close() {
  if (saving.value) return
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
  shotRecipes.value = []
  emit('afterLeave')
}
</script>

<template>
  <VDialog
    :model-value="props.modelValue"
    max-width="1440"
    scrollable
    :persistent="saving"
    @update:model-value="!$event && close()"
    @after-leave="onAfterLeave"
  >
    <AppDialogLayout
      close-label="Đóng chi tiết Visual Style"
      :close-disabled="saving"
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
                :disabled="saving"
              >
                Nhân bản
              </VBtn>
              <VBtn
                variant="tonal"
                color="secondary"
                size="small"
                prepend-icon="tabler-edit"
                :disabled="saving"
                @click="submitMeta"
              >
                Chỉnh sửa
              </VBtn>
              <VBtn
                color="primary"
                size="small"
                prepend-icon="tabler-sparkles"
                :disabled="saving"
              >
                Tạo ảnh từ style
              </VBtn>
            </div>
          </div>
        </VCardItem>
      </template>

      <!-- ================= 2. BODY DIALOG (2 CỘT - CHỈ VÙNG NÀY CUỘN) ================= -->
      <VCardText class="pa-6 position-relative">
        <!-- Loading khi đang nạp chi tiết style -->
        <VOverlay
          :model-value="isDetailLoading"
          class="align-center justify-center"
          contained
          persistent
        >
          <VProgressCircular
            indeterminate
            color="primary"
            size="40"
          />
        </VOverlay>

        <VAlert
          v-if="localError || detailError"
          type="error"
          variant="tonal"
          class="mb-6"
          density="compact"
        >
          {{ localError || detailError }}
        </VAlert>

        <VRow>
          <!-- ---------------- CỘT TRÁI: GALLERY & THÔNG TIN STYLE ---------------- -->
          <VCol
            cols="12"
            lg="4"
          >
            <!-- Khung Ảnh tham chiếu -->
            <VCard
              variant="outlined"
              class="mb-6"
            >
              <VCardItem>
                <template #title>
                  Ảnh tham chiếu
                </template>
                <template #subtitle>
                  Ảnh mẫu dùng để phân tích Style DNA
                </template>
              </VCardItem>
              <VCardText class="pa-4">
                <!-- Ảnh chính lớn -->
                <div class="position-relative rounded overflow-hidden mb-3">
                  <VImg
                    v-if="selectedThumbUrl"
                    :src="selectedThumbUrl"
                    height="250"
                    cover
                    class="rounded gallery-main-image"
                  />
                  <div
                    v-else
                    class="d-flex flex-column align-center justify-center text-medium-emphasis gallery-main-image rounded"
                    style="height: 250px;"
                  >
                    <VIcon
                      size="40"
                      icon="tabler-photo-off"
                      class="mb-2"
                    />
                    <span class="text-caption">{{ selectedThumb ? 'Đang tải ảnh…' : 'Chưa có ảnh tham chiếu' }}</span>
                  </div>
                  <VBtn
                    icon="tabler-arrows-maximize"
                    size="x-small"
                    variant="flat"
                    color="surface"
                    class="expand-btn"
                    aria-label="Phóng to ảnh"
                    :disabled="!selectedThumbUrl"
                  />
                </div>

                <!-- Chỉ có 1 ảnh tham chiếu; hàng thumbnail thay bằng chú thích -->
                <div
                  v-if="galleryImages.length"
                  class="d-flex align-center gap-2"
                >
                  <VAvatar
                    rounded
                    size="38"
                    class="border"
                  >
                    <VImg
                      :src="selectedThumbUrl"
                      cover
                    />
                  </VAvatar>
                  <div class="text-caption text-medium-emphasis">
                    Ảnh nguồn của style này (Ảnh phân tích Style DNA).
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

                <!-- Nhóm phong cách (Category dùng chung) -->
                <AppSelect
                  v-model="styleInfo.categoryIds"
                  :items="categoryOptions"
                  :loading="taxonomyLoading"
                  label="Nhóm phong cách"
                  placeholder="Chọn danh mục"
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
                    :disabled="isProcessing"
                  />
                  <div class="text-caption text-medium-emphasis mt-1">
                    {{ isProcessing ? 'Đang phân tích — chờ xong mới bật được.' : 'Bật/tắt dùng style này cho generation.' }}
                  </div>
                </div>

                <!-- Mô tả ngắn -->
                <AppTextarea
                  v-model="styleInfo.description"
                  label="Mô tả ngắn"
                  rows="3"
                />

                <!-- Từ khóa (Tag dùng chung) -->
                <div>
                  <VLabel class="mb-2 text-body-2 text-high-emphasis d-block">
                    Từ khóa
                  </VLabel>
                  <AppSelect
                    v-model="styleInfo.tagIds"
                    :items="tagOptions"
                    :loading="taxonomyLoading"
                    placeholder="Chọn tag"
                    multiple
                  >
                    <template #chip="{ item, props: chipProps }">
                      <VChip
                        v-bind="chipProps"
                        size="small"
                        color="#7367F0"
                        variant="tonal"
                        class="font-weight-medium rounded-lg"
                        closable
                      >
                        {{ item.title }}
                      </VChip>
                    </template>
                  </AppSelect>
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
                    variant="tonal"
                    color="secondary"
                    size="small"
                    :prepend-icon="showJson ? 'tabler-forms' : 'tabler-code'"
                    @click="showJson = !showJson"
                  >
                    {{ showJson ? 'Form' : 'JSON' }}
                  </VBtn>
                </template>
              </VCardItem>

              <VCardText>
                <!-- Thanh Tabs DNA -->
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
                          :items="OPT.framing"
                        />
                      </VCol>

                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.subjectPosition"
                          label="Vị trí chủ thể"
                          :items="OPT.subject_position"
                        />
                      </VCol>

                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.orientation"
                          label="Hướng ảnh"
                          :items="OPT.orientation"
                        />
                      </VCol>

                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.balance"
                          label="Cân bằng"
                          :items="OPT.visual_balance"
                        />
                      </VCol>

                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.negativeSpace"
                          label="Khoảng trống"
                          :items="OPT.negative_space"
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
                          :items="OPT.shot_type"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.angle"
                          label="Góc máy"
                          :items="OPT.angle"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.lensLook"
                          label="Ống kính"
                          :items="OPT.lens_look"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.depthOfField"
                          label="Độ sâu trường ảnh"
                          :items="OPT.depth_of_field"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.focus"
                          label="Lấy nét"
                          :items="OPT.focus"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.perspective"
                          label="Phối cảnh"
                          :items="OPT.perspective"
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
                          :items="OPT.lighting_type"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.lightingDirection"
                          label="Hướng sáng"
                          :items="OPT.lighting_direction"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.lightingQuality"
                          label="Chất lượng sáng"
                          :items="OPT.lighting_quality"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.lightingIntensity"
                          label="Cường độ"
                          :items="OPT.lighting_intensity"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.lightingTemperature"
                          label="Nhiệt độ màu"
                          :items="OPT.lighting_temperature"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.shadows"
                          label="Bóng đổ"
                          :items="OPT.shadows"
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
                          :items="OPT.environment_type"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.backgroundStyle"
                          label="Kiểu nền"
                          :items="OPT.background_style"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.backgroundBlur"
                          label="Làm mờ nền"
                          :items="OPT.background_blur"
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
                          :items="OPT.saturation"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        sm="6"
                      >
                        <AppSelect
                          v-model="dnaConfig.contrast"
                          label="Độ tương phản"
                          :items="OPT.contrast"
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
                <!-- Đang tải chi tiết style (shots) — spinner thay cho nháy bảng rỗng -->
                <div
                  v-if="isDetailLoading && !shotRecipes.length"
                  class="d-flex flex-column align-center justify-center py-10 text-medium-emphasis"
                >
                  <VProgressCircular
                    indeterminate
                    size="32"
                    width="3"
                    class="mb-3"
                  />
                  <span class="text-body-2">Đang tải shot recipes…</span>
                </div>

                <!-- Bảng danh sách Shot Recipes -->
                <VTable
                  v-else
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

                      <!-- Code recipe -->
                      <td>
                        <code class="text-body-2 font-mono text-high-emphasis">
                          {{ recipe.code }}
                        </code>
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
                      <td>
                        <VChip
                          size="small"
                          variant="tonal"
                          color="secondary"
                        >
                          {{ recipe.ratio }}
                        </VChip>
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
            :disabled="saving"
            @click="close"
          >
            Đóng
          </VBtn>
          <VBtn
            color="primary"
            :loading="saving"
            :disabled="saving"
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
