<!--
  CHỨC NĂNG FILE: Lựa chọn ảnh nguồn hoặc thumbnail AI/model ảnh riêng.
  HÀM: update() phát bản sao state; INPUT/OUTPUT: v-model/catalog -> options.
  SIDE EFFECT: emit lựa chọn, không gọi provider hoặc thay model nội dung.
-->
<script setup>
const props = defineProps({ catalog: { type: Object, default: () => ({}) }, disabled: Boolean })
const source = defineModel({ type: Object, required: true })

/** INPUT: field/value. OUTPUT: bản sao state cho parent; không mutate props. */
const update = (key, value) => { source.value = { ...source.value, [key]: value } }
</script>

<template>
  <div>
    <AppSelect
      :model-value="source.thumbnailMode || 'source'"
      :items="[{ title: 'Lấy ảnh từ URL nguồn', value: 'source' }, { title: 'Sinh ảnh bằng AI', value: 'generate' }]"
      label="Cách tạo ảnh đại diện"
      aria-label="Cách tạo ảnh đại diện"
      :disabled="props.disabled"
      @update:model-value="update('thumbnailMode', $event)"
    />
    <template v-if="source.thumbnailMode === 'generate'">
      <AppSelect
        :model-value="source.imageModelId"
        :items="props.catalog.imageModelOptions ?? []"
        label="Model tạo ảnh"
        aria-label="Model tạo ảnh"
        placeholder="Chọn model ảnh"
        :disabled="props.disabled || props.catalog.loading"
        :loading="props.catalog.loading"
        hint="Model ảnh được chọn riêng với model viết bài."
        persistent-hint
        class="mt-4"
        @update:model-value="update('imageModelId', $event)"
      />
      <VAlert
        v-if="!props.catalog.loading && !props.catalog.imageModelOptions?.length"
        type="warning"
        variant="tonal"
        class="mt-3"
      >
        Chưa có model hỗ trợ tạo ảnh. Bật model ảnh và cấu hình provider trong AI Settings.
      </VAlert>
      <AppTextarea
        :model-value="source.thumbnailPrompt"
        label="Yêu cầu tạo ảnh (tùy chọn)"
        placeholder="Để trống để tạo ảnh theo nội dung bài."
        maxlength="4000"
        rows="3"
        :disabled="props.disabled"
        class="mt-4"
        @update:model-value="update('thumbnailPrompt', $event)"
      />
    </template>
    <p
      v-else
      class="text-caption text-medium-emphasis mt-2 mb-0"
    >
      Ảnh nguồn chỉ dùng cho URL; nếu nguồn không có ảnh thì để trống.
    </p>
  </div>
</template>
