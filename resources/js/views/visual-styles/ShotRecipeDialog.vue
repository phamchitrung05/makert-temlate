<!--
  =====================================================================
  CHỨC NĂNG FILE: Dialog thêm/sửa một Shot Recipe của Visual Style.
  =====================================================================
  Dùng AppDialogLayout (header/footer cố định, chỉ body scroll). Form gồm
  code/name/description, composition_override + camera_override (select enum
  tùy chọn — để trống = giữ DNA), prompt_suffix và suggested_aspect_ratio.
  Emit 'save' với payload; page lo create/update và validation phía backend.
  Giữ state form đến `after-leave` rồi mới reset.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - open(): nạp dữ liệu shot khi sửa hoặc blank khi thêm mới.
  - close(): phát 'close' và để after-leave dọn state.
  - save(): validate client tối thiểu rồi emit 'save' payload.
  - buildOverride(): gom các field có giá trị thành object override/null.
  - watcher props.modelValue: đồng bộ mở/đóng và khởi tạo form.
  - props/emits: modelValue/shot/saving vào; 'update:modelValue','save','close','after-leave' ra.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : prop modelValue (hiển thị), shot (khi sửa, null khi thêm), saving.
  - OUTPUT: emit 'save' `{id?, code,name,description,composition_override,camera_override,prompt_suffix,suggested_aspect_ratio,sort_order,is_active}`.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Shot field names follow the backend enum contract. */
import { ref, watch } from 'vue'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  shot: { type: Object, default: null },
  saving: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'save', 'close', 'afterLeave'])

// Enum override — cùng nguồn config backend; để trống = giữ giá trị DNA gốc
const COMPOSITION_ENUMS = {
  framing: ['extreme_close_up', 'close_up', 'medium_close_up', 'medium', 'medium_wide', 'wide', 'extreme_wide'],
  subject_position: ['center', 'rule_of_thirds_left', 'rule_of_thirds_right', 'top_third', 'bottom_third', 'off_center'],
  orientation: ['landscape', 'portrait', 'square'],
  visual_balance: ['symmetric', 'asymmetric', 'dynamic', 'static'],
  negative_space: ['none', 'minimal', 'moderate', 'dominant'],
}

const CAMERA_ENUMS = {
  shot_type: ['macro', 'detail', 'product_single', 'product_group', 'lifestyle', 'flat_lay', 'hero'],
  angle: ['eye_level', 'high_angle', 'low_angle', 'birds_eye', 'worms_eye', 'dutch', 'three_quarter'],
  lens_look: ['wide', 'standard', 'telephoto', 'macro'],
  depth_of_field: ['shallow', 'moderate', 'deep'],
  focus: ['sharp_all', 'selective', 'soft'],
  perspective: ['frontal', 'three_quarter', 'profile', 'top_down', 'isometric'],
}

const form = ref({})
const formError = ref('')

// Gom các field override có giá trị; trả null khi không có field nào
function buildOverride(values) {
  const filtered = Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '' && v !== null && v !== undefined))

  return Object.keys(filtered).length ? filtered : null
}

// Khởi tạo/đổ form khi mở dialog: nạp shot khi sửa, blank khi thêm
watch(() => props.modelValue, visible => {
  if (!visible) return
  const shot = props.shot

  formError.value = ''
  form.value = shot ? {
    id: shot.id,
    code: shot.code ?? '',
    name: shot.name ?? '',
    description: shot.description ?? '',
    prompt_suffix: shot.prompt_suffix ?? '',
    suggested_aspect_ratio: shot.suggested_aspect_ratio ?? '',
    sort_order: shot.sort_order ?? 0,
    is_active: shot.is_active !== false,
    composition: { ...(shot.composition_override ?? {}) },
    camera: { ...(shot.camera_override ?? {}) },
  } : {
    id: null,
    code: '',
    name: '',
    description: '',
    prompt_suffix: '',
    suggested_aspect_ratio: '',
    sort_order: 0,
    is_active: true,
    composition: {},
    camera: {},
  }
})

// Đóng dialog: emit 'close' để parent ẩn; state dọn sau after-leave
function close() {
  emit('update:modelValue', false)
  emit('close')
}

