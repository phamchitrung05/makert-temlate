<!--
  =====================================================================
  CHỨC NĂNG FILE: Tab "Nền tảng" của trang Lịch Đăng Bài (post-schedule).
  =====================================================================

  Panel con render bởi index.vue trong VWindowItem value="platforms".
  Thiết lập giới hạn số bài đăng mỗi ngày cho từng nền tảng mạng xã hội.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - Không có hàm nghiệp vụ; chỉ state cục bộ quota từng nền tảng.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : không có props; state giữ trong component.
  - OUTPUT: UI giới hạn nền tảng; chưa emit/persist vì chưa có API.
  =====================================================================
-->
<script setup>
import { ref } from 'vue'

// Giới hạn số bài đăng mỗi ngày theo từng nền tảng.
const socialPlatforms = ref([
  { name: 'Facebook', icon: 'tabler-brand-facebook', color: 'white', bg: '#1877F2', quota: 1 },
  { name: 'Instagram', icon: 'tabler-brand-instagram', color: 'white', bg: '#E4405F', quota: 1 },
  { name: 'TikTok', icon: 'tabler-brand-tiktok', color: 'white', bg: '#111111', quota: 1 },
  { name: 'YouTube', icon: 'tabler-brand-youtube', color: 'white', bg: '#FF0000', quota: 1 },
])
</script>

<template>
  <div>
    <div class="text-body-1 font-weight-medium text-high-emphasis mb-3">
      Giới hạn đăng mỗi ngày (mỗi nền tảng)
    </div>

    <div class="d-flex flex-column gap-3">
      <div
        v-for="pf in socialPlatforms"
        :key="pf.name"
        class="d-flex align-center justify-space-between"
      >
        <div class="d-flex align-center">
          <VAvatar
            :color="pf.bg"
            size="28"
            rounded
            class="me-2"
          >
            <VIcon
              :icon="pf.icon"
              :color="pf.color"
              size="16"
            />
          </VAvatar>
          <span class="text-body-2 font-weight-medium text-high-emphasis">
            {{ pf.name }}
          </span>
        </div>

        <div class="d-flex align-center">
          <VAvatar
            :color="pf.bg"
            size="28"
            rounded
            class="me-2"
          >
            <VIcon
              :icon="pf.icon"
              :color="pf.color"
              size="16"
            />
          </VAvatar>
          <span class="text-body-1 font-weight-medium text-high-emphasis">
            {{ pf.name }}
          </span>
        </div>

        <div class="d-flex align-center gap-2">
          <div class="d-inline-flex align-center border rounded px-1 py-0-5">
            <VBtn
              icon="tabler-minus"
              variant="text"
              size="20"
              color="secondary"
              @click="pf.quota > 0 && pf.quota--"
            />
            <span class="px-3 text-body-2 font-weight-bold text-high-emphasis">
              {{ pf.quota }}
            </span>
            <VBtn
              icon="tabler-plus"
              variant="text"
              size="20"
              color="secondary"
              @click="pf.quota++"
            />
          </div>
          <span class="text-body-2 text-medium-emphasis">
            bài/ngày
          </span>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" scoped>
.py-0-5 { padding-block: 0.125rem; }
</style>
