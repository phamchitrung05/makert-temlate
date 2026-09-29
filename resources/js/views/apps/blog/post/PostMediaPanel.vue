<!--
  =====================================================================
  CHỨC NĂNG FILE: Chọn thumbnail và content images cho Post
  =====================================================================

  Component giữ phần media của giao diện Post mới và tái sử dụng MediaAssetField
  để mọi thao tác chọn/upload tuân theo contract Media Library hiện có.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - Không có; dữ liệu đi qua hai v-model rõ ràng

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : v-model thumbnail và contentImages
  - OUTPUT: cập nhật asset đã chọn cho PostForm
  =====================================================================
-->
<script setup>
const thumbnail = defineModel('thumbnail', { type: Object, default: null })
const contentImages = defineModel('contentImages', { type: Array, default: () => [] })
</script>

<template>
  <VCard class="mb-6">
    <VCardItem>
      <template #title>
        Featured Image
      </template>
    </VCardItem>

    <VCardText>
      <div class="media-picker-shell pa-4 rounded-lg">
        <MediaAssetField
          v-model="thumbnail"
          field="post.thumbnail"
          :multiple="false"
          visibility="public"
          label="Post thumbnail"
        />
      </div>
    </VCardText>
  </VCard>

  <VCard class="mb-6">
    <VCardItem>
      <template #title>
        Image Gallery
      </template>
      <template #subtitle>
        Optional
      </template>
    </VCardItem>

    <VCardText>
      <div class="media-picker-shell pa-4 rounded-lg">
        <MediaAssetField
          v-model="contentImages"
          field="post.content_images"
          multiple
          visibility="public"
          label="Post content images"
        />
      </div>
    </VCardText>
  </VCard>
</template>

<style scoped>
.media-picker-shell {
  border: 1.5px dashed rgba(var(--v-theme-primary), 0.35);
  background: rgba(var(--v-theme-primary), 0.035);
  transition: border-color 0.2s ease, background-color 0.2s ease;
}

.media-picker-shell:hover {
  border-color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.07);
}
</style>
