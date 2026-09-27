<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị một liên kết trong menu điều hướng dọc
  =====================================================================

  Component nhận cấu hình navigation item và dựng RouterLink hoặc thẻ liên kết
  phù hợp. CASL đang tạm hoãn nên component không lọc item theo quyền frontend.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - Không có hàm cục bộ; component dùng helper layout để tạo URL và trạng thái active.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : prop item chứa cấu hình liên kết, icon, badge và trạng thái disable
  - OUTPUT: một mục menu dọc có trạng thái active tương ứng với route hiện tại
  =====================================================================
-->
<script setup>
import { layoutConfig } from '@layouts'
import { useLayoutConfigStore } from '@layouts/stores/config'
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
})

const configStore = useLayoutConfigStore()
const hideTitleAndBadge = configStore.isVerticalNavMini()
</script>

<template>
  <li
    class="nav-link"
    :class="{ disabled: item.disable }"
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
        v-bind="
          item.icon && typeof item.icon === 'object' && item.icon !== null
            ? item.icon
            : layoutConfig.verticalNav.defaultNavItemIconProps || {}
        "
        class="nav-item-icon"
      />
      <TransitionGroup name="transition-slide-x">
        <!-- 👉 Title -->
        <Component
          :is="layoutConfig.app.i18n.enable ? 'i18n-t' : 'span'"
          v-show="!hideTitleAndBadge"
          key="title"
          class="nav-item-title"
          v-bind="getDynamicI18nProps(item.title, 'span')"
        >
          {{ item.title }}
        </Component>

        <!-- 👉 Badge -->
        <Component
          :is="layoutConfig.app.i18n.enable ? 'i18n-t' : 'span'"
          v-if="item.badgeContent"
          v-show="!hideTitleAndBadge"
          key="badge"
          class="nav-item-badge"
          :class="item.badgeClass"
          v-bind="getDynamicI18nProps(item.badgeContent, 'span')"
        >
          {{ item.badgeContent }}
        </Component>
      </TransitionGroup>
    </Component>
  </li>
</template>

<style lang="scss">
.layout-vertical-nav {
  .nav-link a {
    display: flex;
    align-items: center;
  }
}
</style>
