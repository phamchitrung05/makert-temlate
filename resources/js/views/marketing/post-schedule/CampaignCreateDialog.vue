<!--
  =====================================================================
  CHỨC NĂNG FILE: Dialog tạo chiến dịch mới trong tab "Chiến Dịch"
  (post-schedule). Wizard 5 bước: Thông tin chung, Nội dung & Văn phong,
  Phong cách hình ảnh, Nền tảng & Lịch đăng, Nâng cao.
  =====================================================================

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - currentStep: bước đang active trong wizard.
  - form: state form tổng hợp tất cả bước.
  - nextStep()/prevStep(): điều hướng wizard.

  INPUT/OUTPUT CỦA COMPONENT:
  - INPUT : modelValue (Boolean) - trạng thái mở/đóng dialog.
  - OUTPUT: emit update:modelValue để đóng dialog; chưa emit submit (chưa có API).
  =====================================================================
-->
<script setup>
import { ref, reactive } from 'vue'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

function close() {
  emit('update:modelValue', false)
}

// ─── Wizard state ────────────────────────────────────────────────────────────

const currentStep = ref(1)

const steps = [
  { id: 1, title: 'Thông tin chung', icon: 'tabler-info-circle' },
  { id: 2, title: 'Nội dung & Văn phong', icon: 'tabler-feather' },
  { id: 3, title: 'Phong cách hình ảnh', icon: 'tabler-palette' },
  { id: 4, title: 'Nền tảng & Lịch đăng', icon: 'tabler-share' },
  { id: 5, title: 'Nâng cao', icon: 'tabler-adjustments' },
]

// ─── Step 1: Thông tin chung ──────────────────────────────────────────────────
const form = reactive({
  name: 'BST Đồ uống mùa thu',
  isActive: true,
  objective: 'product',
  description: 'Giới thiệu bộ sưu tập đồ uống mùa thu, nhấn mạnh hương vị mới, không gian ấm cúng và ưu đãi trong tháng.',
  dateRange: '01/10/2026 – 31/10/2026',
  budget: '5,000,000',
  tags: ['Sản phẩm mới', 'Mùa thu', 'Đồ uống'],
})

const objectives = [
  { id: 'product', label: 'Giới thiệu sản phẩm', icon: 'tabler-flag' },
  { id: 'engagement', label: 'Tăng tương tác', icon: 'tabler-heart' },
  { id: 'branding', label: 'Nhận diện thương hiệu', icon: 'tabler-shield' },
  { id: 'sales', label: 'Khuyến mãi & Ưu đãi', icon: 'tabler-tag' },
  { id: 'event', label: 'Sự kiện & Thông báo', icon: 'tabler-calendar-star' },
  { id: 'other', label: 'Khác', icon: 'tabler-dots' },
]

// ─── Navigation ───────────────────────────────────────────────────────────────
function nextStep() {
  if (currentStep.value < 5) currentStep.value++
}

function prevStep() {
  if (currentStep.value > 1) currentStep.value--
}
</script>

