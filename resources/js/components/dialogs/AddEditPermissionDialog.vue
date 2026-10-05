<!--
  =====================================================================
  CHỨC NĂNG FILE: AddEditPermissionDialog dùng khung dialog chung.
  CÁC HÀM/METHOD TRONG FILE: onReset, onSubmit.
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
  permissionName: {
    type: String,
    required: false,
    default: '',
  },
})

const emit = defineEmits([
  'update:isDialogVisible',
  'update:permissionName',
])

const currentPermissionName = ref('')

const onReset = () => {
  emit('update:isDialogVisible', false)
  currentPermissionName.value = ''
}

const onSubmit = () => {
  emit('update:isDialogVisible', false)
  emit('update:permissionName', currentPermissionName.value)
}

watch(() => props, () => {
  currentPermissionName.value = props.permissionName
})
</script>

<template>
  <VDialog
    scrollable
    :width="$vuetify.display.smAndDown ? 'auto' : 600"
    :model-value="props.isDialogVisible"
    @update:model-value="onReset"
  >
    <AppDialogLayout @close="onReset">
      <template #header>
        <VCardItem>
          <VCardTitle>{{ props.permissionName ? 'Edit' : 'Add' }} Permission</VCardTitle>
          <VCardSubtitle>{{ props.permissionName ? 'Edit' : 'Add' }}  permission as per your requirements.</VCardSubtitle>
        </VCardItem>
      </template>

      <VCardText>
        <!-- 👉 Form -->
        <VForm>
          <VAlert
            type="warning"
            title="Warning!"
            variant="tonal"
            class="mb-6"
          >
            <template #text>
              By {{ props.permissionName ? 'editing' : 'adding' }} the permission name, you might break the system permissions functionality.
            </template>
          </VAlert>

          <!-- 👉 Role name -->
          <div class="d-flex gap-4 mb-6 flex-wrap flex-column flex-sm-row">
            <AppTextField
              v-model="currentPermissionName"
              placeholder="Enter Permission Name"
            />
          </div>

          <VCheckbox label="Set as core permission" />
        </VForm>
      </VCardText>
      <template #footer>
        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            @click="onReset"
          >
            Cancel
          </VBtn>
          <VBtn
            variant="flat"
            @click="onSubmit"
          >
            {{ props.permissionName ? 'Update' : 'Add' }}
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>

<style lang="scss">
.permission-table {
  td {
    border-block-end: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    padding-block: 0.5rem;
    padding-inline: 0;
  }
}
</style>
