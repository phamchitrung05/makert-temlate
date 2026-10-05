<!--
  =====================================================================
  CHỨC NĂNG FILE: PricingPlanDialog dùng khung dialog chung.
  CÁC HÀM/METHOD TRONG FILE: dialogVisibleUpdate.
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

const dialogVisibleUpdate = val => {
  emit('update:isDialogVisible', val)
}
</script>

<template>
  <VDialog
    scrollable
    :model-value="props.isDialogVisible"
    :width="$vuetify.display.smAndDown ? 'auto' : 1200"
    @update:model-value="dialogVisibleUpdate"
  >
    <AppDialogLayout
      class="pricing-dialog"
      title="Pricing Plans"
      subtitle="Choose the best plan to fit your needs."
      @close="$emit('update:isDialogVisible', false)"
    >
      <VCardText>
        <AppPricing
          md="4"
          :show-header="false"
        />
      </VCardText>
    </AppDialogLayout>
  </VDialog>
</template>
