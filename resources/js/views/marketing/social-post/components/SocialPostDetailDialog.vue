<!--
  =====================================================================
  CHỨC NĂNG FILE: Dialog chỉnh caption và xem version Social Post.
  =====================================================================
  Dialog gọi detail/version API khi mở, cho phép sửa canonical caption; mọi
  platform vẫn đọc cùng field caption.

  CÁC HÀM/METHOD TRONG FILE:
  - load(): tải detail và versions.
  - save(): cập nhật caption qua API.
  - close(): đóng dialog.

  INPUT/OUTPUT CỦA COMPONENT:
  - INPUT : v-model, postId.
  - OUTPUT: emits update:modelValue, saved.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Detail fields follow the Laravel API contract. */
import { reactive, shallowRef, watch } from 'vue'
import { socialPostService } from '@/services/socialPost'

const props = defineProps({ modelValue: Boolean, postId: { type: [Number, String], default: null } })
const emit = defineEmits(['update:modelValue', 'saved'])
const detail = shallowRef(null)
const versions = shallowRef([])
const loading = shallowRef(false)
const saving = shallowRef(false)
const error = shallowRef('')
const form = reactive({ caption: '' })

/** Input: dialog/post ID. Output: detail/version state. */
async function load() {
  if (!props.modelValue || !props.postId) return
  loading.value = true
  error.value = ''
  try {
    detail.value = await socialPostService.show(props.postId)
    form.caption = detail.value?.caption ?? ''
    versions.value = await socialPostService.versions(props.postId) ?? []
  } catch (cause) {
    error.value = cause?.data?.message ?? cause?.message ?? 'Không tải được chi tiết Social Post.'
  } finally {
    loading.value = false
  }
}

/** Input: caption form. Output: saved event và reload. */
async function save() {
  saving.value = true
  error.value = ''
  try {
    detail.value = await socialPostService.update(props.postId, { caption: form.caption })
    versions.value = await socialPostService.versions(props.postId) ?? []
    emit('saved', detail.value)
  } catch (cause) {
    error.value = cause?.data?.message ?? cause?.message ?? 'Không lưu được caption.'
  } finally {
    saving.value = false
  }
}

/** Input: dialog close. Output: update:modelValue false. */
function close() { emit('update:modelValue', false) }

watch(() => [props.modelValue, props.postId], load, { immediate: true })
</script>

<template>
  <VDialog
    :model-value="modelValue"
    max-width="860"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <VCard>
      <VCardTitle class="d-flex align-center justify-space-between">
        Chi tiết Social Post <VBtn
          icon="tabler-x"
          variant="text"
          @click="close"
        />
      </VCardTitle>
      <VCardText>
        <VProgressLinear
          v-if="loading"
          indeterminate
          class="mb-4"
        />
        <VAlert
          v-if="error"
          type="error"
          variant="tonal"
          class="mb-4"
        >
          {{ error }}
        </VAlert>
        <VRow v-if="detail">
          <VCol
            cols="12"
            md="5"
          >
            <VImg
              v-if="detail.image?.file?.preview_url || detail.image?.file?.url"
              :src="detail.image.file.preview_url || detail.image.file.url"
              height="280"
              cover
              class="rounded-lg"
            />
            <div
              v-else
              class="detail-empty rounded-lg d-flex align-center justify-center text-medium-emphasis"
            >
              Chưa có ảnh
            </div>
            <div class="d-flex flex-wrap gap-2 mt-3">
              <VChip
                size="small"
                variant="tonal"
              >
                {{ detail.generationStatus }}
              </VChip><VChip
                size="small"
                variant="tonal"
              >
                {{ detail.reviewStatus }}
              </VChip>
            </div>
          </VCol>
          <VCol
            cols="12"
            md="7"
          >
            <VTextarea
              v-model="form.caption"
              label="Caption dùng chung"
              rows="8"
            />
            <div class="text-subtitle-2 mb-2">
              Version history
            </div>
            <VList
              density="compact"
              border
              rounded="lg"
              max-height="190"
            >
              <VListItem
                v-for="version in versions"
                :key="version.id"
                :title="`Version ${version.version_no}`"
                :subtitle="version.reason || 'AI/manual'"
              >
                <template #append>
                  <span class="text-caption text-medium-emphasis">{{ version.created_at }}</span>
                </template>
              </VListItem>
              <VListItem
                v-if="!versions.length"
                title="Chưa có version snapshot"
              />
            </VList>
          </VCol>
        </VRow>
      </VCardText>
      <VCardActions class="justify-end">
        <VBtn
          variant="text"
          @click="close"
        >
          Đóng
        </VBtn><VBtn
          color="primary"
          :loading="saving"
          @click="save"
        >
          Lưu caption
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>

<style scoped>
.detail-empty { min-height: 280px; background: rgba(var(--v-theme-on-surface), 0.04); }
</style>
