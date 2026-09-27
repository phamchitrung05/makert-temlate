<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị một liên kết trong menu điều hướng ngang
  =====================================================================

  Component dựng RouterLink hoặc thẻ liên kết từ navigation item và đánh dấu mục
  đang active. CASL đang tạm hoãn nên component không lọc item theo quyền frontend.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - Không có hàm cục bộ; component dùng helper layout để tạo URL và trạng thái active.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : props item và isSubItem mô tả liên kết cùng vị trí trong menu
  - OUTPUT: một mục menu ngang có trạng thái active/disabled tương ứng
  =====================================================================
-->
<script setup>
import { layoutConfig } from '@layouts'
import {
  getComputedNavLinkToProp,
  getDynamicI18nProps,
  isNavLinkActive,
} from '@layouts/utils'

const props = defineProps({
  item: {
    type: null,
    required: true,
  },
  isSubItem: {
    type: Boolean,
    required: false,
    default: false,
  },
})
</script>

<template>
  <li
    class="nav-link"
    :class="[
      {
        'sub-item': props.isSubItem,
        'disabled': item.disable,
      },
    ]"
  >
    <Component
      :is="item.to ? 'RouterLink' : 'a'"
      v-bind="getComputedNavLinkToProp(item)"
      :class="{
        'router-link-active router-link-exact-active': isNavLinkActive(
          item,
          $router,
        ),
      }"
    >
      <Component
        :is="layoutConfig.app.iconRenderer || 'div'"
        class="nav-item-icon"
        v-bind="
          item.icon && typeof item.icon === 'object' && item.icon !== null
            ? item.icon
            : layoutConfig.verticalNav.defaultNavItemIconProps || {}
        "
      />
      <Component
        :is="layoutConfig.app.i18n.enable ? 'i18n-t' : 'span'"
        class="nav-item-title"
        v-bind="getDynamicI18nProps(item.title, 'span')"
      >
        {{ item.title }}
      </Component>
    </Component>
  </li>
</template>

<style lang="scss">
.layout-horizontal-nav {
  .nav-link a {
    display: flex;
    align-items: center;
  }
}
</style>
