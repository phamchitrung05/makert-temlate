<!--
  =====================================================================
  CHỨC NĂNG FILE: Route danh sách mẫu văn phong đã lưu trong Ai Prompt.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE:
  - definePage(): khai báo quyền quản lý cấu hình AI cho trang List.
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : truy cập /admin/ai/prompt/list.
  - OUTPUT: render AiPromptList; component/composable quản lý bảng và API.
  =====================================================================
-->
<script setup>
import AiPromptList from '@/views/ai/prompt/AiPromptList.vue'

// =====================================================================
// Input: route List. Output: metadata quyền quản lý mẫu văn phong.
// =====================================================================
definePage({ meta: { action: 'manage', subject: 'ai_settings' } })
</script>

<template>
  <AiPromptList />
</template>
