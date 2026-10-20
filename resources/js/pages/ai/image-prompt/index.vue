<script setup>
/* eslint-disable camelcase -- API field names. */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { aiImageProfilesService } from '@/services/aiImageProfiles'
import { useAiProviderSettings } from '@/composables/useAiProviderSettings'
import { formatAiError } from '@/utils/aiErrors'

definePage({ meta: { action: 'manage', subject: 'ai_settings' } })

const catalog = useAiProviderSettings()
const name = ref('')
const file = ref(null)
const previewUrl = ref('')
const modelId = ref(null)
const items = ref([])
const page = ref(1)
const lastPage = ref(1)
const selected = ref(null)
const busy = ref(false)
const loading = ref(false)
const error = ref('')
const notice = ref('')
const deleting = ref(null)
let timer
let disposed = false
let listVersion = 0

const models = computed(() => catalog.providers.value
  .filter(provider => provider.is_active && provider.has_api_key && ['openai', 'openai-compatible', 'gemini'].includes(provider.driver))
  .flatMap(provider => (provider.models ?? [])
    .filter(model => model.is_enabled && model.is_available && model.capabilities?.includes('vision') && model.capabilities?.includes('text_generation'))
    .map(model => ({ title: `${provider.name} · ${model.label || model.remote_model_id}`, value: model.id }))))

const active = item => ['queued', 'analyzing'].includes(item.status)
const statusLabel = item => ({ queued: 'Đang chờ', analyzing: 'Đang phân tích', ready: item.is_enabled ? 'Đã bật' : 'Chờ lưu và bật', failed: 'Thất bại' }[item.status] ?? item.status)
const currentFile = computed(() => Array.isArray(file.value) ? file.value[0] : file.value)

watch(currentFile, value => {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = value ? URL.createObjectURL(value) : ''
})

function schedule() {
  clearTimeout(timer)
  if (!disposed && (items.value.some(active) || (selected.value && active(selected.value))))
    timer = setTimeout(() => load(false), 2500)
}

async function load(showLoading = true) {
  const version = ++listVersion
  if (showLoading) loading.value = true
  try {
    const result = await aiImageProfilesService.list(page.value)
    if (disposed || version !== listVersion) return
    items.value = result.data
    lastPage.value = result.last_page
    if (selected.value && active(selected.value)) {
      const id = selected.value.id
      const fresh = result.data.find(item => item.id === id) ?? await aiImageProfilesService.show(id)
      if (!disposed && selected.value?.id === id && active(selected.value)) selected.value = { ...fresh }
    }
    schedule()
  }
  catch (reason) { if (!disposed) error.value = formatAiError(reason, 'Không tải được prompt ảnh. Bấm tải lại để tiếp tục.') }
  finally { if (!disposed && version === listVersion) loading.value = false }
}

async function analyze() {
  error.value = ''
  notice.value = ''

  const image = currentFile.value
  if (!name.value.trim() || !image || !models.value.some(model => model.value === modelId.value)) {
    error.value = 'Nhập tên, chọn ảnh mẫu và model có Vision.'

    return
  }
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(image.type) || image.size > 5 * 1024 * 1024) {
    error.value = 'Chọn ảnh JPG, PNG hoặc WebP tối đa 5 MB.'

    return
  }
  busy.value = true
  try {
    const body = new FormData()

    body.append('name', name.value.trim())
    body.append('image', image)
    body.append('model_id', modelId.value)
    selected.value = await aiImageProfilesService.create(body)
    name.value = ''
    file.value = null
    page.value = 1
    notice.value = 'Đã xếp hàng phân tích. Bạn có thể rời trang và mở lại kết quả sau.'
    await load()
  }
  catch (reason) { error.value = formatAiError(reason, 'Chưa xác nhận được tác vụ. Hãy tải lại danh sách trước khi gửi lại.') }
  finally { busy.value = false }
}

async function save() {
  busy.value = true
  error.value = ''
  try {
    const { id, name: profileName, prompt, negative_prompt, is_enabled } = selected.value

    selected.value = await aiImageProfilesService.save(id, { name: profileName, prompt, negative_prompt, is_enabled })
    notice.value = 'Đã lưu prompt. Prompt đã bật có thể chọn trong AI Content.'
    await load()
  }
  catch (reason) { error.value = formatAiError(reason, 'Không lưu được prompt ảnh.') }
  finally { busy.value = false }
}

async function remove() {
  busy.value = true
  error.value = ''
  try {
    await aiImageProfilesService.remove(deleting.value.id)
    if (selected.value?.id === deleting.value.id) selected.value = null
    deleting.value = null
    await load()
  }
  catch (reason) { error.value = formatAiError(reason, 'Không xóa được prompt ảnh.') }
  finally { busy.value = false }
}

async function reload() {
  error.value = ''
  await Promise.allSettled([catalog.load(), load()])
}

