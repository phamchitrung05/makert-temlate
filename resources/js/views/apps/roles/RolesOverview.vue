<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị thống kê Roles theo card tiêu chuẩn Vuexy.
  =====================================================================
  Component trình bày số liệu được page tổng hợp; không tự gọi API.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - Không có; render các thống kê từ props.items.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : items gồm title, value, description, icon và color theo theme.
  - OUTPUT: bốn card responsive, avatar tonal và typography chung của project.
  =====================================================================
-->
<script setup>
defineProps({
  items: { type: Array, required: true },
})
</script>

<template>
  <VRow>
    <VCol
      v-for="item in items"
      :key="item.title"
      cols="12"
      sm="6"
      lg="3"
    >
      <VCard class="h-100">
        <VCardText class="d-flex justify-space-between align-start gap-4">
          <div class="d-flex flex-column gap-1">
            <span class="text-body-1">{{ item.title }}</span>
            <h4 class="text-h4 font-weight-medium">
              {{ item.value }}
            </h4>
            <span class="text-body-2 text-medium-emphasis">{{ item.description }}</span>
          </div>
          <VAvatar
            :color="item.color"
            variant="tonal"
            rounded
            size="42"
          >
            <VIcon
              :icon="item.icon"
              size="26"
            />
          </VAvatar>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
</template>
