<!--
  =====================================================================
  CHỨC NĂNG FILE: Tab "Lịch đăng" của trang Lịch Đăng Bài (post-schedule).
  =====================================================================

  Panel con render bởi index.vue trong VWindowItem value="schedule".
  Hiển thị lưới lịch tuần các bài đã lên lịch, mini calendar, thống kê
  trạng thái và bộ lọc nền tảng; hiện là dữ liệu mẫu (chưa nối API).

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - getFilteredPostsByDay(dateStr): lọc bài đăng theo ngày + nền tảng đang bật.
  - calendarView: chế độ xem Ngày/Tuần/Tháng/Danh sách (state cục bộ).

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : không có props; state giữ trong component.
  - OUTPUT: UI lịch đăng; chưa emit/persist vì chưa có API.
  =====================================================================
-->
<script setup>
import { ref } from 'vue'

const calendarView = ref('week')

// 7 ngày trong tuần hiển thị trên lịch (12/10 -> 18/10)
const weekDays = [
  { name: 'Thứ 2', date: '12/10', isToday: false },
  { name: 'Thứ 3', date: '13/10', isToday: false },
  { name: 'Thứ 4', date: '14/10', isToday: false },
  { name: 'Thứ 5', date: '15/10', isToday: true },
  { name: 'Thứ 6', date: '16/10', isToday: false },
  { name: 'Thứ 7', date: '17/10', isToday: false },
  { name: 'Chủ nhật', date: '18/10', isToday: false },
]

// Các mốc giờ ở cột ngoài cùng bên trái
const timeSlots = [
  '07:00',
  '08:00',
  '09:00',
  '10:00',
  '12:00',
  '14:00',
  '15:00',
  '16:00',
  '17:00',
  '18:00',
  '19:00',
  '20:00',
]

