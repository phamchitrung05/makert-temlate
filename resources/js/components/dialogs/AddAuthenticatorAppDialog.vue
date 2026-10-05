<!--
  =====================================================================
  CHỨC NĂNG FILE: AddAuthenticatorAppDialog dùng khung dialog chung.
  CÁC HÀM/METHOD TRONG FILE: formSubmit, resetAuthCode.
  INPUT/OUTPUT CỦA CLASS (tổng thể): props/model và thao tác UI -> sự kiện của
  caller; header/footer cố định, content cuộn qua AppDialogLayout.
  =====================================================================
-->
<script setup>
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import { useId } from 'vue'

const props = defineProps({
  authCode: {
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

import themeselectionQr from '@images/pages/themeselection-qr.png'

const authCode = ref(structuredClone(toRaw(props.authCode)))

const formSubmit = () => {
  if (authCode.value) {
    emit('submit', authCode.value)
    emit('update:isDialogVisible', false)
  }
}

const resetAuthCode = () => {
  authCode.value = structuredClone(toRaw(props.authCode))
  emit('update:isDialogVisible', false)
}
</script>

<template>
  <VDialog
    scrollable
    :width="$vuetify.display.smAndDown ? 'auto' : 900"
    :model-value="props.isDialogVisible"
    @update:model-value="(val) => $emit('update:isDialogVisible', val)"
  >
    <AppDialogLayout @close="$emit('update:isDialogVisible', false)">
      <template #header>
        <VCardItem>
          <VCardTitle>Add Authenticator App</VCardTitle>
        </VCardItem>
      </template>

      <VCardText>
        <h5 class="text-h5 mb-2">
          Authenticator Apps
        </h5>

        <p class="text-body-1 mb-6">
          Using an authenticator app like Google Authenticator, Microsoft Authenticator, Authy, or 1Password, scan the QR code. It will generate a 6 digit code for you to enter below.
        </p>

        <div class="mb-6">
          <VImg
            width="150"
            :src="themeselectionQr"
            class="mx-auto"
          />
        </div>

        <VAlert
          title="ASDLKNASDA9AHS678dGhASD78AB"
          text="If you are unable to scan the QR code, you can manually enter the secret key below."
          variant="tonal"
          color="warning"
        />
        <VForm
          :id="dialogFormId"
          @submit.prevent="formSubmit"
        >
          <AppTextField
            v-model="authCode"
            name="auth-code"
            label="Enter Authentication Code"
            placeholder="123 456"
            class="mt-4 mb-6"
          />
        </VForm>
      </VCardText>
      <template #footer>
        <VCardActions class="justify-end">
          <VBtn
            color="secondary"
            variant="tonal"
            @click="resetAuthCode"
          >
            Cancel
          </VBtn>
          <VBtn
            variant="flat"
            :form="dialogFormId"
            type="submit"
          >
            Continue
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
