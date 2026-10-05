<!--
  =====================================================================
  CHỨC NĂNG FILE: UserUpgradePlanDialog dùng khung dialog chung.
  CÁC HÀM/METHOD TRONG FILE: dialogModelValueUpdate.
  INPUT/OUTPUT CỦA CLASS (tổng thể): props/model và thao tác UI -> sự kiện của
  caller; header/footer cố định, content cuộn qua AppDialogLayout.
  =====================================================================
-->
<script setup>
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'

const props = defineProps({
  isDialogVisible: {
    type: Boolean,
    required: true,
  },
})

const emit = defineEmits(['update:isDialogVisible'])

const selectedPlan = ref('standard')

const plansList = [
  {
    desc: 'Standard - $99/month',
    title: 'Standard',
    value: 'standard',
  },
  {
    desc: 'Basic - $0/month',
    title: 'Basic',
    value: 'basic',
  },
  {
    desc: 'Enterprise - $499/month',
    title: 'Enterprise',
    value: 'enterprice',
  },
  {
    desc: 'Company - $999/month',
    title: 'Company',
    value: 'company',
  },
]

const isConfirmDialogVisible = ref(false)

const dialogModelValueUpdate = val => {
  emit('update:isDialogVisible', val)
}
</script>

<template>
  <!-- 👉 upgrade plan -->
  <VDialog
    scrollable
    :width="$vuetify.display.smAndDown ? 'auto' : 650"
    :model-value="props.isDialogVisible"
    @update:model-value="dialogModelValueUpdate"
  >
    <AppDialogLayout @close="dialogModelValueUpdate(false)">
      <template #header>
        <VCardItem>
          <VCardTitle>Upgrade Plan</VCardTitle>
          <VCardSubtitle>Choose the best plan for user.</VCardSubtitle>
        </VCardItem>
      </template>

      <VCardText>
        <div class="d-flex justify-space-between flex-column flex-sm-row gap-4">
          <AppSelect
            v-model="selectedPlan"
            :items="plansList"
            label="Choose a plan"
            placeholder="Basic"
          />
        </div>

        <VDivider class="my-6" />

        <p class="text-body-1 mb-1">
          User current plan is standard plan
        </p>
        <div class="d-flex justify-space-between align-center flex-wrap">
          <div class="d-flex align-center gap-1 me-3">
            <sup class="text-body-1 text-primary">$</sup>
            <h1 class="text-h1 text-primary">
              99
            </h1>
            <sub class="text-body-2 mt-5">
              / month
            </sub>
          </div>
        </div>
      </VCardText>

      <!-- 👉 Confirm Dialog -->
      <ConfirmDialog
        v-model:is-dialog-visible="isConfirmDialogVisible"
        cancel-title="Cancelled"
        confirm-title="Unsubscribed!"
        confirm-msg="Your subscription cancelled successfully."
        confirmation-question="Are you sure to cancel your subscription?"
        cancel-msg="Unsubscription Cancelled!!"
      />
      <template #footer>
        <VCardActions class="justify-end">
          <VBtn variant="flat">
            Upgrade
          </VBtn>
          <VBtn
            color="error"
            variant="tonal"
            @click="isConfirmDialogVisible = true"
          >
            Cancel Subscription
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>
