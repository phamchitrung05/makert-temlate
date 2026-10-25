<!--
  =====================================================================
  CHỨC NĂNG FILE: Trang Lịch Đăng Bài trong nhóm MARKETING.
  =====================================================================

  Route file-based: /marketing/post-schedule (name: marketing-post-schedule).
  Trang hiển thị KPI và form thiết lập lịch đăng Social Post theo nền tảng;
  hiện là khung giao diện (chưa nối API lưu/tải cấu hình). Nội dung từng tab
  được tách thành component trong views/marketing/post-schedule.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - kpiStats: 4 thẻ KPI đầu trang, số liệu/trend là dữ liệu mẫu.
  - tabs: danh sách tab ánh xạ VTab ↔ VWindowItem ↔ component.
  - saveConfiguration(): xử lý lưu thiết lập (tạm console.log vì chưa có API).

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : điều hướng tới route marketing-post-schedule và thao tác form.
  - OUTPUT: giao diện thiết lập lịch đăng; chưa phát side effect API.
  =====================================================================
-->
<script setup>
import { ref } from 'vue'
import GeneralTab from '@/views/marketing/post-schedule/GeneralTab.vue'
import ScheduleTab from '@/views/marketing/post-schedule/ScheduleTab.vue'
import ToneTab from '@/views/marketing/post-schedule/ToneTab.vue'
import ImageStyleTab from '@/views/marketing/post-schedule/ImageStyleTab.vue'
import PlatformsTab from '@/views/marketing/post-schedule/PlatformsTab.vue'
import AdvancedTab from '@/views/marketing/post-schedule/AdvancedTab.vue'

definePage({ meta: { navActiveLink: 'marketing-post-schedule' } })

const activeTab = ref('general')

// Danh sách tab ánh xạ VTab ↔ VWindowItem ↔ component tương ứng.
const tabs = [
  { value: 'general', title: 'Cài đặt chung', component: GeneralTab },
  { value: 'schedule', title: 'Lịch đăng', component: ScheduleTab },
  { value: 'tone', title: 'Văn phong AI', component: ToneTab },
  { value: 'image-style', title: 'Phong cách hình ảnh', component: ImageStyleTab },
  { value: 'platforms', title: 'Nền tảng', component: PlatformsTab },
  { value: 'advanced', title: 'Nâng cao', component: AdvancedTab },
]

// 4 thẻ KPI đầu trang; số liệu, trend và sparkline đều là dữ liệu mẫu.
const kpiStats = [
  {
    title: 'Số bài tạo mỗi ngày',
    unit: 'bài/ngày',
    value: 3,
    trend: '25%',
    isUp: true,
    color: 'primary',
    icon: 'tabler-calendar-check',
    areaPath: 'M2 26 Q 22 18, 42 22 T 83 10 L 83 32 L 2 32 Z',
    linePath: '',
  },
  {
    title: 'Bài đã lên lịch',
    unit: 'bài',
    value: 12,
    trend: '',
    isUp: true,
    color: 'primary',
    icon: 'tabler-clock',
    areaPath: '',
    linePath: '',
    showMiniBars: true,
  },
  {
    title: 'Bài đã tạo (tuần này)',
    unit: 'bài',
    value: 18,
    trend: '12%',
    isUp: true,
    color: 'success',
    icon: 'tabler-file-description',
    areaPath: 'M2 26 Q 25 20, 48 16 T 83 8 L 83 32 L 2 32 Z',
    linePath: 'M2 26 Q 25 20, 48 16 T 83 8',
  },
  {
    title: 'Bài đã đăng',
    unit: 'bài',
    value: 15,
    trend: '36%',
    isUp: true,
    color: 'info',
    icon: 'tabler-circle-check',
    areaPath: 'M2 28 Q 20 14, 42 20 T 70 10 T 83 6 L 83 32 L 2 32 Z',
    linePath: 'M2 28 Q 20 14, 42 20 T 70 10 T 83 6',
  },
]

/**
 * =====================================================================
 * CHỨC NĂNG: Xử lý lưu thiết lập tạo bài đăng
 * =====================================================================
 * INPUT: state form hiện tại.
 * OUTPUT: tạm console.log; chưa gọi API lưu vì backend chưa có endpoint.
 * SIDE EFFECT: không có ngoài console.log.
 * =====================================================================
 */
const saveConfiguration = () => {
  console.log('Đã lưu thiết lập tạo bài đăng!')
}
</script>

