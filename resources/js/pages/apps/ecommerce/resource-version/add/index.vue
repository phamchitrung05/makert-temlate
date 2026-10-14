<script setup>
import { computed, shallowRef, watch } from 'vue'
import { storeToRefs } from 'pinia'
import ResourceVersionForm from '@/views/apps/ecommerce/resource-version/ResourceVersionForm.vue'
import { useResourceVersionStore } from '@/stores/resourceVersion'
import { resourceService } from '@/services/resource'

const route = useRoute()
const router = useRouter()
const store = useResourceVersionStore()
const { current, isLoading, isMutating, error } = storeToRefs(store)
const resourceId = computed(() => Number(route.query.resource) || 0)
const versionId = computed(() => Number(route.query.version) || null)
const resourceType = shallowRef('')
const errorMessage = computed(() => error.value?.data?.message || error.value?.response?._data?.message || error.value?.message || '')

watch(versionId, id => {
  store.clearCurrent()
  if (id)
    void store.fetchVersion(id)
}, { immediate: true })

watch(resourceId, async id => {
  if (!id)
    return
  try {
    resourceType.value = (await resourceService.show(id))?.type || ''
  }
  catch {
    resourceType.value = ''
  }
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

const handleTemplateUpdated = async () => {
  if (versionId.value)
    await store.fetchVersion(versionId.value)
}
</script>

<template>
  <ResourceVersionForm
    :version="current"
    :resource-id="resourceId"
    :loading="isLoading"
    :saving="isMutating"
    :error="errorMessage"
    :resource-type="resourceType"
    @submit="handleSubmit"
    @ready="handleReady"
    @template-updated="handleTemplateUpdated"
    @discard="router.push({ name: 'apps-ecommerce-resource-version-list' })"
  />
</template>
