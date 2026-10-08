<!--
  =====================================================================
  CHỨC NĂNG FILE: Trang chỉnh sửa văn phong nháp sinh từ worker Ai Prompt.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE:
  - definePage(): khai báo quyền quản lý cấu hình AI cho trang Edit.
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : truy cập /admin/ai/prompt/edit?analysis=<UUID>&profile=<ID> hoặc ?profile=<ID> từ List.
  - OUTPUT: render form phân tích/duyệt giống Add và lưu profile nháp.
  =====================================================================
-->
<script setup>
import AiPromptCreate from '@/views/ai/prompt/AiPromptCreate.vue'

// =====================================================================
// Input: route Edit. Output: metadata quyền quản lý mẫu văn phong.
// =====================================================================
definePage({ meta: { action: 'manage', subject: 'ai_settings' } })
</script>

<template>
  <AiPromptCreate mode="edit" />
</template>
