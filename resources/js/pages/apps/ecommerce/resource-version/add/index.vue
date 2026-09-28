<script setup>
import { computed, shallowRef, watch } from 'vue'
import { storeToRefs } from 'pinia'
import ResourceVersionForm from '@/views/apps/ecommerce/resource-version/ResourceVersionForm.vue'
import { useResourceVersionStore } from '@/stores/resourceVersion'

const route = useRoute()
const router = useRouter()
const store = useResourceVersionStore()
const { current, isLoading, isMutating, error } = storeToRefs(store)
const resourceId = computed(() => Number(route.query.resource) || 0)
const versionId = computed(() => Number(route.query.version) || null)
const errorMessage = computed(() => error.value?.data?.message || error.value?.response?._data?.message || error.value?.message || '')

watch(versionId, id => {
  store.clearCurrent()
  if (id)
    void store.fetchVersion(id)
}, { immediate: true })

const handleSubmit = async payload => {
  if (payload.invalidRequirements) {
    return
  }

  try {
    if (versionId.value)
      await store.updateVersion(versionId.value, payload)
    else
      await store.createVersion(payload)

    await router.push({ name: 'apps-ecommerce-resource-version-list', query: { resource: String(resourceId.value) } })
  }
  catch {}
}

const handleReady = async () => {
  if (!versionId.value)
    return

  try {
    await store.readyVersion(versionId.value)
    await router.push({ name: 'apps-ecommerce-resource-version-list', query: { resource: String(resourceId.value) } })
  }
  catch {}
}
</script>

<template>
  <ResourceVersionForm
    :version="current"
    :resource-id="resourceId"
    :loading="isLoading"
    :saving="isMutating"
    :error="errorMessage"
    @submit="handleSubmit"
    @ready="handleReady"
    @discard="router.push({ name: 'apps-ecommerce-resource-version-list' })"
  />
</template>
