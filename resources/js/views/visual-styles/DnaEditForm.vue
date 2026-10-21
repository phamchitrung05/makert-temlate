<!--
  =====================================================================
  CHỨC NĂNG FILE: Form chỉnh sửa trực quan Style DNA theo nhóm.
  =====================================================================
  Render DNA thành các control phù hợp (select enum, slider số, switch
  boolean, chip list) thay vì bắt sửa JSON thô. Nhóm có tab:
  composition / camera / lighting / environment / color_mood /
  subject_rules / style_constraints. Chế độ "JSON nâng cao" chỉ read-only.
  Emit `update:dna` để page giữ object DNA reactive và `save` khi bấm Lưu.
  Dùng trong `pages/visual-styles/edit/index.vue`.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - setField()/setBool(): gán giá trị vào đúng section.field và emit dna mới.
  - toggleChip(): thêm/bỏ phần tử list (keywords/depth_layers/palette/mood).
  - fieldValue(): đọc giá trị section.field an toàn.
  - dnaJson: computed JSON đẹp để viewer read-only.
  - ENUMS/SECTION_TABS: cấu hình enum + field từng section (đồng bộ config backend).
  - props/emits: `dna`, `saving` vào; 'update:dna', 'save' ra.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : prop `dna` object (đã qua validate backend) + `saving`.
  - OUTPUT: emit 'update:dna' với object mới; emit 'save' khi submit.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- DNA field names follow the backend enum contract. */
import { computed, ref } from 'vue'

const props = defineProps({
  dna: { type: Object, default: () => ({}) },
  saving: { type: Boolean, default: false },
})

const emit = defineEmits(['update:dna', 'save'])

// Enum chuẩn hóa đồng bộ config/ai/visual_style_dna.php — giá trị ngoài enum
// bị backend reject nên select giới hạn đúng danh sách này
const ENUMS = {
  composition: {
    framing: ['extreme_close_up', 'close_up', 'medium_close_up', 'medium', 'medium_wide', 'wide', 'extreme_wide'],
    subject_position: ['center', 'rule_of_thirds_left', 'rule_of_thirds_right', 'top_third', 'bottom_third', 'off_center'],
    orientation: ['landscape', 'portrait', 'square'],
    visual_balance: ['symmetric', 'asymmetric', 'dynamic', 'static'],
    negative_space: ['none', 'minimal', 'moderate', 'dominant'],
  },
  camera: {
    shot_type: ['macro', 'detail', 'product_single', 'product_group', 'lifestyle', 'flat_lay', 'hero'],
    angle: ['eye_level', 'high_angle', 'low_angle', 'birds_eye', 'worms_eye', 'dutch', 'three_quarter'],
    lens_look: ['wide', 'standard', 'telephoto', 'macro'],
    depth_of_field: ['shallow', 'moderate', 'deep'],
    focus: ['sharp_all', 'selective', 'soft'],
    perspective: ['frontal', 'three_quarter', 'profile', 'top_down', 'isometric'],
  },
  lighting: {
    type: ['studio_softbox', 'natural_window', 'golden_hour', 'dramatic', 'backlit', 'rim', 'flat', 'mixed'],
    direction: ['front', 'front_side', 'side', 'back', 'top', 'bottom', 'surround'],
    quality: ['soft', 'hard', 'diffused', 'specular'],
    intensity: ['low', 'medium', 'high'],
    temperature: ['warm', 'neutral', 'cool'],
    shadows: ['none', 'soft', 'defined', 'hard'],
    highlights: ['soft', 'bright', 'blown', 'controlled'],
  },
  environment: {
    type: ['studio_solid', 'studio_gradient', 'lifestyle_interior', 'lifestyle_exterior', 'abstract', 'surface_texture'],
    background_style: ['solid_color', 'gradient', 'textured', 'scene', 'bokeh', 'prop_styled'],
    background_blur: ['none', 'slight', 'moderate', 'strong'],
  },
  color_mood: {
    saturation: ['desaturated', 'muted', 'natural', 'vivid', 'high'],
    contrast: ['low', 'moderate', 'high'],
  },
}

// Nhãn hiển thị tiếng Việt cho enum backend (giữ value nguyên gốc)
const FIELD_LABELS = {
  framing: 'Khung hình', subject_position: 'Vị trí chủ thể', orientation: 'Hướng ảnh',
  visual_balance: 'Cân bằng', negative_space: 'Khoảng trống',
  shot_type: 'Kiểu shot', angle: 'Góc máy', lens_look: 'Ống kính', depth_of_field: 'Độ sâu trường',
  focus: 'Lấy nét', perspective: 'Phối cảnh',
  type: 'Kiểu', direction: 'Hướng sáng', quality: 'Chất lượng sáng', intensity: 'Cường độ',
  temperature: 'Nhiệt độ màu', shadows: 'Bóng', highlights: 'Vùng sáng',
  background_style: 'Kiểu nền', background_blur: 'Làm mờ nền',
  saturation: 'Độ bão hòa', contrast: 'Độ tương phản',
  preserve_shape: 'Giữ hình dáng', preserve_color: 'Giữ màu sắc', preserve_logo: 'Giữ logo',
  preserve_text: 'Giữ chữ', preserve_material: 'Giữ chất liệu',
  allow_perspective_change: 'Cho đổi phối cảnh', allow_occlusion: 'Cho bị che khuất',
  photorealistic: 'Ảnh thật', commercial_quality: 'Chất lượng thương mại',
  avoid_3d_render: 'Tránh 3D render', avoid_illustration: 'Tránh minh họa', avoid_unrealistic_hands: 'Tránh tay phi thực tế',
}

