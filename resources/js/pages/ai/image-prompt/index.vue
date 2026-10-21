<!--
  =====================================================================
  CHỨC NĂNG FILE: Trang tạo prompt mô tả từ hình ảnh tải lên.
  =====================================================================
  Người dùng tải ảnh mẫu, đặt tên gợi nhớ, chọn tỷ lệ khung hình và nhận
  prompt mô tả chi tiết để dùng lại trong các công cụ tạo ảnh.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - triggerFileInput(): mở hộp chọn tệp ảnh trên máy tính.
  - handleFileSelect(): đọc file người dùng chọn từ input và đặt ảnh preview.
  - handleDrop(): nhận file kéo-thả vào dropzone và đặt ảnh preview.
  - copyPromptText(): sao chép nội dung prompt vào clipboard và hiển thị trạng thái.
  - generatePrompt(): tạo prompt từ ảnh + tên + tỷ lệ đã chọn.
  - uploadedImage/promptName/selectedRatio/copied: state form và kết quả.
  - aspectRatios/generatedPrompt/promptTags: dữ liệu tỷ lệ và prompt mẫu.
  - props/emits: không có; page tự quản lý state cục bộ.

  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : ảnh upload, tên prompt, tỷ lệ khung hình do người dùng chọn.
  - OUTPUT: prompt mô tả chi tiết kèm tags và ảnh preview trong panel kết quả.
  - SIDE EFFECT: đọc file qua URL.createObjectURL, ghi clipboard; chưa gọi API.
  =====================================================================
-->
<script setup>
import { ref } from 'vue'

// Tham chiếu input file ẩn và trạng thái đã sao chép prompt
const fileInputRef = ref(null)
const copied = ref(false)

// Ảnh mặc định và state form: ảnh đã tải, tên prompt, tỷ lệ đang chọn
const defaultImage = 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=900'
const uploadedImage = ref(defaultImage)
const promptName = ref('Cà phê sáng – bàn gỗ')
const selectedRatio = ref('16:9')

// Các tùy chọn tỷ lệ khung hình hiển thị trong lưới
const aspectRatios = [
  { ratio: '1:1', label: 'Vuông', width: 20, height: 20 },
  { ratio: '16:9', label: 'Ngang', width: 26, height: 16 },
  { ratio: '9:16', label: 'Dọc', width: 15, height: 24 },
  { ratio: '4:3', label: 'Ngang', width: 24, height: 18 },
  { ratio: '3:4', label: 'Dọc', width: 18, height: 24 },
]

// Nội dung prompt mô tả chi tiết tạo ra từ ảnh
const generatedPrompt = ref(
  'A realistic, high-quality photo of a cup of latte coffee with latte art on a wooden table, coffee beans scattered around, a burlap sack of coffee beans, warm morning light, cozy coffee shop atmosphere, shallow depth of field, bokeh background, natural earthy tones, detailed texture, professional food photography.',
)

// Các từ khóa trích xuất từ ảnh
const promptTags = ref([
  'photorealistic',
  'coffee',
  'latte art',
  'wooden table',
  'coffee beans',
  'warm light',
  'shallow depth of field',
  'bokeh',
  'cozy atmosphere',
  'food photography',
])

// Mở hộp thoại chọn tệp ảnh trên máy tính
const triggerFileInput = () => {
  fileInputRef.value?.click()
}

// Nhận file người dùng chọn từ input và cập nhật ảnh preview
const handleFileSelect = event => {
  const file = event.target.files?.[0]
  if (file)
    uploadedImage.value = URL.createObjectURL(file)
}

// Nhận file kéo-thả vào dropzone và cập nhật ảnh preview
const handleDrop = event => {
  const file = event.dataTransfer.files?.[0]
  if (file)
    uploadedImage.value = URL.createObjectURL(file)
}

// Sao chép nội dung prompt vào clipboard và bật trạng thái "Đã chép" tạm thời
const copyPromptText = () => {
  navigator.clipboard.writeText(generatedPrompt.value)
  copied.value = true
  setTimeout(() => {
    copied.value = false
  }, 2000)
}

// Tạo prompt từ ảnh, tên và tỷ lệ đã chọn (hiện log, sẽ nối API sau)
const generatePrompt = () => {
  console.log('Đang tạo prompt cho:', {
    name: promptName.value,
    ratio: selectedRatio.value,
  })
}
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
        >
          Lịch sử prompt
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
                        JPG, PNG, WEBP<br>Tối đa 10MB
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

            <!-- Bước 3: Chọn tỷ lệ khung hình -->
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
                    3. Chọn tỷ lệ khung hình
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
            <VBtn
              block
              color="primary"
              size="large"
              prepend-icon="tabler-sparkles"
              @click="generatePrompt"
            >
              Tạo Prompt từ ảnh
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

            <!-- Hộp văn bản Prompt kết quả -->
            <div class="prompt-result-box pa-4 rounded-lg mb-4">
              <p class="text-body-2 mb-0 prompt-result-text">
                {{ generatedPrompt }}
              </p>
            </div>

            <!-- Danh sách Tags từ khóa -->
            <div class="d-flex flex-wrap gap-2 mb-4">
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

          <!-- Thông báo đã lưu lịch sử -->
          <VCardText class="pt-0">
            <div class="saved-notice-box pa-4 rounded-lg d-flex align-center">
              <VIcon
                color="primary"
                size="20"
                icon="tabler-info-circle"
                class="me-3"
              />
              <div class="text-body-2">
                Prompt này đã được lưu với tên
                <strong class="text-primary">“{{ promptName || 'Chưa đặt tên' }}”</strong>
                trong lịch sử prompt.
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
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
