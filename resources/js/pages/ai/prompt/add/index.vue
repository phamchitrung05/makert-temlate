<!--
  =====================================================================
  CHỨC NĂNG FILE: Route phân tích bài tham khảo và thêm mẫu văn phong Ai Prompt.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE:
  - definePage(): khai báo quyền quản lý cấu hình AI cho trang Add.
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : truy cập /admin/ai/prompt/add.
  - OUTPUT: render AiPromptCreate với luồng phân tích/duyệt/lưu hiện có.
  =====================================================================
-->
<script setup>
import AiPromptCreate from '@/views/ai/prompt/AiPromptCreate.vue'

// =====================================================================
// Input: route Add. Output: metadata quyền quản lý mẫu văn phong.
// =====================================================================
definePage({ meta: { action: 'manage', subject: 'ai_settings' } })
</script>

<template>
  <AiPromptCreate />
</template>