const SECTION_TABS = [
  { key: 'composition', title: 'Bố cục', icon: 'tabler-layout' },
  { key: 'camera', title: 'Máy ảnh', icon: 'tabler-camera' },
  { key: 'lighting', title: 'Ánh sáng', icon: 'tabler-bulb' },
  { key: 'environment', title: 'Bối cảnh', icon: 'tabler-photo' },
  { key: 'color_mood', title: 'Màu & Tâm trạng', icon: 'tabler-palette' },
  { key: 'subject_rules', title: 'Bảo toàn', icon: 'tabler-shield-check' },
  { key: 'style_constraints', title: 'Ràng buộc', icon: 'tabler-lock' },
]

const activeTab = ref('composition')
const showJson = ref(false)

// Đọc giá trị section.field an toàn (DNA có thể thiếu section)
function fieldValue(section, field) {
  return props.dna?.[section]?.[field]
}

// Gán enum/text vào section.field và emit object DNA mới (immutable)
function setField(section, field, value) {
  const next = {
    ...props.dna,
    [section]: { ...(props.dna?.[section] ?? {}), [field]: value },
  }

  emit('update:dna', next)
}

// Gán boolean cho subject_rules/style_constraints/environment_adaptation
function setBool(section, field, value) {
  setField(section, field, Boolean(value))
}

// Thêm/bỏ phần tử trong list (depth_layers/palette/mood)
function toggleChip(section, field, value) {
  const current = Array.isArray(props.dna?.[section]?.[field]) ? [...props.dna[section][field]] : []
  const index = current.indexOf(value)

  if (index === -1)
    current.push(value)
  else
    current.splice(index, 1)

  setField(section, field, current)
}

// JSON đẹp cho chế độ xem nâng cao (read-only)
const dnaJson = computed(() => JSON.stringify(props.dna ?? {}, null, 2))

