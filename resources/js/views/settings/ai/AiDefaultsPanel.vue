<script setup>
/* eslint-disable camelcase -- Settings payload uses Laravel field names. */
import { computed, ref, watch } from 'vue'

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chọn default/fallback model theo capability AI
 * =====================================================================
 * CÁC HÀM/METHOD: submit(), label(), textModels, imageModels.
 * INPUT: settings typed và model options đã được server lọc catalog.
 * OUTPUT: settings patch; model text và image không bị trộn.
 * SIDE EFFECT: chỉ emit save; không tự gọi API hoặc ghi database.
 * EXCEPTION/TRANSACTION: không mở transaction; API trả validation khi model stale.
 * =====================================================================
 */
const props = defineProps({ settings: { type: Object, default: () => ({}) }, models: { type: Array, default: () => [] }, saving: Boolean })
const emit = defineEmits(['save'])
const draft = ref({})

watch(() => props.settings, value => { draft.value = { ...value } }, { immediate: true, deep: true })

/**
 * =====================================================================
 * CHỨC NĂNG: Lọc model text có structured output
 * =====================================================================
 * INPUT: model options đã qua provider/catalog filter.
 * OUTPUT: model hỗ trợ text_generation và structured_output.
 * SIDE EFFECT: computed chỉ đọc props.
 * EXCEPTION/TRANSACTION: không mở transaction.
 * =====================================================================
 */
const textModels = computed(() => props.models.filter(model => model.capabilities?.includes('text_generation') && model.capabilities?.includes('structured_output')))

/**
 * =====================================================================
 * CHỨC NĂNG: Lọc model image generation
 * =====================================================================
 * INPUT: model options đã qua provider/catalog filter.
 * OUTPUT: model có image_generation capability.
 * SIDE EFFECT: computed chỉ đọc props.
 * EXCEPTION/TRANSACTION: không mở transaction.
 * =====================================================================
 */
const imageModels = computed(() => props.models.filter(model => model.capabilities?.includes('image_generation')))

/**
 * =====================================================================
 * CHỨC NĂNG: Tạo label provider/model cho select
 * =====================================================================
 * INPUT: model option có provider_label và label.
 * OUTPUT: chuỗi label dễ phân biệt giữa các provider.
 * SIDE EFFECT: hàm thuần, không gọi API.
 * EXCEPTION/TRANSACTION: không mở transaction.
 * =====================================================================
 */
const label = model => `${model.provider_label} · ${model.label}`

/**
 * =====================================================================
 * CHỨC NĂNG: Emit settings patch sau khi ép kiểu tuning
 * =====================================================================
 * INPUT: draft defaults/fallback và tuning từ form.
 * OUTPUT: event save cho page/composable.
 * SIDE EFFECT: emit bản copy; không gọi API hoặc ghi database trực tiếp.
 * EXCEPTION/TRANSACTION: backend validation xử lý model stale; không mở transaction.
 * =====================================================================
 */
const submit = () => emit('save', { ...draft.value, default_temperature: Number(draft.value.default_temperature), request_timeout: Number(draft.value.request_timeout) })
</script>

<template>
  <VCard>
    <VCardItem>
      <VCardTitle>Model mặc định</VCardTitle>
      <VCardSubtitle>Luồng bài viết dùng model nội dung; nút tạo ảnh dùng model ảnh riêng.</VCardSubtitle>
    </VCardItem>
    <VCardText>
      <VRow>
        <VCol
          cols="12"
          md="6"
        >
          <VSelect
            v-model="draft.default_text_model_id"
            :items="textModels"
            :item-title="label"
            item-value="id"
            clearable
            label="Model tạo nội dung mặc định"
          />
        </VCol>
        <VCol
          cols="12"
          md="6"
        >
          <VSelect
            v-model="draft.default_image_model_id"
            :items="imageModels"
            :item-title="label"
            item-value="id"
            clearable
            label="Model tạo ảnh mặc định"
          />
        </VCol>
        <VCol
          cols="12"
          md="6"
        >
          <VSelect
            v-model="draft.fallback_text_model_id"
            :items="textModels"
            :item-title="label"
            item-value="id"
            clearable
            label="Fallback nội dung"
          />
        </VCol>
        <VCol
          cols="12"
          md="6"
        >
          <VSelect
            v-model="draft.fallback_image_model_id"
            :items="imageModels"
            :item-title="label"
            item-value="id"
            clearable
            label="Fallback ảnh"
          />
        </VCol>
        <VCol
          cols="12"
          md="6"
        >
          <VTextField
            v-model="draft.default_temperature"
            type="number"
            min="0"
            max="2"
            step="0.1"
            label="Temperature"
          />
        </VCol>
        <VCol
          cols="12"
          md="6"
        >
          <VTextField
            v-model="draft.request_timeout"
            type="number"
            min="5"
            max="120"
            label="Timeout (giây)"
          />
        </VCol>
      </VRow>
      <VBtn
        color="primary"
        :loading="saving"
        @click="submit"
      >
        Lưu mặc định
      </VBtn>
    </VCardText>
  </VCard>
</template>