<template>
  <VDialog
    :model-value="props.modelValue"
    max-width="1400"
    persistent
    scrollable
    @update:model-value="emit('update:modelValue', $event)"
  >
    <AppDialogLayout
      title="Tạo Chiến Dịch Mới"
      subtitle="Thiết lập chiến dịch marketing cho lịch đăng bài"
      @close="close"
    >
      <!-- ================= BODY: 2 CỘT WIZARD ================= -->
      <div class="d-flex h-100">
        <!-- CỘT TRÁI: CÁC BƯỚC -->
        <div class="step-sidebar pa-4 border-e flex-shrink-0">
          <div class="d-flex flex-column gap-2">
            <div
              v-for="step in steps"
              :key="step.id"
              class="step-item d-flex align-center pa-3 rounded-lg cursor-pointer"
              :class="{ 'step-active': currentStep === step.id }"
              @click="currentStep = step.id"
            >
              <VIcon
                :icon="step.icon"
                size="20"
                :color="currentStep === step.id ? 'primary' : 'secondary'"
                class="me-3"
              />
              <span
                class="text-body-1 font-weight-medium"
                :class="currentStep === step.id ? 'text-primary font-weight-bold' : 'text-medium-emphasis'"
              >
                {{ step.title }}
              </span>
            </div>
          </div>
        </div>

        <!-- CỘT PHẢI: NỘI DUNG TỪNG BƯỚC -->
        <div class="step-content pa-6 flex-grow-1 overflow-y-auto">
          <!-- BƯỚC 1: THÔNG TIN CHUNG -->
          <div v-if="currentStep === 1">
            <div class="text-subtitle-1 font-weight-medium text-high-emphasis mb-1">
              Thông tin chung
            </div>
            <div class="text-body-2 text-medium-emphasis mb-4">
              Thiết lập thông tin cơ bản cho chiến dịch đăng bài.
            </div>

            <!-- Tên + Trạng thái -->
            <VRow
              dense
              class="mb-4"
            >
              <VCol
                cols="12"
                sm="8"
              >
                <AppTextField
                  v-model="form.name"
                  label="Tên chiến dịch"
                  placeholder="Nhập tên chiến dịch"
                  required
                />
              </VCol>
              <VCol
                cols="12"
                sm="4"
                class="d-flex flex-column justify-center"
              >
                <div class="d-flex align-center">
                  <VSwitch
                    v-model="form.isActive"
                    color="primary"
                    density="compact"
                    hide-details
                    inset
                    class="me-2"
                  />
                  <span class="text-body-1 font-weight-medium text-high-emphasis">Kích hoạt</span>
                </div>
              </VCol>
            </VRow>

            <!-- Mục tiêu -->
            <div class="mb-4">
              <div class="text-body-1 font-weight-medium text-high-emphasis mb-2">
                Mục tiêu <span class="text-error">*</span>
              </div>
              <div class="objective-grid">
                <div
                  v-for="obj in objectives"
                  :key="obj.id"
                  class="objective-card pa-3 rounded-lg border text-center cursor-pointer d-flex flex-column align-center justify-center position-relative"
                  :class="{ 'objective-active': form.objective === obj.id }"
                  @click="form.objective = obj.id"
                >
                  <VIcon
                    v-if="form.objective === obj.id"
                    icon="tabler-check"
                    size="14"
                    color="white"
                    class="objective-check-badge"
                  />
                  <VIcon
                    :icon="obj.icon"
                    size="22"
                    :color="form.objective === obj.id ? 'primary' : 'secondary'"
                    class="mb-2"
                  />
                  <div
                    class="text-body-1 font-weight-medium"
                    :class="form.objective === obj.id ? 'text-primary font-weight-bold' : 'text-medium-emphasis'"
                    style="line-height: 1.25;"
                  >
                    {{ obj.label }}
                  </div>
                </div>
              </div>
            </div>

            <!-- Mô tả -->
            <div class="mb-4">
              <AppTextarea
                v-model="form.description"
                label="Mô tả chiến dịch"
                placeholder="Mô tả ngắn về chiến dịch"
                rows="3"
                maxlength="300"
                counter
                no-resize
              />
            </div>

            <!-- Thời gian + Ngân sách -->
            <VRow
              dense
              class="mb-4"
            >
              <VCol
                cols="12"
                sm="7"
              >
                <AppTextField
                  v-model="form.dateRange"
                  label="Thời gian chạy"
                  placeholder="DD/MM/YYYY – DD/MM/YYYY"
                  prepend-inner-icon="tabler-calendar"
                  required
                />
              </VCol>
              <VCol
                cols="12"
                sm="5"
              >
                <AppTextField
                  v-model="form.budget"
                  label="Ngân sách (tùy chọn)"
                  placeholder="0"
                  prepend-inner-icon="tabler-currency-dong"
                />
              </VCol>
            </VRow>

            <!-- Gắn nhãn -->
            <div>
              <AppCombobox
                v-model="form.tags"
                label="Gắn nhãn"
                :items="['Sản phẩm mới', 'Mùa thu', 'Đồ uống', 'Khuyến mãi', 'Hot trend']"
                multiple
                chips
                closable-chips
              />
            </div>
          </div>

          <!-- BƯỚC 2–5: PLACEHOLDER -->
          <div
            v-else
            class="d-flex flex-column align-center justify-center py-12 text-center"
          >
            <VIcon
              icon="tabler-layout-dashboard"
              size="48"
              class="mb-3"
            />
            <div class="text-body-1 text-medium-emphasis">
              Nội dung {{ steps[currentStep - 1]?.title }} sẽ được bổ sung tại đây.
            </div>
          </div>
        </div>
      </div>

      <!-- ================= FOOTER: ĐIỀU HƯỚNG ================= -->
      <template #footer>
        <div class="d-flex justify-space-between align-center w-100 pa-4">
          <VBtn
            variant="outlined"
            color="secondary"
            class="text-none"
            @click="close"
          >
            Hủy
          </VBtn>
          <div class="d-flex gap-2">
            <VBtn
              v-if="currentStep > 1"
              variant="outlined"
              color="secondary"
              class="text-none"
              @click="prevStep"
            >
              Quay lại
            </VBtn>
            <VBtn
              color="primary"
              append-icon="tabler-arrow-right"
              class="text-none"
              @click="nextStep"
            >
              Tiếp theo
            </VBtn>
          </div>
        </div>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>

<style scoped>
/* Sidebar bước */
.step-sidebar {
  width: 220px;
}

.step-item {
  transition: background-color 0.15s ease;
}

.step-item:hover {
  background-color: rgba(var(--v-theme-primary), 0.04);
}

.step-active {
  background-color: rgba(var(--v-theme-primary), 0.08) !important;
}

/* Lưới 6 mục tiêu */
.objective-grid {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 8px;
}

@media (max-width: 900px) {
  .objective-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

.objective-card {
  min-height: 85px;
  transition: all 0.2s ease;
}

.objective-card:hover {
  border-color: rgb(var(--v-theme-primary)) !important;
  background-color: rgba(var(--v-theme-primary), 0.04);
}

.objective-active {
  border: 1.5px solid rgb(var(--v-theme-primary)) !important;
  background-color: rgba(var(--v-theme-primary), 0.08) !important;
  box-shadow: 0 4px 12px rgba(var(--v-theme-primary), 0.12);
}

.objective-check-badge {
  position: absolute;
  top: 6px;
  right: 6px;
  background-color: rgb(var(--v-theme-primary));
  border-radius: 50%;
  width: 18px;
  height: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
}
</style>
