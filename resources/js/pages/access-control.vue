<!--
  =====================================================================
  CHỨC NĂNG FILE: Thông báo trạng thái tạm hoãn authorization UI bằng CASL
  =====================================================================

  Trang giữ lại route demo access control để tránh làm hỏng liên kết sẵn có trong
  template. Trang không gọi CASL; authorization thực tế vẫn do Laravel kiểm soát.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - Không có hàm, computed hoặc watcher cục bộ.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : Không có
  - OUTPUT: thông báo CASL sẽ được triển khai trong giai đoạn sau
  =====================================================================
-->
<template>
  <VRow>
    <VCol cols="12">
      <VCard title="Access control">
        <VCardText>
          CASL đang tạm hoãn và chưa tham gia vào router, menu hoặc render giao diện.
        </VCardText>
        <VCardText>
          Laravel vẫn là nơi bắt buộc kiểm tra authentication và authorization.
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
</template>
