<!--
  =====================================================================
  CHỨC NĂNG FILE: Tab "Cài đặt chung" của trang Lịch Đăng Bài (post-schedule).
  =====================================================================

  Panel con render bởi index.vue trong VWindowItem value="general".
  Chứa cài đặt số lượng/khung giờ tạo bài, công tắc tự động gửi duyệt/lên lịch
  và các trường chiến dịch, danh mục, ngôn ngữ.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - Không có hàm nghiệp vụ; chỉ state cục bộ của form.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : không có props; state giữ trong component.
  - OUTPUT: UI cài đặt chung; chưa emit/persist vì chưa có API.
  =====================================================================
-->
<script setup>
import { ref } from 'vue'

// Số bài AI tạo mỗi ngày và khung giờ tạo.
const dailyCount = ref(3)
const createSlots = ref(['08:00', '13:00', '19:00'])

// Công tắc quy trình sau khi AI tạo xong.
const autoApproveQueue = ref(true)
const autoSchedulePost = ref(false)

// Trường phân loại nội dung.
const campaign = ref('Content tháng 10')
const categories = ref(['Sản phẩm', 'Khuyến mãi', 'Chia sẻ kiến thức'])
const language = ref('Tiếng Việt')
</script>

<template>
  <div>
    <!-- Số lượng tạo bài mỗi ngày -->
    <div class="mb-6">
      <div class="text-subtitle-1 font-weight-medium text-high-emphasis mb-1">
        Số lượng tạo bài mỗi ngày
      </div>
      <div class="text-body-2 text-medium-emphasis mb-3">
        AI sẽ tự động tạo nội dung theo số lượng bạn thiết lập
      </div>
      <div class="d-flex align-center gap-3">
        <div class="d-inline-flex align-center border rounded pa-1">
          <VBtn
            icon="tabler-minus"
            variant="text"
            size="x-small"
            color="secondary"
            @click="dailyCount > 1 && dailyCount--"
          />
          <span class="px-5 text-body-2 font-weight-bold text-high-emphasis">
            {{ dailyCount }}
          </span>
          <VBtn
            icon="tabler-plus"
            variant="text"
            size="x-small"
            color="secondary"
            @click="dailyCount++"
          />
        </div>
        <span class="text-body-2 text-medium-emphasis">bài/ngày</span>
      </div>
    </div>

    <VDivider class="my-4" />

    <!-- Khung giờ tạo bài -->
    <div class="mb-6">
      <div class="text-subtitle-1 font-weight-medium text-high-emphasis mb-1">
        Khung giờ tạo bài
      </div>
      <div class="text-body-2 text-medium-emphasis mb-3">
        Chọn thời gian AI tự động tạo bài viết
      </div>
      <div class="d-flex flex-wrap align-center gap-2">
        <VChip
          v-for="(time, idx) in createSlots"
          :key="idx"
          size="small"
          color="primary"
          variant="tonal"
          closable
          @click:close="createSlots.splice(idx, 1)"
        >
          {{ time }}
        </VChip>
        <VBtn
          variant="outlined"
          color="secondary"
          size="small"
          prepend-icon="tabler-plus"
          class="add-slot-btn"
          @click="createSlots.push('21:00')"
        >
          Thêm giờ
        </VBtn>
      </div>
    </div>

    <VDivider class="my-4" />

    <!-- Công tắc Tự động gửi duyệt & Tự động lên lịch -->
    <div class="d-flex align-center justify-space-between mb-4">
      <div class="pe-2">
        <div class="text-body-1 font-weight-medium text-high-emphasis">
          Tự động gửi duyệt
        </div>
        <div class="text-body-2 text-medium-emphasis">
          Sau khi tạo xong, bài viết sẽ được gửi đến hàng chờ duyệt
        </div>
      </div>
      <VSwitch
        v-model="autoApproveQueue"
        density="compact"
        hide-details
        inset
      />
    </div>

    <div class="d-flex align-center justify-space-between mb-6">
      <div class="pe-2">
        <div class="text-body-1 font-weight-medium text-high-emphasis">
          Tự động lên lịch sau khi duyệt
        </div>
        <div class="text-body-2 text-medium-emphasis">
          Tự động lên lịch đăng theo khung giờ đã thiết lập
        </div>
      </div>
      <VSwitch
        v-model="autoSchedulePost"
        density="compact"
        hide-details
        inset
      />
    </div>

    <VDivider class="my-4" />

    <!-- Chiến dịch, Danh mục & Ngôn ngữ -->
    <div class="d-flex flex-column gap-y-4">
      <AppSelect
        v-model="campaign"
        label="Chiến dịch mặc định"
        :items="['Content tháng 10', 'Chiến dịch cuối năm', 'Ra mắt thương hiệu']"
      />
      <AppCombobox
        v-model="categories"
        label="Danh mục nội dung"
        :items="['Sản phẩm', 'Khuyến mãi', 'Chia sẻ kiến thức', 'Tin tức']"
        multiple
        chips
        closable-chips
      />
      <AppSelect
        v-model="language"
        label="Ngôn ngữ bài viết"
        :items="['Tiếng Việt', 'Tiếng Anh (English)', 'Tiếng Nhật']"
      />
    </div>
  </div>
</template>

<style lang="scss" scoped>
/* Nút nét đứt thêm giờ */
.add-slot-btn {
  border-style: dashed;
}
</style>
