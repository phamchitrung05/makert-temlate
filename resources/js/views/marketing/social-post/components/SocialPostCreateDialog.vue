<!--
  =====================================================================
  CHỨC NĂNG FILE: Form tạo Social Post draft độc lập với Product.
  =====================================================================
  Form cho phép caption thủ công hoặc để AI tạo, chọn nhiều Page nhưng không
  tạo caption riêng theo platform.

  CÁC HÀM/METHOD TRONG FILE:
  - reset(): đưa form về giá trị mặc định.
  - submit(): validate local và emit payload.

  INPUT/OUTPUT CỦA COMPONENT:
  - INPUT : v-model open, campaigns/accounts options.
  - OUTPUT: emits update:modelValue, submit(payload).
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Form payload follows the Laravel Social Post contract. */
import { reactive, watch } from 'vue'

const props = defineProps({
  modelValue: Boolean,

  // Kept as a compatibility prop while existing callers migrate; never rendered or submitted.
  products: { type: Array, default: () => [] },
  campaigns: { type: Array, default: () => [] },
  accounts: { type: Array, default: () => [] },
})

const emit = defineEmits(['update:modelValue', 'submit'])
const form = reactive({ campaign_id: null, caption: '', target_account_ids: [], generate: true })

function reset() {
  Object.assign(form, { campaign_id: null, caption: '', target_account_ids: [], generate: true })
}

function submit() {
  emit('submit', { ...form, target_account_ids: [...form.target_account_ids] })
}

watch(() => props.modelValue, open => { if (open) reset() })
</script>

<template>
  <VDialog
    :model-value="modelValue"
    max-width="620"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <VCard>
      <VCardTitle class="d-flex align-center justify-space-between">
        Tạo Social Post draft
        <VBtn
          icon="tabler-x"
          variant="text"
          @click="emit('update:modelValue', false)"
        />
      </VCardTitle>
      <VCardText>
        <VAlert
          type="info"
          variant="tonal"
          class="mb-4"
        >
          Caption và ảnh sẽ được tạo bất đồng bộ. Bài chỉ nằm ở draft cho đến khi bạn duyệt.
        </VAlert>
        <VRow>
          <VCol
            cols="12"
            md="6"
          >
            <VSelect
              v-model="form.campaign_id"
              :items="campaigns"
              item-title="name"
              item-value="id"
              label="Campaign"
              clearable
            />
          </VCol>
          <VCol cols="12">
            <VTextarea
              v-model="form.caption"
              label="Caption (tuỳ chọn)"
              rows="4"
              hint="Để trống nếu muốn AI tạo caption."
              persistent-hint
            />
          </VCol>
          <VCol cols="12">
            <VSelect
              v-model="form.target_account_ids"
              :items="accounts"
              item-title="display_name"
              item-value="id"
              label="Facebook Pages"
              multiple
              chips
              clearable
            />
          </VCol>
          <VCol cols="12">
            <VSwitch
              v-model="form.generate"
              label="Xếp hàng tạo caption và ảnh bằng AI"
            />
          </VCol>
        </VRow>
      </VCardText>
      <VCardActions class="justify-end">
        <VBtn
          variant="text"
          @click="emit('update:modelValue', false)"
        >
          Huỷ
        </VBtn><VBtn
          color="primary"
          @click="submit"
        >
          Tạo draft
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
