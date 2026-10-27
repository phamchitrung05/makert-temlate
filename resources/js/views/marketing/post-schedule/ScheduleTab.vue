<!--
  =====================================================================
  CHỨC NĂNG FILE: Tab "Lịch đăng" của trang Lịch Đăng Bài (post-schedule).
  =====================================================================

  Panel con render bởi index.vue trong VWindowItem value="schedule".
  Hiển thị lưới lịch tháng các bài đã lên lịch cùng bộ lọc chiến dịch,
  nền tảng và các thiết lập nâng cao; hiện là dữ liệu mẫu (chưa nối API).

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - calendarView: chế độ xem Tháng / Tuần / Ngày (state cục bộ).
  - selectCalendarCell(index): chọn ô ngày trên lịch tháng.
  - getFilteredPostsByDay(dateStr): lọc bài đăng theo ngày + nền tảng đang bật.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : không có props; state giữ trong component.
  - OUTPUT: UI lịch đăng; chưa emit/persist vì chưa có API.
  =====================================================================
-->
<template>
  <div class="schedule-tab-view">
    <VRow dense>
      <!-- ================= 1. LỊCH THÁNG TOÀN BỘ ================= -->
      <VCol cols="12">
        <div class="d-flex flex-column gap-4 h-100">
          <!-- Toolbar lịch -->
          <div class="d-flex align-center justify-space-between flex-wrap gap-3">
            <VBtnToggle
              v-model="calendarView"
              mandatory
              density="compact"
              color="primary"
            >
              <VBtn
                value="month"
                class="text-none"
                size="small"
              >
                Tháng
              </VBtn>
              <VBtn
                value="week"
                class="text-none"
                size="small"
              >
                Tuần
              </VBtn>
              <VBtn
                value="day"
                class="text-none"
                size="small"
              >
                Ngày
              </VBtn>
            </VBtnToggle>

            <div class="d-flex align-center gap-2">
              <VBtn
                icon="tabler-chevron-left"
                variant="outlined"
                size="x-small"
              />
              <span class="text-body-2 font-weight-medium text-high-emphasis">
                Tháng 10, 2026
              </span>
              <VBtn
                icon="tabler-chevron-right"
                variant="outlined"
                size="x-small"
              />
            </div>

            <div class="d-flex align-center gap-2 flex-wrap">
              <VSelect
                v-model="filterCampaign"
                :items="['Tất cả chiến dịch', 'BST Đồ uống mùa thu']"
                variant="outlined"
                density="compact"
                hide-details
                style="inline-size: 170px;"
              />
              <VSelect
                v-model="filterPlatform"
                :items="['Tất cả nền tảng', 'Facebook', 'Instagram', 'TikTok', 'YouTube']"
                variant="outlined"
                density="compact"
                hide-details
                style="inline-size: 150px;"
              />
            </div>
          </div>

          <!-- Lưới lịch 7 cột: mỗi ô ngày là hình vuông đều nhau -->
          <div class="calendar-wrapper border rounded overflow-hidden flex-grow-1">
            <div class="calendar-grid">
              <div
                v-for="dow in ['Th 2', 'Th 3', 'Th 4', 'Th 5', 'Th 6', 'Th 7', 'CN']"
                :key="dow"
                class="py-2 text-center text-caption font-weight-bold text-medium-emphasis border-b border-e"
              >
                {{ dow }}
              </div>
              <div
                v-for="(cell, cIdx) in monthCells"
                :key="cIdx"
                class="cal-day-cell pa-2 border-b border-e d-flex flex-column cursor-pointer"
                :class="{
                  'cal-day-cell-muted': cell.muted,
                  'cal-cell-active': cell.isSelected,
                  'cal-cell-today': cell.isToday,
                }"
                @click="selectCalendarCell(cIdx)"
              >
                <div class="mb-1">
                  <span
                    v-if="cell.day"
                    class="cal-day-num text-caption font-weight-bold d-inline-flex align-center justify-center"
                    :class="{
                      'text-disabled': cell.muted,
                      'cal-day-num-active': cell.isSelected,
                    }"
                  >
                    {{ cell.day }}
                  </span>
                </div>
                <div
                  v-if="cell.posts?.length"
                  class="d-flex flex-column gap-1 overflow-hidden"
                >
                  <div
                    v-for="(post, pIdx) in cell.posts"
                    :key="pIdx"
                    class="post-pill pa-1 rounded d-flex align-center text-truncate"
                  >
                    <VAvatar
                      :color="post.iconColor"
                      size="32"
                      rounded="sm"
                      class="me-1 flex-shrink-0"
                    >
                      <VIcon
                        :icon="post.icon"
                        size="18"
                        color="white"
                      />
                    </VAvatar>
                    <span class="text-caption text-high-emphasis text-truncate">
                      {{ post.time }} {{ post.title }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </VCol>
    </VRow>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'

const calendarView = ref('month')
const filterCampaign = ref('Tất cả chiến dịch')
const filterPlatform = ref('Tất cả nền tảng')

// Cấu hình nền tảng: icon tabler + màu brand thật của từng nền tảng
const platformMeta = {
  fb: { icon: 'tabler-brand-facebook', iconColor: '#1877F2' },
  ig: { icon: 'tabler-brand-instagram', iconColor: '#E4405F' },
  tt: { icon: 'tabler-brand-tiktok', iconColor: '#000000' },
  yt: { icon: 'tabler-brand-youtube', iconColor: '#FF0000' },
}

// 35 ô ngày trên lịch tháng 10/2026 (ô vuông đều nhau qua aspect-ratio)
const monthCells = ref([
  { day: 29, muted: true },
  { day: 30, muted: true },
  {
    day: 1,
    posts: [
      { time: '08:00', title: 'Cà phê sáng', ...platformMeta.fb },
      { time: '12:00', title: 'BST mới', ...platformMeta.ig },
    ],
  },
  { day: 2, posts: [{ time: '19:30', title: 'Tips pha', ...platformMeta.tt }] },
  { day: 3, posts: [{ time: '09:00', title: 'Món mới', ...platformMeta.fb }] },
  { day: 4, posts: [{ time: '10:00', title: 'Không gian', ...platformMeta.ig }] },
  { day: 5, posts: [{ time: '20:00', title: 'Review', ...platformMeta.yt }] },
  { day: 6, posts: [{ time: '08:30', title: 'Hậu trường', ...platformMeta.tt }] },
  { day: 7, posts: [{ time: '14:00', title: 'Mini game', ...platformMeta.fb }] },
  { day: 8, posts: [{ time: '10:00', title: 'Trải nghiệm', ...platformMeta.ig }] },
  { day: 9, posts: [{ time: '09:00', title: 'Ưu đãi', ...platformMeta.fb }] },
  { day: 10, posts: [{ time: '19:30', title: 'Công thức', ...platformMeta.tt }] },
  { day: 11, posts: [{ time: '08:00', title: 'Cà phê ngon', ...platformMeta.fb }] },
  { day: 12, posts: [{ time: '15:00', title: 'Quà tặng', ...platformMeta.ig }] },
  { day: 13, posts: [{ time: '15:00', title: 'Feedback', ...platformMeta.yt }] },
  {
    day: 14,
    isSelected: true,
    isToday: true,
    posts: [
      { time: '08:00', title: 'Cà phê sáng', ...platformMeta.fb },
      { time: '14:00', title: 'Không gian', ...platformMeta.ig },
      { time: '19:30', title: 'Tips pha', ...platformMeta.tt },
    ],
  },
  { day: 15, posts: [{ time: '09:00', title: 'Món mới', ...platformMeta.fb }] },
  { day: 16, posts: [{ time: '11:30', title: 'Review', ...platformMeta.ig }] },
  { day: 17, posts: [{ time: '19:00', title: 'Mini game', ...platformMeta.tt }] },
  { day: 18, posts: [{ time: '08:00', title: 'Quà tặng', ...platformMeta.fb }] },
  { day: 19, posts: [{ time: '20:00', title: 'Behind', ...platformMeta.yt }] },
  { day: 20, posts: [{ time: '10:00', title: 'Lifestyle', ...platformMeta.ig }] },
  { day: 21, posts: [{ time: '09:00', title: 'Ưu đãi', ...platformMeta.fb }] },
  { day: 22, posts: [{ time: '19:30', title: 'Trải nghiệm', ...platformMeta.tt }] },
  { day: 23, posts: [{ time: '08:00', title: 'Món ngon', ...platformMeta.fb }] },
  { day: 24, posts: [{ time: '14:00', title: 'Feedback', ...platformMeta.ig }] },
  { day: 25, posts: [{ time: '09:00', title: 'BTS', ...platformMeta.fb }] },
  { day: 26, posts: [{ time: '19:00', title: 'Tips', ...platformMeta.tt }] },
  { day: 27, posts: [{ time: '08:00', title: 'Cà phê', ...platformMeta.fb }] },
  { day: 28, posts: [{ time: '14:00', title: 'Không gian', ...platformMeta.ig }] },
  { day: 29, posts: [{ time: '20:00', title: 'Story', ...platformMeta.yt }] },
  { day: 30, posts: [{ time: '09:00', title: 'Sản phẩm', ...platformMeta.fb }] },
  { day: 31, posts: [{ time: '19:30', title: 'Review', ...platformMeta.tt }] },
  { day: '', muted: true },
])

/**
 * =====================================================================
 * CHỨC NĂNG: Chọn ô ngày trên lịch tháng
 * =====================================================================
 * INPUT : index - vị trí ô trong monthCells.
 * OUTPUT: cập nhật isSelected trên ô được chọn.
 * SIDE EFFECT: thay đổi highlight trên lịch.
 * =====================================================================
 */
const selectCalendarCell = index => {
  if (monthCells.value[index].muted)
    return
  monthCells.value.forEach((c, idx) => {
    c.isSelected = idx === index
  })
}

// Công tắc nâng cao
const advancedSwitches = [
  { key: 'autoDaily', label: 'Tự động tạo nội dung hàng ngày', icon: 'tabler-calendar-sync' },
  { key: 'autoPublish', label: 'Tự động đăng sau khi duyệt', icon: 'tabler-send' },
  { key: 'optimizePerPlatform', label: 'Tối ưu nội dung theo nền tảng', icon: 'tabler-adjustments' },
  { key: 'abTesting', label: 'Tạo 2 phiên bản nội dung (A/B)', icon: 'tabler-test-pipe' },
]

const advanced = reactive({
  autoDaily: true,
  autoPublish: false,
  optimizePerPlatform: true,
  abTesting: false,
  preferredTimes: '08:00, 12:00, 19:30',
  timezone: '(GMT+7) Hồ Chí Minh',
})
</script>

<style scoped>
/* Lưới lịch 7 cột — ô ngày là hình vuông đều nhau */
.calendar-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
}

.cal-day-cell {
  aspect-ratio: 1 / 1;
  overflow: hidden;
  transition: background-color 0.15s ease;
}

.cal-day-cell:hover {
  background-color: rgba(var(--v-theme-primary), 0.04);
}

.cal-day-cell-muted {
  background-color: rgba(var(--v-theme-on-surface), 0.02);
}

.cal-cell-active {
  background-color: rgba(var(--v-theme-primary), 0.08) !important;
  box-shadow: inset 0 0 0 1px rgb(var(--v-theme-primary));
}

/* Ngày hiện tại: nền kem nhẹ 40% opacity */
.cal-cell-today {
  background-color: rgba(255, 248, 225, 0.4) !important;
}

.cal-day-num {
  inline-size: 20px;
  block-size: 20px;
  border-radius: 4px;
}

.cal-day-num-active {
  background-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary)) !important;
}

/* Badge bài đăng trong ô: nền xám nhẹ + icon nền màu brand */
.post-pill {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background-color: rgba(var(--v-theme-on-surface), 0.04);
}
</style>
