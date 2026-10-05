<!--
  =====================================================================
  CHỨC NĂNG FILE: EnableOneTimePasswordDialog dùng khung dialog chung.
  CÁC HÀM/METHOD TRONG FILE: formSubmit, resetPhoneNumber, dialogModelValueUpdate.
  INPUT/OUTPUT CỦA CLASS (tổng thể): props/model và thao tác UI -> sự kiện của
  caller; header/footer cố định, content cuộn qua AppDialogLayout.
  =====================================================================
-->
<script setup>
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import { useId } from 'vue'

const props = defineProps({
  mobileNumber: {
    type: String,
    required: false,
  },
  isDialogVisible: {
    type: Boolean,
    required: true,
  },
})

const emit = defineEmits([
  'update:isDialogVisible',
  'submit',
])

const dialogFormId = useId()
const phoneNumber = ref(structuredClone(toRaw(props.mobileNumber)))

const formSubmit = () => {
  if (phoneNumber.value) {
    emit('submit', phoneNumber.value)
    emit('update:isDialogVisible', false)
  }
}

const resetPhoneNumber = () => {
  phoneNumber.value = structuredClone(toRaw(props.mobileNumber))
  emit('update:isDialogVisible', false)
}

const dialogModelValueUpdate = val => {
  emit('update:isDialogVisible', val)
}
</script>

<template>
  <VDialog
    scrollable
    :width="$vuetify.display.smAndDown ? 'auto' : 900"
    :model-value="props.isDialogVisible"
    @update:model-value="dialogModelValueUpdate"
  >
    <AppDialogLayout @close="dialogModelValueUpdate(false)">
      <template #header>
        <VCardItem>
          <VCardTitle>Verify Your Mobile Number for SMS</VCardTitle>
          <VCardSubtitle>Enter your mobile phone number with country code and  we will send you a verification code.</VCardSubtitle>
        </VCardItem>
      </template>

      <VCardText>
        <VForm
          :id="dialogFormId"
          @submit.prevent="formSubmit"
        >
          <AppTextField
            v-model="phoneNumber"
            name="mobile"
            label="Phone Number"
            placeholder="+1 123 456 7890"
            type="number"
            class="mb-6"
          />
        </VForm>
      </VCardText>
      <template #footer>
        <VCardActions class="justify-end">
          <VBtn
            color="secondary"
            variant="tonal"
            @click="resetPhoneNumber"
          >
            Cancel
          </VBtn>
          <VBtn
            variant="flat"
            :form="dialogFormId"
            type="submit"
          >
            continue
            <VIcon
              end
              icon="tabler-arrow-right"
              class="flip-in-rtl"
            />
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>
