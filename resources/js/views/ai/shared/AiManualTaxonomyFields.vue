<!--
  =====================================================================
  CHỨC NĂNG FILE: Chọn category/tag active thủ công cho candidate Post.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: load(), selectedOptions(), missingIds (computed), watcher active,
  onMounted(), onBeforeUnmount().
  INPUT/OUTPUT CỦA CLASS (tổng thể): categories/tags/disabled/active -> emit ID arrays;
  GET catalog qua service, không tự tạo tag hoặc tự chọn gợi ý legacy.
  =====================================================================
-->
<script setup>
import { computed, onBeforeUnmount, onMounted, shallowRef, watch } from 'vue'
import { aiArticleTaxonomyService } from '@/services/aiArticleTaxonomy'
import { formatAiError } from '@/utils/aiErrors'

const props = defineProps({ disabled: Boolean, active: { type: Boolean, default: true } })
const categories = defineModel('categories', { type: Array, default: () => [] })
const tags = defineModel('tags', { type: Array, default: () => [] })
const categoryItems = shallowRef([])
const tagItems = shallowRef([])
const loading = shallowRef(false)
const error = shallowRef('')
let sequence = 0
let controller

/**
 * =====================================================================
 * Input: items/IDs đang chọn. Output: options giữ ID đã mất để người dùng bỏ;
 * không xóa thầm các lựa chọn cũ nếu taxonomy bị tắt hoặc GET thất bại.
 * =====================================================================
 */
const selectedOptions = (items, ids) => [...items, ...ids.filter(id => !items.some(item => item.id === id)).map(id => ({ id, name: `#${id} · không còn trong danh sách active` }))]

const missingIds = computed(() => !loading.value && !error.value && (categories.value.some(id => !categoryItems.value.some(item => item.id === id))
  || tags.value.some(id => !tagItems.value.some(item => item.id === id))))

/**
 * =====================================================================
 * Input: mở feature/tải lại. Output: danh mục và tag đủ mọi trang hoặc lỗi;
 * SIDE EFFECT: GET độc lập, abort/guard ngăn phản hồi cũ; không đổi IDs đã chọn.
 * =====================================================================
 */
async function load() {
  controller?.abort()
  controller = new AbortController()

  const token = ++sequence

  loading.value = true
  error.value = ''
  try {
    const values = await Promise.all([aiArticleTaxonomyService.all('categories', controller.signal), aiArticleTaxonomyService.all('tags', controller.signal)])
    if (token !== sequence) return
    ;[categoryItems.value, tagItems.value] = values
  }
  catch (reason) { if (token === sequence) error.value = formatAiError(reason, 'Không tải được danh mục/tag. Các lựa chọn hiện tại vẫn được giữ.') }
  finally { if (token === sequence) loading.value = false }
}

// =====================================================================
// Input: vòng đời/open. Output: tải catalog khi cần, dừng GET khi unmount.
// =====================================================================
onMounted(() => { if (props.active) void load() })
watch(() => props.active, value => { if (value) void load() })
onBeforeUnmount(() => { sequence += 1; controller?.abort() })
</script>

<template>
  <div class="mb-4">
    <div class="d-flex align-center justify-space-between gap-2 mb-2">
      <div class="text-subtitle-2">
        Danh mục và tag · chọn thủ công
      </div>
      <VBtn
        icon="tabler-refresh"
        size="small"
        variant="text"
        aria-label="Tải lại danh mục và tag"
        :disabled="props.disabled || loading"
        @click="load"
      />
    </div>
    <VRow>
      <VCol
        cols="12"
        md="6"
      >
        <AppAutocomplete
          :model-value="categories"
          :items="selectedOptions(categoryItems, categories)"
          item-title="name"
          item-value="id"
          :return-object="false"
          label="Danh mục"
          multiple
          chips
          closable-chips
          clearable
          :disabled="props.disabled"
          :loading="loading"
          @update:model-value="categories = $event ?? []"
        />
      </VCol>
      <VCol
        cols="12"
        md="6"
      >
        <AppAutocomplete
          :model-value="tags"
          :items="selectedOptions(tagItems, tags)"
          item-title="name"
          item-value="id"
          :return-object="false"
          label="Tag"
          multiple
          chips
          closable-chips
          clearable
          :disabled="props.disabled"
          :loading="loading"
          @update:model-value="tags = $event ?? []"
        />
      </VCol>
    </VRow>
    <VAlert
      v-if="error || missingIds"
      type="warning"
      variant="tonal"
      class="mt-3"
    >
      {{ error || 'Có lựa chọn không còn active. Hãy bỏ hoặc chọn lại trước khi lưu.' }}
    </VAlert>
  </div>
</template>