// Dữ liệu 18 thẻ bài đăng trên lưới lịch
const scheduledPosts = ref([
  // Thứ 2 (12/10)
  {
    id: 1,
    day: '12/10',
    platform: 'facebook',
    platformIcon: 'tabler-brand-facebook',
    platformBg: '#1877F2',
    time: '08:00',
    borderColor: '#dbeafe',
    title: 'Cà phê sáng năng lượng',
    image: 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=300',
    top: 38,
  },
  {
    id: 2,
    day: '12/10',
    platform: 'instagram',
    platformIcon: 'tabler-brand-instagram',
    platformBg: '#E4405F',
    time: '10:00',
    borderColor: '#fce7f3',
    title: 'Khuyến mãi tháng 10',
    image: 'https://images.unsplash.com/photo-1513885535751-8b9238bd345a?w=300',
    top: 195,
  },
  {
    id: 3,
    day: '12/10',
    platform: 'tiktok',
    platformIcon: 'tabler-brand-tiktok',
    platformBg: '#111111',
    time: '14:00',
    borderColor: '#e5e7eb',
    title: 'Hành trình khám phá',
    image: 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=300',
    isVideo: true,
    top: 365,
  },

  // Thứ 3 (13/10)
  {
    id: 4,
    day: '13/10',
    platform: 'instagram',
    platformIcon: 'tabler-brand-instagram',
    platformBg: '#E4405F',
    time: '08:00',
    borderColor: '#fce7f3',
    title: 'Trà đào nhiệt đới',
    image: 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=300',
    top: 38,
  },
  {
    id: 5,
    day: '13/10',
    platform: 'youtube',
    platformIcon: 'tabler-brand-youtube',
    platformBg: '#FF0000',
    time: '10:00',
    borderColor: '#fee2e2',
    title: 'Review bánh mì mới',
    image: 'https://images.unsplash.com/photo-1509722747041-616f39b57569?w=300',
    top: 205,
  },
  {
    id: 6,
    day: '13/10',
    platform: 'facebook',
    platformIcon: 'tabler-brand-facebook',
    platformBg: '#1877F2',
    time: '16:00',
    borderColor: '#dbeafe',
    title: 'Menu mùa hè mới',
    image: 'https://images.unsplash.com/photo-1513558161293-cdaf765ed2fd?w=300',
    top: 505,
  },

  // Thứ 4 (14/10)
  {
    id: 7,
    day: '14/10',
    platform: 'tiktok',
    platformIcon: 'tabler-brand-tiktok',
    platformBg: '#111111',
    time: '09:00',
    borderColor: '#e5e7eb',
    title: '5 mẹo chăm sóc',
    image: 'https://images.unsplash.com/photo-1543466835-00a7907e9de1?w=300',
    top: 100,
  },
  {
    id: 8,
    day: '14/10',
    platform: 'linkedin',
    platformIcon: 'tabler-brand-linkedin',
    platformBg: '#0A66C2',
    time: '13:00',
    borderColor: '#e0f2fe',
    title: 'Báo cáo xu hướng',
    image: 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=300',
    top: 340,
  },

  // Thứ 5 (15/10 - Cột ngày hiện tại)
  {
    id: 9,
    day: '15/10',
    platform: 'facebook',
    platformIcon: 'tabler-brand-facebook',
    platformBg: '#1877F2',
    time: '08:00',
    borderColor: '#c7d2fe',
    title: 'Tips pha cà phê',
    image: 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=300',
    isVideo: true,
    top: 28,
  },
  {
    id: 10,
    day: '15/10',
    platform: 'instagram',
    platformIcon: 'tabler-brand-instagram',
    platformBg: '#E4405F',
    time: '12:00',
    borderColor: '#fce7f3',
    title: 'Không gian quán',
    image: 'https://images.unsplash.com/photo-1554118811-1e0d58224f24?w=300',
    isVideo: true,
    top: 230,
  },
  {
    id: 11,
    day: '15/10',
    platform: 'youtube',
    platformIcon: 'tabler-brand-youtube',
    platformBg: '#FF0000',
    time: '16:00',
    borderColor: '#fee2e2',
    title: 'Trải nghiệm thực tế',
    image: 'https://images.unsplash.com/photo-1447933601403-0c6688de566e?w=300',
    isVideo: true,
    top: 430,
  },
  {
    id: 12,
    day: '15/10',
    platform: 'tiktok',
    platformIcon: 'tabler-brand-tiktok',
    platformBg: '#111111',
    time: '19:00',
    borderColor: '#e5e7eb',
    title: 'Công thức cold brew',
    image: 'https://images.unsplash.com/photo-1517701550927-30cf4ba1dba5?w=300',
    top: 630,
  },

  // Thứ 6 (16/10)
  {
    id: 13,
    day: '16/10',
    platform: 'website',
    platformIcon: 'tabler-world',
    platformBg: '#00cfe8',
    time: '09:00',
    borderColor: '#cffafe',
    title: 'Sản phẩm mới ra mắt',
    image: 'https://images.unsplash.com/photo-1522335789203-aabd1fc54bc9?w=300',
    top: 90,
  },
  {
    id: 14,
    day: '16/10',
    platform: 'facebook',
    platformIcon: 'tabler-brand-facebook',
    platformBg: '#1877F2',
    time: '14:00',
    borderColor: '#dbeafe',
    title: 'Câu chuyện thương hiệu',
    image: 'https://images.unsplash.com/photo-1514432324607-a09d9b4aefdd?w=300',
    isVideo: true,
    top: 380,
  },

  // Thứ 7 (17/10)
  {
    id: 15,
    day: '17/10',
    platform: 'tiktok',
    platformIcon: 'tabler-brand-tiktok',
    platformBg: '#111111',
    time: '10:00',
    borderColor: '#e5e7eb',
    title: 'Behind the scene',
    image: 'https://images.unsplash.com/photo-1507133750040-4a8f57021571?w=300',
    isVideo: true,
    top: 155,
  },
  {
    id: 16,
    day: '17/10',
    platform: 'instagram',
    platformIcon: 'tabler-brand-instagram',
    platformBg: '#E4405F',
    time: '17:00',
    borderColor: '#fce7f3',
    title: 'Góc decor quán',
    image: 'https://images.unsplash.com/photo-1501339847302-ac426a4a7cbb?w=300',
    top: 550,
  },

  // Chủ nhật (18/10)
  {
    id: 17,
    day: '18/10',
    platform: 'youtube',
    platformIcon: 'tabler-brand-youtube',
    platformBg: '#FF0000',
    time: '09:00',
    borderColor: '#fee2e2',
    title: 'Livestream cuối tuần',
    image: 'https://images.unsplash.com/photo-1511920170033-f8396924c348?w=300',
    isVideo: true,
    top: 90,
  },
  {
    id: 18,
    day: '18/10',
    platform: 'facebook',
    platformIcon: 'tabler-brand-facebook',
    platformBg: '#1877F2',
    time: '13:00',
    borderColor: '#dbeafe',
    title: 'Ưu đãi cuối tuần',
    image: 'https://images.unsplash.com/photo-1607083206869-4c7672e72a8a?w=300',
    top: 340,
  },
])

