<script setup>
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Danh sách screen draft của Resource Template
 * =====================================================================
 * Hiển thị thứ tự ổn định, cho chọn entry và sửa title; việc lưu thuộc panel.
 */
import { computed } from 'vue'

const props = defineProps({
  screens: { type: Array, default: () => [] },
  entry: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:entry', 'update:screens', 'select'])

const sorted = computed(() => [...props.screens].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0)))

const move = (index, delta) => {
  const next = [...sorted.value]
  const target = index + delta
  if (target < 0 || target >= next.length)
    return
  ;[next[index], next[target]] = [next[target], next[index]]
  emit('update:screens', next.map((screen, sortOrder) => ({ ...screen, sort_order: sortOrder })))
}

const updateTitle = (screen, title) => {
  emit('update:screens', sorted.value.map(item => item.id === screen.id ? { ...item, title } : item))
}
</script>

<template>
  <VList lines="two" border rounded>
    <VListItem
      v-for="(screen, index) in sorted"
      :key="screen.id"
      :active="screen.slug === props.entry"
      @click="emit('select', screen)"
    >
      <template #prepend>
        <VBtn
          icon="tabler-grip-vertical"
          variant="text"
          size="small"
          :disabled="props.disabled"
          @click.stop
        />
      </template>
      <VTextField
        :model-value="screen.title"
        density="compact"
        variant="plain"
        hide-details
        :disabled="props.disabled"
        @update:model-value="updateTitle(screen, $event)"
      />
      <template #subtitle>
        <code>{{ screen.source_path }}</code>
      </template>
      <template #append>
        <VBtn
          icon="tabler-arrow-up"
          variant="text"
          size="small"
          :disabled="props.disabled || index === 0"
          @click.stop="move(index, -1)"
        />
        <VBtn
          icon="tabler-arrow-down"
          variant="text"
          size="small"
          :disabled="props.disabled || index === sorted.length - 1"
          @click.stop="move(index, 1)"
        />
        <VRadio
          :model-value="props.entry"
          :value="screen.slug"
          :disabled="props.disabled"
          @update:model-value="emit('update:entry', $event)"
        />
      </template>
    </VListItem>
    <VListItem v-if="!sorted.length" title="Chưa phát hiện screen" subtitle="Hãy xử lý package hoặc kiểm tra manifest." />
  </VList>
</template>
