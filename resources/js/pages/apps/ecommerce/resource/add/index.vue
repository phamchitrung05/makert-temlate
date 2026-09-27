<!--
  =====================================================================
  CHỨC NĂNG FILE: Điều phối form tạo/chỉnh sửa Resource trong admin
  =====================================================================

  Page đọc resource id từ query để phân biệt Add/Edit, tải detail qua Pinia
  store và điều phối mutation create/update/publish. UI field và validation
  được tách sang ResourceForm.vue để giữ page là composition surface.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - resourceId: lấy id resource từ route query
  - errorMessage: chuyển lỗi API thành message hiển thị được
  - handleSubmit(): lưu draft hoặc publish sau khi form emit payload
  - discard(): quay lại danh sách resource

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : route query resource và thao tác submit/discard từ ResourceForm
  - OUTPUT: Pinia mutations, trạng thái loading/error và navigation về list
  =====================================================================
-->
<script setup>
import { computed, shallowRef, watch } from 'vue'
import { storeToRefs } from 'pinia'
import ResourceForm from '@/views/apps/ecommerce/resource/ResourceForm.vue'
import { useResourceStore } from '@/stores/resource'

const route = useRoute()
const router = useRouter()
const resourceStore = useResourceStore()
const { currentResource, isLoading, isMutating, error } = storeToRefs(resourceStore)
const successMessage = shallowRef('')

const resourceId = computed(() => {
  const rawId = Number(route.query.resource)

  return Number.isInteger(rawId) && rawId > 0 ? rawId : null
})

const isEdit = computed(() => resourceId.value !== null)

/**
 * Chuyển lỗi ofetch/BaseResponse thành chuỗi để ResourceForm hiển thị.
 *
 * Input: error reactive từ Resource store.
 * Output: message tiếng Việt, ưu tiên lỗi field từ API.
 */
const errorMessage = computed(() => {
  const payload = error.value?.data ?? error.value?.response?._data ?? {}
  const fieldErrors = payload.errors ?? {}
  const messages = Object.values(fieldErrors).flat().filter(Boolean)

  return messages.join(' ') || payload.message || (error.value ? 'Không thể xử lý resource.' : '')
})

/**
 * Tải lại detail khi query resource thay đổi trong cùng route component.
 *
 * Input: id từ route query hoặc null khi chuyển về Add.
 * Output: không trả dữ liệu; đồng bộ currentResource trong Pinia store.
 */
const loadResource = async id => {
  resourceStore.clearCurrentResource()

  if (id)
    await resourceStore.fetchResource(id)
}

watch(resourceId, id => {
  void loadResource(id)
}, { immediate: true })

/**
 * Lưu draft hoặc publish resource theo payload do ResourceForm phát ra.
 *
 * Input: `{ action, payload }`, action là draft hoặc publish.
 * Output: không trả dữ liệu; điều hướng về list sau khi mutation thành công.
 * Side effect: gọi create/update và publish API qua Pinia store.
 * Exception: lỗi được giữ trong store để form hiển thị.
 */
const handleSubmit = async ({ action, payload }) => {
  successMessage.value = ''
  resourceStore.clearError()

  try {
    // Publish luôn bắt đầu từ draft để backend PublishResourceAction kiểm tra
    // đúng lifecycle; Save Draft cũng không được tự ý public resource.
    const draftPayload = { ...payload, status: 'draft' }

    const savedResource = resourceId.value
      ? await resourceStore.updateResource(resourceId.value, draftPayload)
      : await resourceStore.createResource(draftPayload)

    if (action === 'publish')
      await resourceStore.publishResource(savedResource.id)

    successMessage.value = action === 'publish'
      ? 'Resource đã được publish thành công.'
      : 'Resource đã được lưu nháp thành công.'

    await router.push({ name: 'apps-ecommerce-resource-list' })
  }
  catch {
    // Store đã giữ error; page chỉ cần để ResourceForm render message đó.
  }
}

/**
 * Quay về danh sách mà không thay đổi dữ liệu.
 *
 * Input: không có.
 * Output: Promise navigation tới route resource list.
 */
const discard = () => router.push({ name: 'apps-ecommerce-resource-list' })
</script>

<template>
  <ResourceForm
    :resource="currentResource"
    :is-edit="isEdit"
    :loading="isLoading"
    :saving="isMutating"
    :error="errorMessage"
    :success="successMessage"
    @submit="handleSubmit"
    @discard="discard"
  />
</template>
