<!--
  =====================================================================
  CHỨC NĂNG FILE: AddEditAddressDialog dùng khung dialog chung.
  CÁC HÀM/METHOD TRONG FILE: resetForm, onFormSubmit.
  INPUT/OUTPUT CỦA CLASS (tổng thể): props/model và thao tác UI -> sự kiện của
  caller; header/footer cố định, content cuộn qua AppDialogLayout.
  =====================================================================
-->
<script setup>
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import { useId } from 'vue'
import home from '@images/svg/home.svg'
import office from '@images/svg/office.svg'

const props = defineProps({
  billingAddress: {
    type: Object,
    required: false,
    default: () => ({
      firstName: '',
      lastName: '',
      selectedCountry: null,
      addressLine1: '',
      addressLine2: '',
      landmark: '',
      contact: '',
      country: null,
      city: '',
      state: '',
      zipCode: null,
    }),
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

const billingAddress = ref(structuredClone(toRaw(props.billingAddress)))

const resetForm = () => {
  emit('update:isDialogVisible', false)
  billingAddress.value = structuredClone(toRaw(props.billingAddress))
}

const onFormSubmit = () => {
  emit('update:isDialogVisible', false)
  emit('submit', billingAddress.value)
}

const selectedAddress = ref('Home')

const addressTypes = [
  {
    icon: {
      icon: home,
      size: '28',
    },
    title: 'Home',
    desc: 'Delivery Time (9am - 9pm)',
    value: 'Home',
  },
  {
    icon: {
      icon: office,
      size: '28',
    },
    title: 'Office',
    desc: 'Delivery Time (9am - 5pm)',
    value: 'Office',
  },
]
</script>

<template>
  <VDialog
    scrollable
    :width="$vuetify.display.smAndDown ? 'auto' : 900 "
    :model-value="props.isDialogVisible"
    @update:model-value="val => $emit('update:isDialogVisible', val)"
  >
    <AppDialogLayout
      v-if="props.billingAddress"

      @close="$emit('update:isDialogVisible', false)"
    >
      <template #header>
        <VCardItem>
          <VCardTitle>{{ (props.billingAddress.addressLine1 || props.billingAddress.addressLine2) ? 'Edit' : 'Add New' }} Address</VCardTitle>
          <VCardSubtitle>Add new address for express delivery</VCardSubtitle>
        </VCardItem>
      </template>

      <VCardText>
        <div class="d-flex mb-6">
          <CustomRadiosWithIcon
            v-model:selected-radio="selectedAddress"
            :radio-content="addressTypes"
            :grid-column="{ sm: '6', cols: '12' }"
          />
        </div>

        <!-- 👉 Form -->
        <VForm
          :id="dialogFormId"
          @submit.prevent="onFormSubmit"
        >
          <VRow>
            <!-- 👉 First Name -->
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="billingAddress.firstName"
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
                v-model="billingAddress.lastName"
                label="Last Name"
                placeholder="Doe"
              />
            </VCol>

            <!-- 👉 Select Country -->
            <VCol cols="12">
              <AppSelect
                v-model="billingAddress.selectedCountry"
                label="Select Country"
                placeholder="Select Country"
                :items="['USA', 'Aus', 'Canada', 'NZ']"
              />
            </VCol>

            <!-- 👉 Address Line 1 -->
            <VCol cols="12">
              <AppTextField
                v-model="billingAddress.addressLine1"
                label="Address Line 1"
                placeholder="12, Business Park"
              />
            </VCol>

            <!-- 👉 Address Line 2 -->
            <VCol cols="12">
              <AppTextField
                v-model="billingAddress.addressLine2"
                label="Address Line 2"
                placeholder="Mall Road"
              />
            </VCol>

            <!-- 👉 Landmark -->
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="billingAddress.landmark"
                label="Landmark"
                placeholder="Nr. Hard Rock Cafe"
              />
            </VCol>

            <!-- 👉 City -->
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="billingAddress.city"
                label="City"
                placeholder="Los Angeles"
              />
            </VCol>

            <!-- 👉 State -->
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="billingAddress.state"
                label="State"
                placeholder="California"
              />
            </VCol>

            <!-- 👉 Zip Code -->
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="billingAddress.zipCode"
                label="Zip Code"
                placeholder="99950"
                type="number"
              />
            </VCol>

            <VCol cols="12">
              <VSwitch label="Use as a billing address?" />
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
      <template #footer>
        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            @click="resetForm"
          >
            Cancel
          </VBtn>
          <VBtn
            variant="flat"
            :form="dialogFormId"
            type="submit"
            class="me-3"
          >
            submit
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>
