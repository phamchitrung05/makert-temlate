<!--
  =====================================================================
  CHỨC NĂNG FILE: Chuyển đường dẫn Ai Prompt cũ về trang danh sách văn phong.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE:
  - definePage(): khai báo redirect và quyền quản lý cấu hình AI.
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : truy cập route ai-prompt tại /admin/ai/prompt.
  - OUTPUT: chuyển tới ai-prompt-list; không tải catalog hoặc gọi model.
  =====================================================================
-->
<script setup>
import AiPromptList from '@/views/ai/prompt/AiPromptList.vue'

// =====================================================================
// Input: route Ai Prompt cũ. Output: redirect tới danh sách với cùng quyền AI.
// =====================================================================
definePage({ redirect: { name: 'ai-prompt-list' }, meta: { action: 'manage', subject: 'ai_settings' } })
</script>

<template>
  <AiPromptList />
</template>