// Validate tối thiểu rồi emit payload cho parent (backend validate tiếp)
function save() {
  if (!form.value.code?.trim()) {
    formError.value = 'Mã code là bắt buộc.'

    return
  }
  if (!form.value.name?.trim()) {
    formError.value = 'Tên recipe là bắt buộc.'

    return
  }
  formError.value = ''
  emit('save', {
    ...(form.value.id ? { id: form.value.id } : {}),
    code: form.value.code.trim(),
    name: form.value.name.trim(),
    description: form.value.description?.trim() || null,
    composition_override: buildOverride(form.value.composition),
    camera_override: buildOverride(form.value.camera),
    prompt_suffix: form.value.prompt_suffix?.trim() || null,
    suggested_aspect_ratio: form.value.suggested_aspect_ratio?.trim() || null,
    sort_order: form.value.sort_order ?? 0,
    is_active: form.value.is_active !== false,
  })
}
</script>

<template>
  <VDialog
    :model-value="props.modelValue"
    max-width="720"
    scrollable
    :persistent="props.saving"
    @update:model-value="!$event && close()"
    @after-leave="emit('afterLeave')"
  >
    <AppDialogLayout
      :title="form.id ? 'Sửa shot recipe' : 'Thêm shot recipe'"
      :subtitle="form.id ? `Mã: ${form.code}` : 'Góc/bố cục riêng giữ nguyên Style DNA'"
      :close-disabled="props.saving"
      @close="close"
    >
      <VCardText>
        <VAlert
          v-if="formError"
          type="error"
          variant="tonal"
          class="mb-4"
          density="compact"
        >
          {{ formError }}
        </VAlert>

        <VRow>
          <VCol
            cols="12"
            sm="6"
          >
            <AppTextField
              v-model="form.code"
              label="Mã code *"
              placeholder="hero_front"
              hint="snake_case, duy nhất trong style"
              persistent-hint
            />
          </VCol>
          <VCol
            cols="12"
            sm="6"
          >
            <AppTextField
              v-model="form.name"
              label="Tên recipe *"
              placeholder="Hero mặt trước"
            />
          </VCol>
          <VCol cols="12">
            <AppTextField
              v-model="form.description"
              label="Mô tả"
              placeholder="Mô tả ngắn góc chụp..."
            />
          </VCol>
          <VCol
            cols="12"
            sm="6"
          >
            <AppSelect
              v-model="form.suggested_aspect_ratio"
              :items="['1:1', '4:5', '3:4', '16:9', '9:16', '4:3', '3:2']"
              label="Tỷ lệ gợi ý"
              clearable
            />
          </VCol>
          <VCol
            cols="12"
            sm="6"
          >
            <VSwitch
              v-model="form.is_active"
              label="Kích hoạt recipe"
              color="primary"
              hide-details
              class="mt-2"
            />
          </VCol>

          <!-- Composition override -->
          <VCol cols="12">
            <div class="text-subtitle-1 font-weight-medium mt-2 mb-1">
              Composition override
            </div>
            <div class="text-body-2 text-medium-emphasis mb-2">
              Để trống field = giữ giá trị của Style DNA.
            </div>
            <VRow>
              <VCol
                v-for="(items, field) in COMPOSITION_ENUMS"
                :key="field"
                cols="12"
                sm="6"
              >
                <AppSelect
                  v-model="form.composition[field]"
                  :items="items"
                  :label="field"
                  clearable
                />
              </VCol>
            </VRow>
          </VCol>

          <!-- Camera override -->
          <VCol cols="12">
            <div class="text-subtitle-1 font-weight-medium mt-2 mb-1">
              Camera override
            </div>
            <VRow>
              <VCol
                v-for="(items, field) in CAMERA_ENUMS"
                :key="field"
                cols="12"
                sm="6"
              >
                <AppSelect
                  v-model="form.camera[field]"
                  :items="items"
                  :label="field"
                  clearable
                />
              </VCol>
            </VRow>
          </VCol>

          <VCol cols="12">
            <AppTextarea
              v-model="form.prompt_suffix"
              label="Prompt suffix"
              placeholder="Phần prompt thêm riêng cho recipe này..."
              rows="3"
              auto-grow
            />
          </VCol>
        </VRow>
      </VCardText>

      <template #footer>
        <VCardActions class="justify-end">
          <VBtn
            color="secondary"
            variant="tonal"
            :disabled="props.saving"
            @click="close"
          >
            Hủy
          </VBtn>
          <VBtn
            color="primary"
            :loading="props.saving"
            :disabled="props.saving"
            @click="save"
          >
            {{ form.id ? 'Lưu recipe' : 'Tạo recipe' }}
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>
