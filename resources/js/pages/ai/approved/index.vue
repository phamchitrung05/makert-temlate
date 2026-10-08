<!--
  =====================================================================
  CHỨC NĂNG FILE: Route trang AI Approved lưu bài AI đã duyệt dài hạn.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: definePage().
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : route admin AI Approved.
  - OUTPUT: render AiApprovedList; API state nằm ở composable/component.
  =====================================================================
-->
<script setup>
import AiApprovedList from '@/views/ai/approved/AiApprovedList.vue'

// Input: route AI Approved. Output: metadata CASL hiển thị cho admin có quyền quản lý Post.
definePage({ meta: { action: 'manage', subject: 'posts' } })
</script>

<template>
  <AiApprovedList />
</template>
