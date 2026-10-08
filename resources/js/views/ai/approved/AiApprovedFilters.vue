<!--
  =====================================================================
  CHỨC NĂNG FILE: Panel lọc riêng cho kho bài AI đã được duyệt.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: datePickerConfig, dateRangeError.
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : search/createdFrom/createdTo từ trang AI Approved.
  - OUTPUT: model filter; không gọi API hoặc thay đổi archive.
  =====================================================================
-->
<script setup>
import { computed } from 'vue'

const search = defineModel('search', { type: String, default: '' })
const createdFrom = defineModel('createdFrom', { type: String, default: '' })
const createdTo = defineModel('createdTo', { type: String, default: '' })

const datePickerConfig = { dateFormat: 'Y-m-d', allowInput: true }

const dateRangeError = computed(() => createdFrom.value && createdTo.value && createdFrom.value > createdTo.value
  ? ['Ngày kết thúc phải lớn hơn hoặc bằng ngày bắt đầu.'] : [])
</script>

<template>
  <VCard class="mb-6">
    <VCardText>
      <div class="d-flex align-center gap-2 mb-4">
        <VIcon
          icon="tabler-filter"
          color="primary"
        />
        <span class="text-subtitle-1 font-weight-medium">Bộ lọc kho AI</span>
      </div>
      <VRow>
        <VCol
          cols="12"
          md="6"
          lg="4"
        >
          <AppTextField
            v-model="search"
            label="Tìm bài viết"
            placeholder="Nhập tiêu đề bài viết"
            prepend-inner-icon="tabler-search"
            maxlength="160"
            clearable
          />
        </VCol>
        <VCol
          cols="12"
          md="3"
        >
          <AppDateTimePicker
            v-model="createdFrom"
            label="Từ ngày lưu kho"
            placeholder="Chọn ngày bắt đầu"
            :config="datePickerConfig"
            clearable
          />
        </VCol>
        <VCol
          cols="12"
          md="3"
        >
          <AppDateTimePicker
            v-model="createdTo"
            label="Đến ngày lưu kho"
            placeholder="Chọn ngày kết thúc"
            :config="datePickerConfig"
            :error-messages="dateRangeError"
            clearable
          />
        </VCol>
      </VRow>
    </VCardText>
  </VCard>
</template>