// Bộ lọc Nền tảng hiển thị bên phải
const platformFilters = ref([
  { id: 'facebook', name: 'Facebook', icon: 'tabler-brand-facebook', bg: '#1877F2', checked: true },
  { id: 'instagram', name: 'Instagram', icon: 'tabler-brand-instagram', bg: '#E4405F', checked: true },
  { id: 'tiktok', name: 'TikTok', icon: 'tabler-brand-tiktok', bg: '#111111', checked: true },
  { id: 'youtube', name: 'YouTube', icon: 'tabler-brand-youtube', bg: '#FF0000', checked: true },
  { id: 'twitter', name: 'X (Twitter)', icon: 'tabler-brand-x', bg: '#000000', checked: false },
  { id: 'linkedin', name: 'LinkedIn', icon: 'tabler-brand-linkedin', bg: '#0A66C2', checked: true },
])

/**
 * =====================================================================
 * CHỨC NĂNG: Lọc các bài đăng theo ngày và nền tảng đang bật
 * =====================================================================
 * INPUT : dateStr - chuỗi ngày dạng 'dd/MM' khớp weekDays.date.
 * OUTPUT: mảng scheduledPosts thuộc ngày đó và platform nằm trong filter.
 * SIDE EFFECT: không có (chỉ đọc state).
 * =====================================================================
 */
const getFilteredPostsByDay = dateStr => {
  const activeIds = platformFilters.value.filter(p => p.checked).map(p => p.id)

  return scheduledPosts.value.filter(
    post => post.day === dateStr && activeIds.includes(post.platform),
  )
}

// Dữ liệu Mini Calendar Tháng 10, 2026
const miniCalendarDays = [
  { day: 28, muted: true },
  { day: 29, muted: true },
  { day: 30, muted: true },
  { day: 1, muted: false },
  { day: 2, muted: false },
  { day: 3, muted: false },
  { day: 4, muted: false },
  { day: 5, muted: false },
  { day: 6, muted: false },
  { day: 7, muted: false },
  { day: 8, muted: false, dotColor: 'primary' },
  { day: 9, muted: false },
  { day: 10, muted: false },
  { day: 11, muted: false },
  { day: 12, muted: false },
  { day: 13, muted: false, dotColor: 'success' },
  { day: 14, muted: false, dotColor: 'primary' },
  { day: 15, muted: false, active: true },
  { day: 16, muted: false, dotColor: 'success' },
  { day: 17, muted: false, dotColor: 'warning' },
  { day: 18, muted: false },
  { day: 19, muted: false },
  { day: 20, muted: false },
  { day: 21, muted: false },
  { day: 22, muted: false, dotColor: 'success' },
  { day: 23, muted: false, dotColor: 'primary' },
  { day: 24, muted: false },
  { day: 25, muted: false },
  { day: 26, muted: false },
  { day: 27, muted: false },
  { day: 28, muted: false },
  { day: 29, muted: false, dotColor: 'success' },
  { day: 30, muted: false, dotColor: 'primary' },
  { day: 31, muted: false },
  { day: 1, muted: true },
]

