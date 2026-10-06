<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị logo Settings tại các vị trí logo của theme.
  CÁC HÀM/METHOD TRONG FILE: logoUrl (computed).
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : branding chung và slot logo mặc định của theme.
  - OUTPUT: ảnh logo đã lưu; khi tải ảnh lỗi dùng slot mặc định.
  =====================================================================
-->
<script setup>
import { computed, shallowRef } from 'vue'
import { useSiteBranding } from '@/composables/useSiteBranding'

const branding = useSiteBranding()
const failedUrl = shallowRef(null)
const logoUrl = computed(() => branding.value.logo_url !== failedUrl.value ? branding.value.logo_url : null)
</script>

<template>
  <img
    v-if="logoUrl"
    :src="logoUrl"
    alt=""
    class="app-brand-logo"
    @error="failedUrl = logoUrl"
  >
  <slot v-else />
</template>

<style scoped>
.app-brand-logo {
  display: block;
  block-size: 32px;
  max-inline-size: 120px;
  object-fit: contain;
}
</style>
