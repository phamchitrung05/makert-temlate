<!--
  =====================================================================
  CHỨC NĂNG FILE: Tab "Lịch đăng" của trang Lịch Đăng Bài (post-schedule).
  =====================================================================

  Panel con render bởi index.vue trong VWindowItem value="schedule".
  Chứa lịch đăng tự động theo 7 ngày trong tuần và cài đặt múi giờ.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - Không có hàm nghiệp vụ; chỉ state cục bộ của form.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : không có props; state giữ trong component.
  - OUTPUT: UI lịch đăng; chưa emit/persist vì chưa có API.
  =====================================================================
-->
<script setup>
import { ref } from 'vue'

// Lịch đăng theo ngày trong tuần; mỗi ngày có active và danh sách khung giờ.
const scheduleDays = ref([
  { label: 'Thứ 2', active: true, times: ['08:00', '12:00', '19:00'] },
  { label: 'Thứ 3', active: true, times: ['08:00', '12:00', '19:00'] },
  { label: 'Thứ 4', active: true, times: ['08:00', '12:00', '19:00'] },
  { label: 'Thứ 5', active: true, times: ['08:00', '12:00', '19:00'] },
  { label: 'Thứ 6', active: true, times: ['08:00', '12:00', '19:00'] },
  { label: 'Thứ 7', active: false, times: ['09:00', '15:00'] },
  { label: 'Chủ nhật', active: false, times: ['09:00', '15:00'] },
])

const timezone = ref('(GMT+7) Hồ Chí Minh, Việt Nam')
</script>

<template>
  <div>
    <div class="text-body-1 font-weight-medium text-high-emphasis mb-1">
      Lịch đăng tự động
    </div>
    <div class="text-caption text-medium-emphasis mb-3">
      Thiết lập khung giờ đăng cho từng ngày trong tuần
    </div>

    <!-- Lịch 7 ngày trong tuần -->
    <div class="d-flex flex-column gap-2 mb-4">
      <div
        v-for="day in scheduleDays"
        :key="day.label"
        class="d-flex align-center justify-space-between py-1"
      >
        <div
          class="d-flex align-center"
          style="min-inline-size: 95px;"
        >
          <VCheckboxBtn
            v-model="day.active"
            density="compact"
            class="me-1"
          />
          <span
            class="text-body-2"
            :class="day.active ? 'text-high-emphasis' : 'text-medium-emphasis'"
          >
            {{ day.label }}
          </span>
        </div>

        <div class="d-flex align-center flex-wrap gap-1 justify-end flex-grow-1">
          <VChip
            v-for="(slot, sIdx) in day.times"
            :key="sIdx"
            size="x-small"
            :color="day.active ? 'primary' : 'secondary'"
            variant="tonal"
            class="px-2"
          >
            {{ slot }}
          </VChip>
          <VBtn
            icon="tabler-plus"
            size="20"
            variant="tonal"
            color="secondary"
            class="rounded"
            @click="day.times.push('21:00')"
          />
        </div>
      </div>
    </div>

    <VDivider class="my-4" />

    <!-- Múi giờ -->
    <div>
      <AppSelect
        v-model="timezone"
        label="Múi giờ"
        :items="['(GMT+7) Hồ Chí Minh, Việt Nam', '(GMT+8) Singapore', '(GMT+9) Tokyo']"
        class="mb-1"
      />
      <div class="text-caption text-medium-emphasis d-flex align-center">
        <VIcon
          icon="tabler-info-circle"
          size="14"
          class="me-1"
        />
        Tất cả thời gian sẽ được lưu theo múi giờ này
      </div>
    </div>
  </div>
</template>