// Thống kê số lượng theo trạng thái
const scheduleStats = [
  { label: 'Đã lên lịch', count: 12, color: 'primary' },
  { label: 'Chờ duyệt', count: 6, color: 'warning' },
  { label: 'Đã đăng', count: 15, color: 'success' },
  { label: 'Từ chối', count: 2, color: 'error' },
]
</script>

<template>
  <div class="schedule-tab-content">
    <VRow>
      <!-- ================= CỘT TRÁI: LƯỚI LỊCH TUẦN (WEEKLY CALENDAR) ================= -->
      <VCol
        cols="12"
        lg="9"
      >
        <!-- Thanh điều hướng & Chuyển đổi chế độ xem -->
        <div class="d-flex justify-space-between align-center flex-wrap gap-3 mb-4">
          <div class="d-flex align-center gap-2">
            <div class="d-flex border rounded overflow-hidden bg-surface">
              <VBtn
                icon="tabler-chevron-left"
                variant="text"
                size="small"
                density="comfortable"
                color="secondary"
              />
              <VDivider vertical />
              <VBtn
                icon="tabler-chevron-right"
                variant="text"
                size="small"
                density="comfortable"
                color="secondary"
              />
            </div>

            <VBtn
              variant="text"
              class="text-none font-weight-medium text-body-2 text-high-emphasis px-2"
              append-icon="tabler-chevron-down"
            >
              Tháng 10, 2026
            </VBtn>

            <VBtn
              variant="outlined"
              color="secondary"
              size="small"
              class="text-none"
            >
              Hôm nay
            </VBtn>
          </div>

          <!-- Bộ nút chọn chế độ xem Ngày / Tuần / Tháng / Danh sách -->
          <VBtnToggle
            v-model="calendarView"
            mandatory
            density="compact"
            color="primary"
            rounded="lg"
            class="border"
          >
            <VBtn
              value="day"
              class="text-none text-caption px-3"
            >
              Ngày
            </VBtn>
            <VBtn
              value="week"
              class="text-none text-caption px-3"
            >
              Tuần
            </VBtn>
            <VBtn
              value="month"
              class="text-none text-caption px-3"
            >
              Tháng
            </VBtn>
            <VBtn
              value="list"
              class="text-none text-caption px-3"
            >
              Danh sách
            </VBtn>
          </VBtnToggle>
        </div>

        <!-- Khung Lưới Lịch Tuần -->
        <div class="weekly-calendar-wrapper border rounded-lg overflow-x-auto bg-surface">
          <div class="weekly-calendar-grid">
            <!-- Ô trống góc trên bên trái -->
            <div class="time-col-header border-b border-e" />

            <!-- Header 7 ngày trong tuần -->
            <div
              v-for="day in weekDays"
              :key="day.date"
              class="day-col-header text-center border-b border-e"
              :class="{ 'day-col-highlight-header': day.isToday }"
            >
              <div
                class="text-caption font-weight-medium"
                :class="day.isToday ? 'text-primary' : 'text-high-emphasis'"
              >
                {{ day.name }}
              </div>
              <div
                class="text-caption"
                :class="day.isToday ? 'text-primary font-weight-medium' : 'text-medium-emphasis'"
              >
                {{ day.date }}
              </div>
            </div>

            <!-- Cột trục giờ bên trái -->
            <div class="time-axis-col border-e">
              <div
                v-for="(hour, hIdx) in timeSlots"
                :key="hIdx"
                class="time-slot-label text-caption text-medium-emphasis"
              >
                {{ hour }}
              </div>
            </div>

            <!-- 7 Cột hiển thị các bài đăng theo từng ngày -->
            <div
              v-for="day in weekDays"
              :key="day.date"
              class="day-events-col border-e position-relative"
              :class="{ 'day-col-highlight-body': day.isToday }"
            >
              <!-- Đường kẻ ngang chia các khung giờ -->
              <div
                v-for="(_, hIdx) in timeSlots"
                :key="hIdx"
                class="hour-grid-line border-b"
              />

              <!-- Card bài viết đã lên lịch -->
              <div
                v-for="post in getFilteredPostsByDay(day.date)"
                :key="post.id"
                class="scheduled-post-card rounded-lg pa-2 elevation-1 cursor-pointer bg-surface"
                :style="{ top: post.top + 'px', borderColor: post.borderColor }"
              >
                <!-- Header Card: Icon nền tảng + Thời gian + Menu -->
                <div class="d-flex justify-space-between align-center mb-1">
                  <div class="d-flex align-center gap-1">
                    <VAvatar
                      :color="post.platformBg"
                      size="18"
                      rounded="sm"
                    >
                      <VIcon
                        :icon="post.platformIcon"
                        color="white"
                        size="12"
                      />
                    </VAvatar>
                    <span
                      class="font-weight-medium text-caption"
                      :style="{ color: post.platformBg }"
                    >
                      {{ post.time }}
                    </span>
                  </div>
                  <VIcon
                    icon="tabler-dots"
                    size="14"
                    color="secondary"
                  />
                </div>

                <!-- Tiêu đề bài đăng -->
                <div class="text-caption font-weight-medium text-high-emphasis text-truncate mb-1">
                  {{ post.title }}
                </div>

                <!-- Ảnh / Video Thumbnail -->
                <div class="position-relative rounded overflow-hidden">
                  <VImg
                    :src="post.image"
                    height="58"
                    cover
                    class="bg-grey-lighten-3"
                  />
                  <div
                    v-if="post.isVideo"
                    class="video-overlay d-flex align-center justify-center"
                  >
                    <VAvatar
                      color="rgba(0,0,0,0.55)"
                      size="22"
                    >
                      <VIcon
                        icon="tabler-player-play"
                        color="white"
                        size="14"
                      />
                    </VAvatar>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </VCol>

      <!-- ================= CỘT PHẢI: MINI CALENDAR, TRẠNG THÁI & BỘ LỌC ================= -->
      <VCol
        cols="12"
        lg="3"
      >
        <div class="d-flex flex-column gap-4">
          <!-- Nút + Thêm lịch đăng -->
          <VBtn
            block
            color="primary"
            prepend-icon="tabler-plus"
          >
            Thêm lịch đăng
          </VBtn>

          <!-- Khung Lịch nhỏ (Mini Calendar) & Thống kê trạng thái -->
          <div class="border rounded pa-4">
            <div class="d-flex justify-space-between align-center mb-3">
              <span class="text-body-2 font-weight-medium text-high-emphasis">Tháng 10, 2026</span>
              <div class="d-flex gap-1">
                <VBtn
                  icon="tabler-chevron-left"
                  variant="outlined"
                  size="x-small"
                  color="secondary"
                />
                <VBtn
                  icon="tabler-chevron-right"
                  variant="outlined"
                  size="x-small"
                  color="secondary"
                />
              </div>
            </div>

            <!-- Hàng tiêu đề thứ trong tuần -->
            <div class="mini-cal-grid text-center mb-1">
              <span
                v-for="d in ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN']"
                :key="d"
                class="text-caption text-medium-emphasis font-weight-medium"
              >
                {{ d }}
              </span>
            </div>

            <!-- Lưới các ngày trong tháng -->
            <div class="mini-cal-grid text-center">
              <div
                v-for="(dayObj, idx) in miniCalendarDays"
                :key="idx"
                class="mini-cal-cell d-flex flex-column align-center justify-center cursor-pointer"
              >
                <div
                  class="mini-cal-num d-flex align-center justify-center rounded-circle text-caption"
                  :class="{
                    'text-disabled': dayObj.muted,
                    'bg-primary text-white font-weight-medium': dayObj.active,
                    'text-high-emphasis': !dayObj.muted && !dayObj.active,
                  }"
                >
                  {{ dayObj.day }}
                </div>
                <span
                  v-if="dayObj.dotColor"
                  class="mini-cal-dot mt-1"
                  :style="{ backgroundColor: `rgb(var(--v-theme-${dayObj.dotColor}))` }"
                />
              </div>
            </div>

            <VDivider class="my-4" />

            <!-- Danh sách thống kê theo trạng thái -->
            <div class="d-flex flex-column gap-2">
              <div
                v-for="st in scheduleStats"
                :key="st.label"
                class="d-flex justify-space-between align-center text-body-2"
              >
                <div class="d-flex align-center">
                  <span
                    class="status-dot me-2"
                    :style="{ backgroundColor: `rgb(var(--v-theme-${st.color}))` }"
                  />
                  <span class="text-medium-emphasis">{{ st.label }}</span>
                </div>
                <span class="font-weight-medium text-high-emphasis">{{ st.count }}</span>
              </div>
            </div>
          </div>

          <!-- Khung lọc Nền tảng hiển thị -->
          <div class="border rounded pa-4">
            <div class="text-body-2 font-weight-medium text-high-emphasis mb-3">
              Nền tảng hiển thị
            </div>

            <div class="d-flex flex-column gap-2">
              <div
                v-for="pf in platformFilters"
                :key="pf.id"
                class="d-flex align-center cursor-pointer"
                @click="pf.checked = !pf.checked"
              >
                <VCheckboxBtn
                  v-model="pf.checked"
                  color="primary"
                  density="compact"
                  class="me-2"
                  @click.stop
                />
                <VAvatar
                  :color="pf.bg"
                  size="24"
                  rounded
                  class="me-2"
                >
                  <VIcon
                    :icon="pf.icon"
                    color="white"
                    size="15"
                  />
                </VAvatar>
                <span class="text-body-2 font-weight-medium text-high-emphasis">
                  {{ pf.name }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </VCol>
    </VRow>
  </div>
</template>

<style lang="scss" scoped>
/* Lưới Lịch Tuần 7 cột ngày + 1 cột giờ */
.weekly-calendar-grid {
  display: grid;
  grid-template-columns: 56px repeat(7, minmax(128px, 1fr));
  min-inline-size: 960px;
}

.day-col-header {
  padding-block: 10px;
}

.day-col-highlight-header {
  background-color: rgba(var(--v-theme-primary), 0.08) !important;
}

.day-col-highlight-body {
  background-color: rgba(var(--v-theme-primary), 0.04);
}

.time-axis-col {
  background-color: rgba(var(--v-theme-on-surface), 0.02);
}

.time-slot-label {
  block-size: 54px;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding-block-start: 6px;
}

.day-events-col {
  min-block-size: 756px;
}

.hour-grid-line {
  block-size: 54px;
  border-color: rgba(var(--v-border-color), var(--v-border-opacity)) !important;
}

/* Card bài viết trên lịch */
.scheduled-post-card {
  position: absolute;
  inset-inline: 6px;
  border: 1.5px solid rgba(var(--v-border-color), var(--v-border-opacity));
  z-index: 2;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.scheduled-post-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(var(--v-theme-primary), 0.16) !important;
  z-index: 5;
}

.video-overlay {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.15);
}

/* Mini Calendar */
.mini-cal-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
}

.mini-cal-num {
  inline-size: 28px;
  block-size: 28px;
}

.mini-cal-dot {
  inline-size: 4px;
  block-size: 4px;
  border-radius: 50%;
}

/* Chấm trạng thái */
.status-dot {
  inline-size: 8px;
  block-size: 8px;
  border-radius: 50%;
  display: inline-block;
}
</style>