watch(page, () => load())
onMounted(reload)
onUnmounted(() => {
  disposed = true
  listVersion++
  clearTimeout(timer)
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
})
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-4">
      <div>
        <h2>Prompt tạo ảnh</h2>
        <p class="text-medium-emphasis mb-0">
          Phân tích ảnh mẫu để lưu phong cách tạo ảnh cho những lần sau.
        </p>
      </div>
      <VBtn
        variant="tonal"
        :loading="loading"
        :disabled="busy"
        @click="reload"
      >
        Tải lại
      </VBtn>
    </div>
    <VAlert
      v-if="error || catalog.error.value"
      type="error"
      class="mb-4"
    >
      {{ error || catalog.error.value }}
    </VAlert>
    <VAlert
      v-if="notice"
      type="success"
      variant="tonal"
      class="mb-4"
    >
      {{ notice }}
    </VAlert>
    <VRow>
      <VCol
        cols="12"
        md="5"
      >
        <VCard title="Phân tích ảnh mẫu">
          <VCardText class="d-flex flex-column gap-4">
            <AppTextField
              v-model="name"
              label="Tên prompt ảnh"
              maxlength="160"
              :disabled="busy"
            />
            <VFileInput
              v-model="file"
              label="Ảnh mẫu"
              accept="image/jpeg,image/png,image/webp"
              show-size
              :disabled="busy"
            />
            <VImg
              v-if="previewUrl"
              :src="previewUrl"
              max-height="240"
              contain
              alt="Ảnh mẫu đã chọn"
            />
            <AppSelect
              v-model="modelId"
              :items="models"
              label="Model phân tích ảnh (Vision)"
              :disabled="busy || catalog.loading.value"
            />
            <VAlert
              v-if="!catalog.loading.value && !models.length"
              type="info"
              variant="tonal"
            >
              Bật model có Text generation và Vision trong AI Settings để phân tích ảnh.
            </VAlert>
            <VBtn
              :loading="busy"
              :disabled="busy || !models.length"
              prepend-icon="tabler-sparkles"
              @click="analyze"
            >
              Phân tích &amp; tạo prompt
            </VBtn>
            <p class="text-caption mb-0">
              JPG, PNG, WebP · tối đa 5 MB. AI phân tích bố cục, màu sắc, ánh sáng và phong cách.
            </p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="12"
        md="7"
      >
        <VCard
          v-if="selected"
          title="Kết quả phân tích"
          class="mb-4"
        >
          <VCardText class="d-flex flex-column gap-4">
            <VChip class="align-self-start">
              {{ statusLabel(selected) }}
            </VChip>
            <VProgressLinear
              v-if="active(selected)"
              indeterminate
            />
            <VAlert
              v-if="selected.error_message"
              type="error"
            >
              {{ selected.error_message }}
            </VAlert>
            <template v-if="selected.status === 'ready'">
              <p>{{ selected.summary }}</p>
              <AppTextField
                v-model="selected.name"
                label="Tên prompt"
                maxlength="160"
                :disabled="busy"
              />
              <AppTextarea
                v-model="selected.prompt"
                label="Prompt tạo ảnh"
                rows="7"
                maxlength="6000"
                :disabled="busy"
                hint="{subject} sẽ được thay bằng chủ đề ảnh mới khi sử dụng."
                persistent-hint
              />
              <AppTextarea
                v-model="selected.negative_prompt"
                label="Những chi tiết cần tránh"
                rows="3"
                maxlength="2000"
                :disabled="busy"
              />
              <VSwitch
                v-model="selected.is_enabled"
                label="Bật để sử dụng khi tạo ảnh"
                :disabled="busy"
              />
              <VBtn
                :loading="busy"
                :disabled="busy"
                @click="save"
              >
                Lưu prompt ảnh
              </VBtn>
            </template>
          </VCardText>
        </VCard>
        <VCard title="Prompt ảnh đã lưu">
          <VList v-if="items.length">
            <VListItem
              v-for="item in items"
              :key="item.id"
              :title="item.name"
              :subtitle="statusLabel(item)"
              :disabled="busy"
              @click="selected = { ...item }"
            >
              <template #append>
                <VBtn
                  icon="tabler-trash"
                  variant="text"
                  aria-label="Xóa prompt ảnh"
                  :disabled="busy || active(item)"
                  @click.stop="deleting = item"
                />
              </template>
            </VListItem>
          </VList>
          <VCardText v-else>
            Chưa có prompt ảnh. Tải ảnh mẫu lên để tạo prompt đầu tiên.
          </VCardText>
          <VPagination
            v-if="lastPage > 1"
            v-model="page"
            :length="lastPage"
            :disabled="busy"
          />
        </VCard>
      </VCol>
    </VRow>
    <VDialog
      :model-value="!!deleting"
      max-width="440"
      @update:model-value="!busy && (deleting = null)"
    >
      <VCard title="Xóa prompt ảnh?">
        <VCardText>Prompt “{{ deleting?.name }}” và ảnh mẫu sẽ bị xóa.</VCardText>
        <VCardActions>
          <VBtn
            :disabled="busy"
            @click="deleting = null"
          >
            Hủy
          </VBtn>
          <VBtn
            color="error"
            :loading="busy"
            :disabled="busy"
            @click="remove"
          >
            Xóa
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
