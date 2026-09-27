<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị tiêu đề phân khu trong menu điều hướng dọc
  =====================================================================

  Component đổi giữa tiêu đề chữ và icon giữ chỗ theo trạng thái thu gọn của
  menu. CASL đang tạm hoãn nên mọi section được hiển thị theo cấu hình navigation.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - Không có hàm cục bộ; trạng thái hiển thị lấy từ layout config store.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : prop item chứa heading của section
  - OUTPUT: tiêu đề hoặc icon giữ chỗ của section trong menu dọc
  =====================================================================
-->
<script setup>
import { layoutConfig } from '@layouts'
import { useLayoutConfigStore } from '@layouts/stores/config'
import { getDynamicI18nProps } from '@layouts/utils'

const props = defineProps({
  item: {
    type: null,
    required: true,
  },
})

const configStore = useLayoutConfigStore()
const shallRenderIcon = configStore.isVerticalNavMini()
</script>

<template>
  <li class="nav-section-title">
    <div class="title-wrapper">
      <Transition
        name="vertical-nav-section-title"
        mode="out-in"
      >
        <Component
          :is="shallRenderIcon ? layoutConfig.app.iconRenderer : layoutConfig.app.i18n.enable ? 'i18n-t' : 'span'"
          :key="shallRenderIcon"
          :class="shallRenderIcon ? 'placeholder-icon' : 'title-text'"
          v-bind="{ ...layoutConfig.icons.sectionTitlePlaceholder, ...getDynamicI18nProps(item.heading, 'span') }"
        >
          {{ !shallRenderIcon ? item.heading : null }}
        </Component>
      </Transition>
    </div>
  </li>
</template>
