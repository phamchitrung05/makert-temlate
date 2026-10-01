<!--
  =====================================================================
  CHỨC NĂNG FILE: Nhập metadata SEO riêng, không thay đổi title/excerpt.
  CÁC HÀM/METHOD TRONG FILE:
  - update(): phát bản sao metadata cho field text/boolean.
  - updateOgImage(): cập nhật object/ID ảnh Open Graph từ Media picker.
  INPUT/OUTPUT CỦA CLASS (tổng thể): modelValue/title/excerpt -> update:modelValue.
  =====================================================================
-->
<script setup>
import MediaAssetField from '@/views/apps/media/field/MediaAssetField.vue'

const props = defineProps({
  modelValue: { type: Object, required: true },
  title: { type: String, default: '' },
  excerpt: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue'])

/** Input: key/value từ field. Output: object mới qua emit, không mutate props. */
const update = (key, value) => emit('update:modelValue', { ...props.modelValue, [key]: value })

/** Input: MediaAsset/null từ picker. Output: SEO state mới gồm object và ID. */
const updateOgImage = asset => emit('update:modelValue', {
  ...props.modelValue,
  ogImage: asset || null,
  ogImageId: asset?.id ?? null,
})
</script>

<template>
  <VCard
    title="SEO Settings"
    class="mb-6"
  >
    <VCardText class="d-flex flex-column ga-4">
      <AppTextField
        :model-value="props.modelValue.focusKeyword"
        label="Focus Keyword"
        maxlength="255"
        @update:model-value="update('focusKeyword', $event)"
      />
      <AppTextField
        :model-value="props.modelValue.title"
        label="Meta Title"
        :placeholder="props.title || 'Để trống dùng Title'"
        counter="60"
        maxlength="255"
        hint="Để trống dùng Title; đề xuất 30–60 ký tự."
        persistent-hint
        @update:model-value="update('title', $event)"
      />
      <AppTextarea
        :model-value="props.modelValue.description"
        label="Meta Description"
        :placeholder="props.excerpt || 'Để trống dùng Excerpt'"
        counter="160"
        maxlength="5000"
        rows="3"
        hint="Để trống dùng Excerpt; đề xuất 120–160 ký tự."
        persistent-hint
        @update:model-value="update('description', $event)"
      />
      <VExpansionPanels variant="accordion">
        <VExpansionPanel title="Nâng cao & chia sẻ">
          <VExpansionPanelText>
            <AppTextField
              :model-value="props.modelValue.canonicalUrl"
              label="Canonical URL"
              placeholder="Để trống dùng permalink"
              class="mb-4"
              @update:model-value="update('canonicalUrl', $event)"
            />
            <VSwitch
              :model-value="props.modelValue.robotsIndex"
              label="Cho phép index bài published"
              @update:model-value="update('robotsIndex', $event)"
            />
            <VSwitch
              :model-value="props.modelValue.robotsFollow"
              label="Cho phép follow liên kết"
              @update:model-value="update('robotsFollow', $event)"
            />
            <VAlert
              v-if="!props.modelValue.robotsIndex"
              type="warning"
              variant="tonal"
              class="mb-4"
            >
              Noindex: bài viết sẽ yêu cầu không xuất hiện trong kết quả tìm kiếm khi tích hợp trang public.
            </VAlert>
            <VAlert
              v-if="props.modelValue.canonicalUrl"
              type="info"
              variant="tonal"
              class="mb-4"
            >
              Chỉ dùng canonical tùy chỉnh nếu URL này thực sự là bản chuẩn.
            </VAlert>
            <AppTextField
              :model-value="props.modelValue.ogTitle"
              label="Social Title"
              maxlength="255"
              placeholder="Mặc định dùng tiêu đề SEO"
              class="mb-4"
              @update:model-value="update('ogTitle', $event)"
            />
            <AppTextarea
              :model-value="props.modelValue.ogDescription"
              label="Social Description"
              maxlength="5000"
              placeholder="Mặc định dùng mô tả SEO"
              @update:model-value="update('ogDescription', $event)"
            />
            <MediaAssetField
              :model-value="props.modelValue.ogImage"
              field="post.og_image"
              :multiple="false"
              visibility="public"
              label="Open Graph Image"
              @update:model-value="updateOgImage"
            />
            <p class="text-caption mt-3 mb-0">
              Ảnh Open Graph được lưu riêng; nếu bỏ trống backend sẽ dùng Featured Image làm fallback.
            </p>
          </VExpansionPanelText>
        </VExpansionPanel>
      </VExpansionPanels>
    </VCardText>
  </VCard>
</template>
