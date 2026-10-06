<!-- Điều hướng Settings; input danh mục/dirty, output chọn tab, không mutate form. -->
<script setup>
defineProps({
  tabs: { type: Array, required: true },
  active: { type: String, required: true },
  dirty: { type: Array, default: () => [] },
})

const emit = defineEmits(['select'])
</script>

<template>
  <VCard border>
    <VCardText class="pa-3">
      <div class="d-md-none">
        <AppSelect
          id="settings-navigation"
          aria-label="Chọn nhóm cài đặt"
          :model-value="active"
          :items="tabs.map(tab => ({ title: tab.title + (dirty.includes(tab.id) ? ' • Chưa lưu' : ''), value: tab.id }))"
          label="Nhóm cài đặt"
          @update:model-value="emit('select', $event)"
        />
      </div>
      <VList
        class="d-none d-md-block pa-0"
        density="comfortable"
        nav
        aria-label="Nhóm cài đặt"
      >
        <VListItem
          v-for="tab in tabs"
          :key="tab.id"
          :active="active === tab.id"
          :aria-current="active === tab.id ? 'page' : undefined"
          color="primary"
          min-height="52"
          rounded
          class="mb-1 py-3"
          @click="emit('select', tab.id)"
        >
          <template #prepend>
            <VIcon
              :icon="tab.icon"
              size="22"
            />
          </template>
          <VListItemTitle class="text-body-1 font-weight-medium">
            {{ tab.title }}
          </VListItemTitle>
          <template #append>
            <VIcon
              v-if="dirty.includes(tab.id)"
              icon="tabler-point-filled"
              color="warning"
              size="16"
              title="Chưa lưu"
            />
          </template>
        </VListItem>
      </VList>
    </VCardText>
  </VCard>
</template>
