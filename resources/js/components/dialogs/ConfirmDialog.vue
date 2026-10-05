<!--
  =====================================================================
  CHỨC NĂNG FILE: ConfirmDialog dùng khung dialog chung.
  CÁC HÀM/METHOD TRONG FILE: updateModelValue, onConfirmation, onCancel.
  INPUT/OUTPUT CỦA CLASS (tổng thể): props/model và thao tác UI -> sự kiện của
  caller; header/footer cố định, content cuộn qua AppDialogLayout.
  =====================================================================
-->
<script setup>
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'

const props = defineProps({
  confirmationQuestion: {
    type: String,
    required: true,
  },
  isDialogVisible: {
    type: Boolean,
    required: true,
  },
  confirmTitle: {
    type: String,
    required: true,
  },
  confirmMsg: {
    type: String,
    required: true,
  },
  cancelTitle: {
    type: String,
    required: true,
  },
  cancelMsg: {
    type: String,
    required: true,
  },
})

const emit = defineEmits([
  'update:isDialogVisible',
  'confirm',
])

const unsubscribed = ref(false)
const cancelled = ref(false)

const updateModelValue = val => {
  emit('update:isDialogVisible', val)
}

const onConfirmation = () => {
  emit('confirm', true)
  updateModelValue(false)
  unsubscribed.value = true
}

const onCancel = () => {
  emit('confirm', false)
  emit('update:isDialogVisible', false)
  cancelled.value = true
}
</script>

<template>
  <!-- 👉 Confirm Dialog -->
  <VDialog
    scrollable
    max-width="500"
    :model-value="props.isDialogVisible"
    @update:model-value="updateModelValue"
  >
    <AppDialogLayout
      title="Confirm action"
      @close="updateModelValue(false)"
    >
      <VCardText>
        <VBtn
          icon
          variant="outlined"
          color="warning"
          class="my-4"
          style=" block-size: 88px;inline-size: 88px; pointer-events: none;"
        >
          <span class="text-5xl">!</span>
        </VBtn>

        <h6 class="text-lg font-weight-medium">
          {{ props.confirmationQuestion }}
        </h6>
      </VCardText>

      <template #footer>
        <VCardActions class="justify-end">
          <VBtn
            color="secondary"
            variant="tonal"
            @click="onCancel"
          >
            Cancel
          </VBtn>
          <VBtn
            variant="elevated"
            @click="onConfirmation"
          >
            Confirm
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>

  <!-- Unsubscribed -->
  <VDialog
    v-model="unsubscribed"
    scrollable
    max-width="500"
  >
    <AppDialogLayout @close="unsubscribed = false">
      <template #header>
        <VCardItem>
          <VCardTitle>{{ props.confirmTitle }}</VCardTitle>
          <VCardSubtitle>{{ props.confirmMsg }}</VCardSubtitle>
        </VCardItem>
      </template>

      <VCardText class="text-center px-10 py-6">
        <VBtn
          icon
          variant="outlined"
          color="success"
          class="my-4"
          style=" block-size: 88px;inline-size: 88px; pointer-events: none;"
        >
          <VIcon
            icon="tabler-check"
            size="38"
          />
        </VBtn>
      </VCardText>
      <template #footer>
        <VCardActions class="justify-end">
          <VBtn
            variant="flat"
            color="success"
            @click="unsubscribed = false"
          >
            Ok
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>

  <!-- Cancelled -->
  <VDialog
    v-model="cancelled"
    scrollable
    max-width="500"
  >
    <AppDialogLayout @close="cancelled = false">
      <template #header>
        <VCardItem>
          <VCardTitle>{{ props.cancelTitle }}</VCardTitle>
          <VCardSubtitle>{{ props.cancelMsg }}</VCardSubtitle>
        </VCardItem>
      </template>

      <VCardText class="text-center px-10 py-6">
        <VBtn
          icon
          variant="outlined"
          color="error"
          class="my-4"
          style=" block-size: 88px;inline-size: 88px; pointer-events: none;"
        >
          <span class="text-5xl font-weight-light">X</span>
        </VBtn>
      </VCardText>
      <template #footer>
        <VCardActions class="justify-end">
          <VBtn
            variant="flat"
            color="success"
            @click="cancelled = false"
          >
            Ok
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>
