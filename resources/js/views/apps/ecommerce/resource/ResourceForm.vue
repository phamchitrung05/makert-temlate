<!--
  =====================================================================
  CHỨC NĂNG FILE: Cung cấp form tạo và chỉnh sửa Resource cho admin
  =====================================================================

  Component chỉ quản lý field, validation và phát payload lên page. Store/API
  nằm ở page container; nhờ vậy form dùng lại được cho cả Add và Edit mà không
  biết chi tiết endpoint hoặc cách lưu state server.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - createFormState(): tạo state form từ resource hiện tại
  - submit(): validate form và emit payload theo action draft/publish
  - watcher props.resource: đồng bộ detail được tải từ API vào form

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : resource detail, loading/saving và message từ page
  - OUTPUT: emit submit `{ action, payload }` hoặc discard; render form UI
  =====================================================================
-->
<script setup>
import { reactive, shallowRef, watch } from 'vue'

const props = defineProps({
  resource: {
    type: Object,
    default: null,
  },
  isEdit: {
    type: Boolean,
    default: false,
  },
  loading: {
    type: Boolean,
    default: false,
  },
  saving: {
    type: Boolean,
    default: false,
  },
  error: {
    type: String,
    default: '',
  },
  success: {
    type: String,
    default: '',
  },
})

const emit = defineEmits(['submit', 'discard'])
const refVForm = shallowRef()

const typeOptions = [
  { title: 'Template', value: 'template' },
  { title: 'UI kit', value: 'ui_kit' },
  { title: 'CSS', value: 'css' },
  { title: 'Component', value: 'component' },
  { title: 'Theme', value: 'theme' },
  { title: 'Snippet', value: 'snippet' },
  { title: 'Plugin', value: 'plugin' },
  { title: 'Icon pack', value: 'icon_pack' },
  { title: 'Illustration', value: 'illustration' },
  { title: 'Ebook', value: 'ebook' },
  { title: 'Course', value: 'course' },
  { title: 'Other', value: 'other' },
]

const statusOptions = [
  { title: 'Draft', value: 'draft' },
  { title: 'Pending review', value: 'pending_review' },
  { title: 'Published', value: 'published' },
]

const visibilityOptions = [
  { title: 'Public', value: 'public' },
  { title: 'Members', value: 'members' },
  { title: 'Private', value: 'private' },
]

/**
 * Tạo state form mới từ detail API hoặc giá trị mặc định khi Add.
 *
 * Input: resource detail đã normalize hoặc null.
 * Output: object form độc lập để người dùng chỉnh sửa.
 */
const createFormState = resource => ({
  title: resource?.title ?? '',
  code: resource?.code ?? '',
  type: resource?.type ?? 'template',
  status: resource?.status ?? 'draft',
  visibility: resource?.visibility ?? 'private',
  isFeatured: resource?.isFeatured ?? false,
  shortDescription: resource?.shortDescription ?? '',
  description: resource?.description ?? '',
  demoUrl: resource?.demoUrl ?? '',
  documentationUrl: resource?.documentationUrl ?? '',
})

const form = reactive(createFormState(props.resource))

/**
 * Đồng bộ resource detail mới tải từ API vào form mà không thay object reactive.
 *
 * Input: resource prop thay đổi sau request show.
 * Output: không trả dữ liệu; cập nhật các field form hiện tại.
 */
watch(() => props.resource, resource => {
  Object.assign(form, createFormState(resource))
})

/**
 * Validate và gửi payload lên page container.
 *
 * Input: action `draft` hoặc `publish` từ nút người dùng chọn.
 * Output: không trả dữ liệu; emit submit khi form hợp lệ.
 * Side effect: chạy validation của Vuetify VForm.
 */
const submit = async action => {
  const validation = await refVForm.value?.validate()

  if (!validation?.valid)
    return

  emit('submit', {
    action,
    payload: { ...form },
  })
}
</script>

