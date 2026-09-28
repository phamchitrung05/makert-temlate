<script setup>
import { computed, reactive, shallowRef, watch } from 'vue'

const props = defineProps({
  version: { type: Object, default: null },
  resourceId: { type: [Number, String], required: true },
  loading: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
  error: { type: String, default: '' },
})

const emit = defineEmits(['submit', 'ready', 'discard'])
const formRef = shallowRef()
const requirementsError = shallowRef('')

const createFormState = version => ({
  version: version?.version ?? '',
  changelog: version?.changelog ?? '',
  requirements: version?.requirements ? JSON.stringify(version.requirements, null, 2) : '',
  package: version?.media?.package ?? null,
  documentation: Array.isArray(version?.media?.documentation) ? version.media.documentation : [],
  isDefault: version?.is_default ?? false,
})

const form = reactive(createFormState(props.version))

watch(() => props.version, value => Object.assign(form, createFormState(value)))

const packageScanStatus = computed(() => form.package?.file?.scan_status ?? null)

const canReady = computed(() => form.package?.kind === 'archive'
  && form.package?.visibility === 'private'
  && packageScanStatus.value === 'clean')

const submit = async () => {
  const validation = await formRef.value?.validate()

  if (!validation?.valid)
    return

  requirementsError.value = ''

  let requirements = null
  if (form.requirements.trim()) {
    try {
      requirements = JSON.parse(form.requirements)
    }
    catch {
      requirementsError.value = 'Requirements phải là JSON hợp lệ.'

      return
    }
  }

  emit('submit', {
    resourceId: Number(props.resourceId),
    version: form.version,
    changelog: form.changelog,
    requirements,
    package: form.package,
    documentation: form.documentation,
    isDefault: form.isDefault,
  })
}
</script>

<template>
  <div>
    <div class="d-flex flex-wrap justify-space-between gap-y-4 gap-x-6 mb-6">
      <div>
        <h4 class="text-h4 font-weight-medium">
          {{ props.version ? 'Edit Resource Version' : 'Add Resource Version' }}
        </h4>
        <div class="text-body-1">
          Quản lý package và tài liệu cho từng resource.
        </div>
      </div>
      <div class="d-flex gap-3">
        <VBtn
          variant="tonal"
          :disabled="props.saving"
          @click="emit('discard')"
        >
          Discard
        </VBtn>
        <VBtn
          :loading="props.saving"
          :disabled="props.loading"
          @click="submit"
        >
          Save Draft
        </VBtn>
        <VBtn
          color="success"
          :loading="props.saving"
          :disabled="!canReady || props.loading"
          @click="emit('ready')"
        >
          Mark Ready
        </VBtn>
      </div>
    </div>

    <VAlert
      v-if="props.error"
      color="error"
      variant="tonal"
      class="mb-5"
    >
      {{ props.error }}
    </VAlert>
    <VAlert
      v-if="requirementsError"
      color="error"
      variant="tonal"
      class="mb-5"
    >
      {{ requirementsError }}
    </VAlert>
    <VAlert
      v-if="form.package && !canReady"
      color="warning"
      variant="tonal"
      class="mb-5"
    >
      Package phải là archive private và scan ở trạng thái clean trước khi chuyển ready.
    </VAlert>

    <VForm
      ref="formRef"
      @submit.prevent="submit"
    >
      <VRow>
        <VCol
          cols="12"
          md="8"
        >
          <VCard title="Version information">
            <VCardText>
              <AppTextField
                v-model="form.version"
                label="Version"
                :rules="[requiredValidator]"
                class="mb-5"
              />
              <AppTextarea
                v-model="form.changelog"
                label="Changelog"
                rows="5"
                class="mb-5"
              />
              <AppTextarea
                v-model="form.requirements"
                label="Requirements JSON"
                rows="6"
              />
            </VCardText>
          </VCard>
        </VCol>
        <VCol
          cols="12"
          md="4"
        >
          <VCard
            title="Package"
            class="mb-6"
          >
            <VCardText>
              <MediaAssetField
                v-model="form.package"
                field="resource_version.package"
                :multiple="false"
                visibility="private"
                label="Package archive"
              />
            </VCardText>
          </VCard>
          <VCard
            title="Documentation"
            class="mb-6"
          >
            <VCardText>
              <MediaAssetField
                v-model="form.documentation"
                field="resource_version.documentation"
                multiple
                visibility="private"
                label="Documentation files"
              />
            </VCardText>
          </VCard>
          <VCheckbox
            v-model="form.isDefault"
            label="Default version"
          />
        </VCol>
      </VRow>
    </VForm>
  </div>
</template>
