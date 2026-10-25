<!--
  =====================================================================
  CHỨC NĂNG FILE: Tab "Phong cách hình ảnh" của trang Lịch Đăng Bài (post-schedule).
  =====================================================================

  Panel con render bởi index.vue trong VWindowItem value="image-style".
  Lưới chọn phong cách hình ảnh AI dùng khi tạo ảnh minh họa cho bài đăng.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - Không có hàm nghiệp vụ; chỉ state chọn style cục bộ.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : không có props; state giữ trong component.
  - OUTPUT: UI chọn phong cách ảnh; chưa emit/persist vì chưa có API.
  =====================================================================
-->
<script setup>
import { ref } from 'vue'

const activeVisualStyle = ref('realistic')

const visualStyles = [
  { id: 'realistic', label: 'Ảnh thật', image: 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=200' },
  { id: 'cinematic', label: 'Cinematic', image: 'https://images.unsplash.com/photo-1518709268805-4e9042af9f23?w=200' },
  { id: '3d', label: '3D Illustration', image: 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=200' },
  { id: 'cartoon', label: 'Cartoon', image: 'https://images.unsplash.com/photo-1563089145-599997674d42?w=200' },
  { id: 'watercolor', label: 'Watercolor', image: 'https://images.unsplash.com/photo-1579783902614-a3fb3927b675?w=200' },
  { id: 'minimal', label: 'Minimal', image: 'https://images.unsplash.com/photo-1513519245088-0e12902e5a38?w=200' },
  { id: 'flat', label: 'Flat Design', image: 'https://images.unsplash.com/photo-1558655146-d09347e92766?w=200' },
  { id: 'vintage', label: 'Vintage', image: 'https://images.unsplash.com/photo-1509198397868-475647b2a1e5?w=200' },
  { id: 'cyberpunk', label: 'Cyberpunk', image: 'https://images.unsplash.com/photo-1515630278258-407f66498911?w=200' },
]
</script>

<template>
  <div>
    <div class="text-body-1 font-weight-medium text-high-emphasis mb-1">
      Prompt phong cách tạo ảnh
    </div>
    <div class="text-caption text-medium-emphasis mb-3">
      Chọn phong cách hình ảnh mà AI sẽ sử dụng khi tạo ảnh minh họa
    </div>

    <div class="style-grid">
      <div
        v-for="style in visualStyles"
        :key="style.id"
        class="text-center cursor-pointer"
        @click="activeVisualStyle = style.id"
      >
        <div
          class="style-thumb rounded overflow-hidden mb-1"
          :class="{ 'style-thumb--active': activeVisualStyle === style.id }"
        >
          <VImg
            :src="style.image"
            height="52"
            cover
            class="bg-surface-variant"
          />
        </div>
        <div
          class="text-caption text-truncate"
          :class="activeVisualStyle === style.id ? 'text-primary font-weight-bold' : 'text-medium-emphasis'"
        >
          {{ style.label }}
        </div>
      </div>

      <!-- Ô + Tùy chỉnh -->
      <div
        class="text-center cursor-pointer"
        @click="activeVisualStyle = 'custom'"
      >
        <div
          class="custom-style-btn rounded d-flex flex-column align-center justify-center mb-1"
          :class="{ 'style-thumb--active': activeVisualStyle === 'custom' }"
        >
          <VIcon
            icon="tabler-plus"
            color="primary"
            size="18"
          />
          <span class="text-primary font-weight-medium">
            Tùy chỉnh
          </span>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" scoped>
/* Lưới 5 cột cho Prompt phong cách tạo ảnh */
.style-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 0.5rem;
}
@media (max-width: 600px) {
  .style-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

.style-thumb {
  border: 2px solid transparent;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.style-thumb--active {
  border-color: rgb(var(--v-theme-primary));
  box-shadow: 0 2px 8px rgba(var(--v-theme-primary), 0.25);
}

.custom-style-btn {
  block-size: 52px;
  border: 1.5px dashed rgba(var(--v-theme-primary), 0.4);
  background-color: rgba(var(--v-theme-primary), 0.05);
  font-size: 0.625rem;
}
.custom-style-btn:hover {
  background-color: rgba(var(--v-theme-primary), 0.08);
  border-color: rgb(var(--v-theme-primary));
}
</style>
