<!--
  =====================================================================
  CHỨC NĂNG FILE: UserInfoEditDialog dùng khung dialog chung.
  CÁC HÀM/METHOD TRONG FILE: onFormSubmit, onFormReset, dialogModelValueUpdate.
  INPUT/OUTPUT CỦA CLASS (tổng thể): props/model và thao tác UI -> sự kiện của
  caller; header/footer cố định, content cuộn qua AppDialogLayout.
  =====================================================================
-->
<script setup>
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import { useId } from 'vue'

const props = defineProps({
  userData: {
    type: Object,
    required: false,
    default: () => ({
      id: 0,
      fullName: '',
      company: '',
      role: '',
      username: '',
      country: '',
      contact: '',
      email: '',
      currentPlan: '',
      status: '',
      avatar: '',
      taskDone: null,
      projectDone: null,
      taxId: '',
      language: '',
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
const userData = ref(structuredClone(toRaw(props.userData)))
const isUseAsBillingAddress = ref(false)

watch(() => props, () => {
  userData.value = structuredClone(toRaw(props.userData))
})

const onFormSubmit = () => {
  emit('update:isDialogVisible', false)
  emit('submit', userData.value)
}

const onFormReset = () => {
  userData.value = structuredClone(toRaw(props.userData))
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
          <VCardTitle>Edit User Information</VCardTitle>
          <VCardSubtitle>Updating user details will receive a privacy audit.</VCardSubtitle>
        </VCardItem>
      </template>

      <VCardText>
        <!-- 👉 Form -->
        <VForm
          :id="dialogFormId"
          class="mt-6"
          @submit.prevent="onFormSubmit"
        >
          <VRow>
            <!-- 👉 First Name -->
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="userData.fullName.split(' ')[0]"
                label="First Name"
                placeholder="John"
              />
            </VCol>

            <!-- 👉 Last Name -->
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="userData.fullName.split(' ')[1]"
                label="Last Name"
                placeholder="Doe"
              />
            </VCol>

            <!-- 👉 Username -->
            <VCol cols="12">
              <AppTextField
                v-model="userData.username"
                label="Username"
                placeholder="john.doe.007"
              />
            </VCol>

            <!-- 👉 Billing Email -->
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="userData.email"
                label="Email"
                placeholder="johndoe@email.com"
              />
            </VCol>

            <!-- 👉 Status -->
            <VCol
              cols="12"
              md="6"
            >
              <AppSelect
                v-model="userData.status"
                label="Status"
                placeholder="Active"
                :items="['Active', 'Inactive', 'Pending']"
              />
            </VCol>

            <!-- 👉 Tax Id -->
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="userData.taxId"
                label="Tax ID"
                placeholder="123456789"
              />
            </VCol>

            <!-- 👉 Contact -->
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="userData.contact"
                label="Phone Number"
                placeholder="+1 9876543210"
              />
            </VCol>

            <!-- 👉 Language -->
            <VCol
              cols="12"
              md="6"
            >
              <AppSelect
                v-model="userData.language"
                closable-chips
                chips
                multiple
                label="Language"
                placeholder="English"
                :items="['English', 'Spanish', 'French']"
              />
            </VCol>

            <!-- 👉 Country -->
            <VCol
              cols="12"
              md="6"
            >
              <AppSelect
                v-model="userData.country"
                label="Country"
                placeholder="United States"
                :items="['United States', 'United Kingdom', 'France']"
              />
            </VCol>

            <!-- 👉 Switch -->
            <VCol cols="12">
              <VSwitch
                v-model="isUseAsBillingAddress"
                density="compact"
                label="Use as a billing address?"
              />
            </VCol>

            <!-- 👉 Submit and Cancel -->
          </VRow>
        </VForm>
      </VCardText>
      <template #footer>
        <VCardActions class="justify-end">
          <VBtn
            color="secondary"
            variant="tonal"
            @click="onFormReset"
          >
            Cancel
          </VBtn>
          <VBtn
            variant="flat"
            :form="dialogFormId"
            type="submit"
          >
            Submit
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>
