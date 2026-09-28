<script setup>
import { computed, shallowRef, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useResourceVersionStore } from '@/stores/resourceVersion'

const route = useRoute()
const router = useRouter()
const store = useResourceVersionStore()
const { items, totalItems, isLoading, error } = storeToRefs(store)
const page = shallowRef(1)
const itemsPerPage = shallowRef(15)
const resourceId = computed(() => Number(route.query.resource) || null)
const errorMessage = computed(() => error.value?.data?.message || error.value?.response?._data?.message || 'Không thể tải resource versions.')

const fetchItems = () => store.fetchVersions({
  'resource_id': resourceId.value || undefined,
  page: page.value,
  'per_page': itemsPerPage.value,
})

watch([page, itemsPerPage, resourceId], () => void fetchItems(), { immediate: true })
</script>

<template>
  <div>
    <div class="d-flex flex-wrap justify-space-between gap-y-4 mb-6">
      <div>
        <h4 class="text-h4 font-weight-medium">
          Resource Versions
        </h4>
        <div class="text-body-1">
          Package chỉ được ready sau khi security scan clean.
        </div>
      </div>
      <VBtn
        prepend-icon="tabler-plus"
        @click="router.push({ name: 'apps-ecommerce-resource-version-add', query: { resource: resourceId || undefined } })"
      >
        Add Version
      </VBtn>
    </div>
    <VCard>
      <VAlert
        v-if="error"
        color="error"
        variant="tonal"
        class="ma-4"
      >
        {{ errorMessage }}
      </VAlert>
      <VDataTableServer
        v-model:items-per-page="itemsPerPage"
        v-model:page="page"
        :items="items"
        :items-length="totalItems"
        :loading="isLoading"
        :headers="[
          { title: 'Version', key: 'version' },
          { title: 'Status', key: 'status' },
          { title: 'Package', key: 'media.package' },
          { title: 'Released', key: 'released_at' },
          { title: 'Actions', key: 'actions', sortable: false },
        ]"
      >
        <template #item.status="{ item }">
          <VChip
            size="small"
            :color="item.status === 'ready' ? 'success' : 'secondary'"
          >
            {{ item.status }}
          </VChip>
        </template>
        <template #item.media.package="{ item }">
          {{ item.media?.package?.title || '—' }}
        </template>
        <template #item.actions="{ item }">
          <VBtn
            icon="tabler-edit"
            variant="text"
            size="small"
            @click="router.push({ name: 'apps-ecommerce-resource-version-add', query: { version: item.id, resource: item.resource_id } })"
          />
        </template>
      </VDataTableServer>
    </VCard>
  </div>
</template>
