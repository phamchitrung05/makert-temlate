<!--
  =====================================================================
  CHỨC NĂNG FILE: Dialog chỉnh caption và xem version Social Post.
  =====================================================================
  Dialog gọi detail/version API khi mở, cho phép sửa Facebook content blocks
  và xem gallery ảnh theo thứ tự.

  CÁC HÀM/METHOD TRONG FILE:
  - load(): tải detail và versions.
  - save(): cập nhật Facebook content qua API.
  - close(): đóng dialog.

  INPUT/OUTPUT CỦA COMPONENT:
  - INPUT : v-model, postId.
  - OUTPUT: emits update:modelValue, saved.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Detail fields follow the Laravel API contract. */
import { computed, onBeforeUnmount, reactive, shallowRef, watch } from 'vue'
import { socialPostService } from '@/services/socialPost'
import MediaAssetField from '@/views/apps/media/field/MediaAssetField.vue'
import { useMediaCapabilities } from '@/views/apps/media/field/useMediaCapabilities'

const props = defineProps({ modelValue: Boolean, postId: { type: [Number, String], default: null } })
const emit = defineEmits(['update:modelValue', 'saved'])
const detail = shallowRef(null)
const versions = shallowRef([])
const loading = shallowRef(false)
const saving = shallowRef(false)
const error = shallowRef('')
const galleryAssets = shallowRef([])
const { canAttach } = useMediaCapabilities()
let pollTimer
let loadVersion = 0
const activeStatuses = ['queued', 'running', 'processing', 'generating']
const generating = computed(() => (detail.value?.generation_jobs ?? []).some(job => activeStatuses.includes(job.status)))
const imageJobs = computed(() => (detail.value?.generation_jobs ?? []).filter(job => job.component === 'image'))

const form = reactive({
  title: '', body: '', bullet_points: '', cta: '', address: '', phone: '', website: '', hashtags: '', link_url: '',
})

function fillContent(content = {}) {
  form.title = content.title ?? ''
  form.body = content.body ?? ''
  form.bullet_points = (content.bullet_points ?? []).join('\n')
  form.cta = content.cta ?? ''
  form.address = content.contact?.address ?? ''
  form.phone = content.contact?.phone ?? ''
  form.website = content.contact?.website ?? ''
  form.hashtags = (content.hashtags ?? []).join(' ')
  form.link_url = content.link_url ?? ''
}

function fillGallery(images = []) {
  galleryAssets.value = images.map(image => image.asset).filter(Boolean)
}

/** Input: dialog/post ID. Output: detail/version state. */
async function load(poll = false) {
  clearTimeout(pollTimer)

  const token = ++loadVersion
  if (!props.modelValue || !props.postId) return
  loading.value = !poll
  error.value = ''
  try {
    const value = await socialPostService.show(props.postId)
    if (token !== loadVersion) return
    detail.value = value
    fillContent(detail.value?.facebook_variant?.content ?? {})
    fillGallery(detail.value?.facebook_variant?.images ?? [])

    const history = await socialPostService.versions(props.postId)
    if (token !== loadVersion) return
    versions.value = history ?? []
    if (token === loadVersion && generating.value) pollTimer = setTimeout(() => load(true), 2000)
  } catch (cause) {
    if (token === loadVersion) error.value = cause?.data?.message ?? cause?.message ?? 'Không tải được chi tiết Social Post.'
  } finally {
    if (token === loadVersion) loading.value = false
  }
}

async function regenerateSlot(slot) {
  saving.value = true
  error.value = ''
  try {
    await socialPostService.regenerateImageSlot(props.postId, slot)
    await load()
  } catch (cause) {
    error.value = cause?.data?.message ?? cause?.message ?? 'Không tạo lại được ảnh.'
  } finally {
    saving.value = false
  }
}

/** Input: Facebook content blocks. Output: saved event và reload. */
async function save() {
  saving.value = true
  error.value = ''
  try {
    detail.value = await socialPostService.updateFacebookContent(props.postId, {
      title: form.title || null,
      body: form.body,
      bullet_points: form.bullet_points.split('\n').map(item => item.trim()).filter(Boolean),
      cta: form.cta || null,
      contact: { address: form.address || null, phone: form.phone || null, website: form.website || null },
      hashtags: form.hashtags.split(/\s+/).map(item => item.trim()).filter(Boolean),
      link_url: form.link_url || null,
    })
    if (canAttach.value) {
      detail.value = await socialPostService.replaceFacebookImages(props.postId, galleryAssets.value.map(asset => asset.id))
    }
    fillContent(detail.value?.facebook_variant?.content ?? {})
    fillGallery(detail.value?.facebook_variant?.images ?? [])
    versions.value = await socialPostService.versions(props.postId) ?? []
    emit('saved', detail.value)
  } catch (cause) {
    error.value = cause?.data?.message ?? cause?.message ?? 'Không lưu được content Facebook.'
  } finally {
    saving.value = false
  }
}