<template>
  <div>
    <div class="d-flex flex-wrap justify-space-between gap-y-4 gap-x-6 mb-6">
      <div class="d-flex flex-column justify-center">
        <h4 class="text-h4 font-weight-medium">
          {{ props.isEdit ? 'Edit Resource' : 'Add Resource' }}
        </h4>
        <div class="text-body-1">
          {{ props.isEdit ? 'Cập nhật tài nguyên số trong catalog.' : 'Tạo tài nguyên số mới cho catalog.' }}
        </div>
      </div>

      <div class="d-flex gap-4 align-center flex-wrap">
        <VBtn
          variant="tonal"
          color="secondary"
          :disabled="props.saving"
          @click="emit('discard')"
        >
          Discard
        </VBtn>
        <VBtn
          variant="tonal"
          color="primary"
          :loading="props.saving"
          :disabled="props.loading"
          @click="submit('draft')"
        >
          Save Draft
        </VBtn>
        <VBtn
          :loading="props.saving"
          :disabled="props.loading"
          @click="submit('publish')"
        >
          Publish Resource
        </VBtn>
      </div>
    </div>

    <VAlert
      v-if="props.success"
      color="success"
      variant="tonal"
      class="mb-6"
    >
      {{ props.success }}
    </VAlert>
    <VAlert
      v-if="props.error"
      color="error"
      variant="tonal"
      class="mb-6"
    >
      {{ props.error }}
    </VAlert>
    <VProgressLinear
      v-if="props.loading"
      indeterminate
      color="primary"
      class="mb-6"
    />

    <VForm
      ref="refVForm"
      @submit.prevent="submit('draft')"
    >
      <VRow>
        <VCol
          cols="12"
          md="8"
        >
          <VCard
            title="Resource Information"
            class="mb-6"
          >
            <VCardText>
              <VRow>
                <VCol cols="12">
                  <AppTextField
                    v-model="form.title"
                    label="Title"
                    placeholder="Vue Admin Starter"
                    :rules="[requiredValidator]"
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <AppTextField
                    v-model="form.code"
                    label="Code"
                    placeholder="VUE-ADMIN-001"
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <AppSelect
                    v-model="form.type"
                    label="Type"
                    :items="typeOptions"
                    :rules="[requiredValidator]"
                  />
                </VCol>
                <VCol cols="12">
                  <AppTextField
                    v-model="form.shortDescription"
                    label="Short description"
                    placeholder="Mô tả ngắn cho danh sách catalog"
                  />
                </VCol>
                <VCol cols="12">
                  <AppTextarea
                    v-model="form.description"
                    label="Description"
                    placeholder="Mô tả chi tiết resource"
                    rows="6"
                  />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>

          <VCard
            title="Links"
            class="mb-6"
          >
            <VCardText>
              <VRow>
                <VCol
                  cols="12"
                  md="6"
                >
                  <AppTextField
                    v-model="form.demoUrl"
                    label="Demo URL"
                    placeholder="https://example.com/demo"
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <AppTextField
                    v-model="form.documentationUrl"
                    label="Documentation URL"
                    placeholder="https://example.com/docs"
                  />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>
        </VCol>

        <VCol
          cols="12"
          md="4"
        >
          <VCard
            title="Publishing"
            class="mb-6"
          >
            <VCardText>
              <div class="d-flex flex-column gap-y-5">
                <AppSelect
                  v-model="form.status"
                  label="Status"
                  :items="statusOptions"
                  :rules="[requiredValidator]"
                />
                <AppSelect
                  v-model="form.visibility"
                  label="Visibility"
                  :items="visibilityOptions"
                  :rules="[requiredValidator]"
                />
                <VCheckbox
                  v-model="form.isFeatured"
                  label="Featured resource"
                />
              </div>
            </VCardText>
          </VCard>

          <VCard title="Media">
            <VCardText>
              <DropZone />
              <p class="text-body-2 text-medium-emphasis mt-4 mb-0">
                Upload cover, preview và package sẽ được nối với Media Library ở bước tiếp theo.
              </p>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </VForm>
  </div>
</template>