<template>
  <div class="post-schedule-page">
    <!-- ================= 1. HEADER TRANG ================= -->
    <div class="d-flex flex-wrap justify-start justify-sm-space-between gap-y-4 gap-x-6 mb-6">
      <div class="d-flex flex-column justify-center">
        <h4 class="text-h4 font-weight-medium">
          Lịch Đăng Bài
        </h4>
        <div class="text-body-1">
          Thiết lập số lượng, lịch đăng, văn phong AI và phong cách hình ảnh cho từng nền tảng
        </div>
      </div>

      <div class="d-flex gap-4 align-center flex-wrap">
        <VBtn
          prepend-icon="tabler-check"
          @click="saveConfiguration"
        >
          Lưu thiết lập
        </VBtn>
      </div>
    </div>

    <!-- ================= 2. 4 THẺ THỐNG KÊ (KPI CARDS) ================= -->
    <VRow class="mb-6">
      <VCol
        v-for="stat in kpiStats"
        :key="stat.title"
        cols="12"
        sm="6"
        lg="3"
      >
        <VCard class="pa-4 position-relative overflow-hidden h-100">
          <div class="d-flex align-center">
            <VAvatar
              :color="stat.color"
              rounded="lg"
              size="46"
              variant="tonal"
              class="me-3"
            >
              <VIcon
                :icon="stat.icon"
                :color="stat.color"
                size="24"
              />
            </VAvatar>
            <div>
              <div class="text-body-2 text-medium-emphasis font-weight-medium mb-1">
                {{ stat.title }}
              </div>
              <div class="d-flex align-baseline gap-1">
                <span class="text-h4 font-weight-bold text-high-emphasis">{{ stat.value }}</span>
                <span class="text-caption text-medium-emphasis">{{ stat.unit }}</span>
                <span
                  v-if="stat.trend"
                  class="text-caption font-weight-bold ms-1"
                  :class="stat.isUp ? 'text-success' : 'text-error'"
                >
                  {{ stat.isUp ? '↑' : '↓' }} {{ stat.trend }}
                </span>
              </div>
            </div>
          </div>

          <!-- Sparkline SVG góc dưới phải -->
          <svg
            v-if="stat.areaPath || stat.linePath"
            class="sparkline-svg"
            :class="`text-${stat.color}`"
            viewBox="0 0 85 32"
            fill="none"
          >
            <path
              v-if="stat.areaPath"
              :d="stat.areaPath"
              fill="currentColor"
              fill-opacity="0.12"
            />
            <path
              v-if="stat.linePath"
              :d="stat.linePath"
              stroke="currentColor"
              stroke-width="2"
              fill="none"
              stroke-linecap="round"
            />
          </svg>

          <!-- Biểu đồ cột mini cho thẻ "Bài đã lên lịch" -->
          <div
            v-if="stat.showMiniBars"
            class="d-flex align-end gap-1 mini-bars"
          >
            <div
              class="mini-bar"
              style="block-size: 30%;"
            />
            <div
              class="mini-bar"
              style="block-size: 55%;"
            />
            <div
              class="mini-bar"
              style="block-size: 40%;"
            />
            <div
              class="mini-bar"
              style="block-size: 75%;"
            />
            <div
              class="mini-bar"
              style="block-size: 45%;"
            />
            <div
              class="mini-bar mini-bar--active"
              style="block-size: 100%;"
            />
            <div
              class="mini-bar"
              style="block-size: 60%;"
            />
          </div>
        </VCard>
      </VCol>
    </VRow>

    <!-- ================= 3. KHỐI THIẾT LẬP TẠO BÀI ĐĂNG ================= -->
    <VCard>
      <VCardItem>
        <template #title>
          Thiết lập tạo bài đăng
        </template>
      </VCardItem>

      <!-- Thanh Tabs điều hướng -->
      <VTabs
        v-model="activeTab"
        class="px-4"
        color="primary"
      >
        <VTab
          v-for="tab in tabs"
          :key="tab.value"
          :value="tab.value"
        >
          {{ tab.title }}
        </VTab>
      </VTabs>

      <VDivider />

      <VCardText>
        <VWindow
          v-model="activeTab"
          :touch="false"
        >
          <VWindowItem
            v-for="tab in tabs"
            :key="tab.value"
            :value="tab.value"
          >
            <component :is="tab.component" />
          </VWindowItem>
        </VWindow>
      </VCardText>
    </VCard>
  </div>
</template>

<style lang="scss" scoped>
.post-schedule-page {
  min-inline-size: 0;
}

/* Sparkline góc dưới phải các thẻ KPI */
.sparkline-svg {
  position: absolute;
  inset-block-end: 0;
  inset-inline-end: 12px;
  inline-size: 85px;
  block-size: 32px;
  pointer-events: none;
}

/* Biểu đồ cột mini */
.mini-bars {
  block-size: 34px;
}
.mini-bar {
  inline-size: 4px;
  background-color: rgba(var(--v-theme-primary), 0.2);
  border-radius: 2px;
}
.mini-bar--active {
  background-color: rgb(var(--v-theme-primary));
}
</style>