/** Input: dialog close. Output: update:modelValue false. */
function close() { emit('update:modelValue', false) }

watch(() => [props.modelValue, props.postId], () => load(), { immediate: true })
onBeforeUnmount(() => { loadVersion += 1; clearTimeout(pollTimer) })
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
        <VAlert
          v-if="generating"
          type="info"
          variant="tonal"
          class="mb-4"
        >
          AI đang tạo nội dung và ảnh. Kết quả sẽ tự cập nhật tại đây.
        </VAlert>
        <fieldset
          :disabled="generating || loading || saving"
          class="social-editor-fields"
        >
          <VRow v-if="detail">
            <VCol
              cols="12"
              md="5"
            >
              <VCard
                variant="outlined"
                class="facebook-preview"
              >
                <VCardItem>
                  <template #prepend>
                    <VAvatar
                      color="primary"
                      variant="tonal"
                    >
                      <VIcon icon="tabler-brand-facebook" />
                    </VAvatar>
                  </template>
                  <VCardTitle class="text-body-1">
                    Facebook preview
                  </VCardTitle>
                  <VCardSubtitle>{{ detail.campaign?.name || 'Social Post' }}</VCardSubtitle>
                </VCardItem>
                <VCardText>
                  <div class="text-subtitle-1 font-weight-bold mb-2">
                    {{ form.title }}
                  </div>
                  <div class="text-body-2 whitespace-pre-line">
                    {{ form.body }}
                  </div>
                  <ul
                    v-if="detail.facebook_variant?.content?.bullet_points?.length"
                    class="mt-3 ps-5"
                  >
                    <li
                      v-for="bullet in detail.facebook_variant.content.bullet_points"
                      :key="bullet"
                    >
                      {{ bullet }}
                    </li>
                  </ul>
                </VCardText>
                <VRow
                  v-if="detail.facebook_variant?.images?.length"
                  no-gutters
                >
                  <VCol
                    v-for="image in detail.facebook_variant.images"
                    :key="image.usage_id"
                    cols="6"
                  >
                    <VImg
                      v-if="image.asset?.file?.preview_url || image.asset?.file?.url"
                      :src="image.asset.file.preview_url || image.asset.file.url"
                      height="150"
                      cover
                    />
                  </VCol>
                </VRow>
              </VCard>
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
              <VTextField
                v-model="form.title"
                label="Tiêu đề Facebook"
                class="mb-3"
              />
              <VTextarea
                v-model="form.body"
                label="Nội dung"
                rows="7"
                class="mb-3"
              />
              <VTextarea
                v-model="form.bullet_points"
                label="Bullet points (mỗi dòng một ý)"
                rows="4"
                class="mb-3"
              />
              <VTextarea
                v-model="form.cta"
                label="CTA"
                rows="2"
                class="mb-3"
              />
              <VRow>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="form.address"
                    label="Địa chỉ"
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="form.phone"
                    label="Hotline"
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="form.website"
                    label="Website"
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="form.hashtags"
                    label="Hashtag"
                  />
                </VCol>
                <VCol cols="12">
                  <VTextField
                    v-model="form.link_url"
                    label="Link"
                  />
                </VCol>
              </VRow>
              <div class="text-caption text-medium-emphasis mb-3">
                Gallery Facebook: {{ detail.facebook_variant?.images?.length || 0 }}/{{ detail.facebook_variant?.image_count ?? 3 }} ảnh.
              </div>
              <div
                v-if="imageJobs.length"
                class="d-flex flex-wrap gap-2 mb-3"
              >
                <VBtn
                  v-for="job in imageJobs"
                  :key="job.id"
                  size="small"
                  variant="tonal"
                  :disabled="generating || saving"
                  @click="regenerateSlot(job.slot_no)"
                >
                  {{ job.status === 'ready' ? 'Tạo lại' : 'Thử lại' }} ảnh {{ job.slot_no + 1 }}
                </VBtn>
              </div>
              <MediaAssetField
                v-if="canAttach"
                v-model="galleryAssets"
                field="social_post_variant.facebook.images"
                multiple
                visibility="public"
                label="Gallery ảnh Facebook"
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
        </fieldset>
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
          :disabled="generating || loading || !detail"
          @click="save"
        >
          Lưu content Facebook
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>

<style scoped>
.social-editor-fields { border: 0; padding: 0; margin: 0; min-width: 0; }
.detail-empty { min-height: 280px; background: rgba(var(--v-theme-on-surface), 0.04); }
.facebook-preview { min-height: 280px; }
.whitespace-pre-line { white-space: pre-line; }
</style>