const textInput = ref('')
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-4">
      <VTabs
        v-model="activeTab"
        density="comfortable"
        class="flex-grow-1"
      >
        <VTab
          v-for="tab in SECTION_TABS"
          :key="tab.key"
          :value="tab.key"
        >
          <VIcon
            :icon="tab.icon"
            size="18"
            class="me-1"
          />
          {{ tab.title }}
        </VTab>
      </VTabs>
      <VBtn
        size="small"
        variant="tonal"
        color="secondary"
        :prepend-icon="showJson ? 'tabler-forms' : 'tabler-code'"
        @click="showJson = !showJson"
      >
        {{ showJson ? 'Form' : 'JSON' }}
      </VBtn>
    </div>

    <!-- Chế độ JSON nâng cao — chỉ đọc -->
    <div
      v-if="showJson"
      class="dna-json-viewer pa-4 rounded-lg"
    >
      <pre
        class="text-body-2 mb-0"
        style="white-space: pre-wrap;"
      >{{ dnaJson }}</pre>
    </div>

    <VWindow
      v-else
      v-model="activeTab"
    >
      <!-- ============ COMPOSITION ============ -->
      <VWindowItem value="composition">
        <VRow>
          <VCol
            v-for="field in Object.keys(ENUMS.composition)"
            :key="field"
            cols="12"
            sm="6"
          >
            <AppSelect
              :model-value="fieldValue('composition', field)"
              :items="ENUMS.composition[field]"
              :label="FIELD_LABELS[field] || field"
              clearable
              @update:model-value="setField('composition', field, $event)"
            />
          </VCol>
          <VCol
            cols="12"
            sm="6"
          >
            <div class="text-body-2 mb-1">
              Kích thước chủ thể (0–1)
            </div>
            <VSlider
              :model-value="fieldValue('composition', 'subject_scale') ?? 0.5"
              :min="0"
              :max="1"
              :step="0.05"
              thumb-label
              @update:model-value="setField('composition', 'subject_scale', $event)"
            />
          </VCol>
          <VCol cols="12">
            <AppTextField
              :model-value="(fieldValue('composition', 'depth_layers') ?? []).join(', ')"
              label="Lớp chiều sâu (phân cách bởi dấu phẩy)"
              placeholder="foreground, midground, background"
              @update:model-value="setField('composition', 'depth_layers', $event.split(',').map(v => v.trim()).filter(Boolean))"
            />
          </VCol>
        </VRow>
      </VWindowItem>

      <!-- ============ CAMERA ============ -->
      <VWindowItem value="camera">
        <VRow>
          <VCol
            v-for="field in Object.keys(ENUMS.camera)"
            :key="field"
            cols="12"
            sm="6"
          >
            <AppSelect
              :model-value="fieldValue('camera', field)"
              :items="ENUMS.camera[field]"
              :label="FIELD_LABELS[field] || field"
              clearable
              @update:model-value="setField('camera', field, $event)"
            />
          </VCol>
        </VRow>
      </VWindowItem>

      <!-- ============ LIGHTING ============ -->
      <VWindowItem value="lighting">
        <VRow>
          <VCol
            v-for="field in Object.keys(ENUMS.lighting)"
            :key="field"
            cols="12"
            sm="6"
          >
            <AppSelect
              :model-value="fieldValue('lighting', field)"
              :items="ENUMS.lighting[field]"
              :label="FIELD_LABELS[field] || field"
              clearable
              @update:model-value="setField('lighting', field, $event)"
            />
          </VCol>
        </VRow>
      </VWindowItem>

      <!-- ============ ENVIRONMENT ============ -->
      <VWindowItem value="environment">
        <VRow>
          <VCol
            v-for="field in Object.keys(ENUMS.environment)"
            :key="field"
            cols="12"
            sm="6"
          >
            <AppSelect
              :model-value="fieldValue('environment', field)"
              :items="ENUMS.environment[field]"
              :label="FIELD_LABELS[field] || field"
              clearable
              @update:model-value="setField('environment', field, $event)"
            />
          </VCol>
          <VCol
            cols="12"
            sm="6"
          >
            <AppTextField
              :model-value="fieldValue('environment', 'background_detail')"
              label="Chi tiết nền"
              placeholder="Mô tả chi tiết nền..."
              @update:model-value="setField('environment', 'background_detail', $event)"
            />
          </VCol>
          <VCol
            cols="12"
            sm="6"
          >
            <AppTextField
              :model-value="fieldValue('environment', 'props')"
              label="Đạo cụ"
              placeholder="Đạo cụ đi kèm..."
              @update:model-value="setField('environment', 'props', $event)"
            />
          </VCol>
          <VCol cols="12">
            <VSwitch
              :model-value="fieldValue('environment', 'environment_adaptation') !== false"
              label="Tự thích nghi bối cảnh"
              color="primary"
              @update:model-value="setBool('environment', 'environment_adaptation', $event)"
            />
          </VCol>
        </VRow>
      </VWindowItem>

      <!-- ============ COLOR & MOOD ============ -->
      <VWindowItem value="color_mood">
        <VRow>
          <VCol
            v-for="field in Object.keys(ENUMS.color_mood)"
            :key="field"
            cols="12"
            sm="6"
          >
            <AppSelect
              :model-value="fieldValue('color_mood', field)"
              :items="ENUMS.color_mood[field]"
              :label="FIELD_LABELS[field] || field"
              clearable
              @update:model-value="setField('color_mood', field, $event)"
            />
          </VCol>
          <VCol
            cols="12"
            sm="6"
          >
            <AppTextField
              :model-value="(fieldValue('color_mood', 'palette') ?? []).join(', ')"
              label="Bảng màu (phân cách bởi dấu phẩy)"
              placeholder="#fff, warm beige"
              @update:model-value="setField('color_mood', 'palette', $event.split(',').map(v => v.trim()).filter(Boolean))"
            />
          </VCol>
          <VCol
            cols="12"
            sm="6"
          >
            <AppTextField
              :model-value="(fieldValue('color_mood', 'mood') ?? []).join(', ')"
              label="Tâm trạng (phân cách bởi dấu phẩy)"
              placeholder="cozy, premium, fresh"
              @update:model-value="setField('color_mood', 'mood', $event.split(',').map(v => v.trim()).filter(Boolean))"
            />
          </VCol>
        </VRow>
      </VWindowItem>

      <!-- ============ SUBJECT RULES ============ -->
      <VWindowItem value="subject_rules">
        <VRow>
          <VCol
            v-for="field in ['preserve_shape', 'preserve_color', 'preserve_logo', 'preserve_text', 'preserve_material', 'allow_perspective_change', 'allow_occlusion']"
            :key="field"
            cols="12"
            sm="6"
          >
            <VSwitch
              :model-value="fieldValue('subject_rules', field) !== false"
              :label="FIELD_LABELS[field] || field"
              color="primary"
              @update:model-value="setBool('subject_rules', field, $event)"
            />
          </VCol>
        </VRow>
      </VWindowItem>

      <!-- ============ STYLE CONSTRAINTS ============ -->
      <VWindowItem value="style_constraints">
        <VRow>
          <VCol
            v-for="field in ['photorealistic', 'commercial_quality', 'avoid_3d_render', 'avoid_illustration', 'avoid_unrealistic_hands']"
            :key="field"
            cols="12"
            sm="6"
          >
            <VSwitch
              :model-value="fieldValue('style_constraints', field) !== false"
              :label="FIELD_LABELS[field] || field"
              color="primary"
              @update:model-value="setBool('style_constraints', field, $event)"
            />
          </VCol>
        </VRow>
      </VWindowItem>
    </VWindow>
  </div>
</template>

<style scoped>
.dna-json-viewer {
  background-color: rgba(var(--v-theme-on-surface), 0.04);
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  max-block-size: 420px;
  overflow: auto;
}
</style>
