<script setup>
const props = defineProps({
  roleOptions: {
    type: Array,
    default: () => [],
  },
  statusOptions: {
    type: Array,
    default: () => [],
  },
  hasActiveFilters: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['reset'])

const search = defineModel('search', { type: String, default: '' })
const status = defineModel('status', { type: String, default: null })
const roleId = defineModel('roleId', { type: Number, default: null })
</script>

<template>
  <VCard
    title="Bộ lọc người dùng"
    class="mb-6"
  >
    <VCardText>
      <VRow>
        <VCol
          cols="12"
          md="4"
        >
          <AppTextField
            v-model="search"
            placeholder="Search users"
            clearable
          />
        </VCol>

        <VCol
          cols="12"
          md="4"
        >
          <AppSelect
            v-model="roleId"
            label="Role"
            :items="props.roleOptions"
            clearable
          />
        </VCol>

        <VCol
          cols="12"
          md="4"
        >
          <AppSelect
            v-model="status"
            label="Status"
            :items="props.statusOptions"
            clearable
          />
        </VCol>
      </VRow>

      <div
        v-if="props.hasActiveFilters"
        class="d-flex justify-end mt-2"
      >
        <VBtn
          variant="text"
          color="secondary"
          @click="emit('reset')"
        >
          Clear filters
        </VBtn>
      </div>
    </VCardText>
  </VCard>
</template>
