<!--
  =====================================================================
  CHỨC NĂNG FILE: CardAddEditDialog dùng khung dialog chung.
  CÁC HÀM/METHOD TRONG FILE: formSubmit, dialogModelValueUpdate.
  INPUT/OUTPUT CỦA CLASS (tổng thể): props/model và thao tác UI -> sự kiện của
  caller; header/footer cố định, content cuộn qua AppDialogLayout.
  =====================================================================
-->
<script setup>
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import { useId } from 'vue'

const props = defineProps({
  cardDetails: {
    type: Object,
    required: false,
    default: () => ({
      number: '',
      name: '',
      expiry: '',
      cvv: '',
      isPrimary: false,
      type: '',
    }),
  },
  isDialogVisible: {
    type: Boolean,
    required: true,
  },
})

const emit = defineEmits([
  'submit',
  'update:isDialogVisible',
])

const dialogFormId = useId()
const cardDetails = ref(structuredClone(toRaw(props.cardDetails)))

watch(() => props, () => {
  cardDetails.value = structuredClone(toRaw(props.cardDetails))
})

const formSubmit = () => {
  emit('submit', cardDetails.value)
}

const dialogModelValueUpdate = val => {
  emit('update:isDialogVisible', val)
}
</script>

<template>
  <VDialog
    scrollable
    :width="$vuetify.display.smAndDown ? 'auto' : 600"
    :model-value="props.isDialogVisible"
    @update:model-value="dialogModelValueUpdate"
  >
    <AppDialogLayout @close="dialogModelValueUpdate(false)">
      <template #header>
        <VCardItem class="text-center">
          <VCardTitle>
            <h4 class="text-h4 mb-2">
              {{ props.cardDetails.name ? 'Edit Card' : 'Add New Card' }}
            </h4>
          </VCardTitle>
          <p class="text-body-1 mb-0">
            {{ props.cardDetails.name ? 'Edit your saved card details' : 'Add card for future billing' }}
          </p>
        </VCardItem>
      </template>

      <VCardText class="pt-6">
        <VForm
          :id="dialogFormId"
          @submit.prevent="formSubmit"
        >
          <VRow>
            <!-- 👉 Card Number -->
            <VCol cols="12">
              <AppTextField
                v-model="cardDetails.number"
                label="Card Number"
                placeholder="1356 3215 6548 7898"
                type="number"
              />
            </VCol>

            <!-- 👉 Card Name -->
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="cardDetails.name"
                label="Name"
                placeholder="John Doe"
              />
            </VCol>

            <!-- 👉 Card Expiry -->
            <VCol
              cols="12"
              md="3"
            >
              <AppTextField
                v-model="cardDetails.expiry"
                label="Expiry Date"
                placeholder="MM/YY"
              />
            </VCol>

            <!-- 👉 Card CVV -->
            <VCol
              cols="12"
              md="3"
            >
              <AppTextField
                v-model="cardDetails.cvv"
                type="number"
                label="CVV Code"
                placeholder="654"
              />
            </VCol>

            <!-- 👉 Card Primary Set -->
            <VCol cols="12">
              <VSwitch
                v-model="cardDetails.isPrimary"
                label="Save Card for future billing?"
              />
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
      <template #footer>
        <VCardActions class="justify-end">
          <VBtn
            color="secondary"
            variant="tonal"
            @click="$emit('update:isDialogVisible', false)"
          >
            Cancel
          </VBtn>
          <VBtn
            variant="flat"
            :form="dialogFormId"
            class="me-4"
            type="submit"
          >
            Submit
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>
