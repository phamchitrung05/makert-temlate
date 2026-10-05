<!--
  =====================================================================
  CHỨC NĂNG FILE: Bộ lọc tên của danh sách mẫu văn phong Ai Prompt.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE:
  - defineModel(search): nối ô nhập/clear với query của component cha.
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : search từ AiPromptList.
  - OUTPUT: update:search theo thao tác nhập; không gọi API.
  =====================================================================
-->
<script setup>
const search = defineModel('search', { type: String, default: '' })
</script>

<template>
  <VRow>
    <VCol
      cols="12"
      md="6"
      lg="4"
    >
      <AppTextField
        v-model="search"
        label="Tìm văn phong"
        placeholder="Nhập tên văn phong"
        prepend-inner-icon="tabler-search"
        maxlength="160"
        clearable
      />
    </VCol>
  </VRow>
</template>
