<!--
  =====================================================================
  CHỨC NĂNG FILE: Tab "Nâng cao" của trang Lịch Đăng Bài (post-schedule).
  =====================================================================

  Panel con render bởi index.vue trong VWindowItem value="advanced".
  Hiện là khung chờ để bổ sung các cài đặt nâng cao sau.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - Không có.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : không có props.
  - OUTPUT: UI khung chờ; sẽ thay bằng nội dung nâng cao khi triển khai.
  =====================================================================
-->


<template>
  <div class="py-6 text-center text-medium-emphasis">
    <VIcon
      icon="tabler-settings-cog"
      size="40"
      class="mb-2"
    />
    <div class="text-body-1">
      Nội dung thiết lập nâng cao sẽ được bổ sung tại đây.
    </div>
  </div>
</template>
