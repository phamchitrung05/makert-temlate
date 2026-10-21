<!--
  =====================================================================
  CHỨC NĂNG FILE: Trang Generate — tạo batch ảnh sản phẩm theo Visual Style.
  =====================================================================
  Luồng: upload ảnh sản phẩm (→ Media Library lấy media_ids) → chọn style
  active+enabled → chọn 1-4 shot recipes → aspect_ratio + image_count +
  instructions → POST /generate (queue). Sau đó poll GET /generations/{importId}
  để render lưới outputs per-shot (object theo recipe_code, mỗi shot có
  images[{slot_no,status,media_asset_id,prompt_used,error_*}]); prompt_used
  expand, retry gửi lại generate cho shot lỗi.
  Backend `/api/admin/ai/visual-styles/*` qua visualStylesService +
  mediaAssetService (upload sản phẩm).

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - loadStyles(): tải style cho select (chỉ active+enabled+dna).
  - onStyleChange(): đổi style → nạp shots active làm recipe checkbox.
  - toggleRecipe(): chọn/bỏ recipe (tối đa 4).
  - generate(): upload product files → media_ids, POST generate, poll import.
  - pollRun(): GET generations/{id} theo interval; dừng khi terminal.
  - retryShot(): POST generate lại cho một shot recipe.
  - uploadProducts(): đưa File lên Media Library → media_ids.
  - flattenOutputs(): computed outputs object → danh sách {recipe,shot,image} phẳng.
  - onMounted/onScopeDispose: load styles + dọn timer.
  - props/emits: không có; page tự quản lý state + API qua service.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : files sản phẩm, style + recipes + ratio/count/instructions.
  - OUTPUT: form cấu hình + grid outputs + prompt_used + retry; snackbar.
  - SIDE EFFECT: POST media-assets upload, GET list/show, POST generate,
  GET generations/{importId}; polling timer.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Request keys follow the Laravel Visual Style API contract. */
import { computed, onMounted, onScopeDispose, ref } from 'vue'
import { visualStylesService } from '@/services/visualStyles'
import { mediaAssetService } from '@/services/mediaAsset'
import ProductUpload from '@/views/visual-styles/ProductUpload.vue'

definePage({ meta: { action: 'manage', subject: 'ai_settings' } })

// Form state
const productFiles = ref([])
const productDescription = ref('')
const productCategory = ref('')
const styleId = ref(null)
const selectedRecipeIds = ref([])
const aspectRatio = ref('1:1')
const imageCount = ref(1)
const instructions = ref('')

// Data + generation state
const styles = ref([])
const stylesLoading = ref(false)
const stylesError = ref('')
const shots = ref([])
const shotsLoading = ref(false)
const generating = ref(false)
const uploading = ref(false)
const run = ref(null)
const runError = ref('')
const retryingShot = ref(null)
const snackbar = ref({ show: false, text: '', color: 'success' })

const ASPECT_RATIOS = ['1:1', '4:5', '3:4', '16:9', '9:16', '4:3', '3:2']
const TERMINAL = ['ready', 'failed', 'cancelled', 'expired']

let pollTimer = null

// Chỉ style active + enabled mới generate được (backend reject style khác)
const styleOptions = computed(() => styles.value
  .filter(item => item.status === 'active' && item.is_enabled)
  .map(item => ({ title: item.name, value: item.id })))

const selectedRecipes = computed(() => shots.value.filter(shot => selectedRecipeIds.value.includes(shot.id)))

const taskStatus = computed(() => run.value?.status ?? null)

const canGenerate = computed(() =>
  productFiles.value.length > 0
  && styleId.value
  && selectedRecipeIds.value.length >= 1
  && selectedRecipeIds.value.length <= 4
  && !generating.value
  && !uploading.value,
)

// outputs từ resource là object theo recipe_code → flatten thành list {recipe_code, shot_id, status, image{slot,status,media_asset_id,prompt_used,error}}
const flatOutputs = computed(() => {
  const raw = run.value?.outputs
  if (!raw || typeof raw !== 'object') return []
  const list = []

  Object.values(raw).forEach(entry => {
    const recipeCode = entry.recipe_code ?? ''
    const shotId = entry.shot_id
    const shotStatus = entry.status ?? 'queued'

    if (Array.isArray(entry.images) && entry.images.length) {
      entry.images.forEach(image => list.push({
        recipe_code: recipeCode,
        shot_id: shotId,
        shot_status: shotStatus,
        status: image.status ?? 'queued',
        media_asset_id: image.media_asset_id,
        prompt_used: image.prompt_used,
        error_message: image.error_message,
        slot_no: image.slot_no,
      }))
    }
    else {
      list.push({
        recipe_code: recipeCode,
        shot_id: shotId,
        shot_status: shotStatus,
        status: shotStatus,
        media_asset_id: null,
        prompt_used: null,
        error_message: entry.error_message,
        slot_no: null,
      })
    }
  })

  return list
})

function showSnackbar(text, color = 'success') {
  snackbar.value = { show: true, text, color }
}

// =====================================================================
// Input: không có. Output: styles cho select.
// Side effect: GET list; lỗi vào stylesError.
// =====================================================================
async function loadStyles() {
  stylesLoading.value = true
  stylesError.value = ''
  try {
    const result = await visualStylesService.list({ per_page: 50 })

    styles.value = result.items ?? []
  }
  catch (reason) {
    stylesError.value = reason?.data?.message ?? 'Không tải được danh sách style.'
  }
  finally {
    stylesLoading.value = false
  }
}

// =====================================================================
// Input: style id. Output: shots active làm recipe checkbox + auto chọn ≤4.
// Side effect: GET show (shots eager); lỗi → snackbar.
// =====================================================================
async function onStyleChange(id) {
  shots.value = []
  selectedRecipeIds.value = []
  if (!id) return
  shotsLoading.value = true
  try {
    const detail = await visualStylesService.show(id)

    shots.value = Array.isArray(detail.shots) ? detail.shots.filter(shot => shot.is_active !== false) : []
    selectedRecipeIds.value = shots.value.slice(0, 4).map(shot => shot.id)
  }
  catch (reason) {
    showSnackbar(reason?.data?.message ?? 'Không tải được recipes.', 'error')
  }
  finally {
    shotsLoading.value = false
  }
}

// =====================================================================
// Input: shot id. Output: thêm/bỏ khỏi selectedRecipeIds (tối đa 4).
// =====================================================================
function toggleRecipe(id) {
  const index = selectedRecipeIds.value.indexOf(id)
  if (index === -1) {
    if (selectedRecipeIds.value.length >= 4) {
      showSnackbar('Chọn tối đa 4 recipes.', 'warning')

      return
    }
    selectedRecipeIds.value.push(id)
  }
  else {
    selectedRecipeIds.value.splice(index, 1)
  }
}

// =====================================================================
// Input: File[] product. Output: media_ids sau upload lên Media Library.
// Side effect: POST media-assets từng file; lỗi ném lên caller.
// =====================================================================
async function uploadProducts() {
  const ids = []

  for (const file of productFiles.value) {
    const asset = await mediaAssetService.upload({
      file,
      title: file.name,
    })

    if (asset?.id) ids.push(asset.id)
  }

  return ids
}

// =====================================================================
// Input: form. Output: queued generation; poll import.
// Side effect: upload products + POST generate + đặt polling.
// =====================================================================
async function generate() {
  if (!canGenerate.value) return
  generating.value = true
  runError.value = ''
  try {
    uploading.value = true

    const mediaIds = await uploadProducts()

    uploading.value = false

    const payload = {
      style_id: styleId.value,
      shot_recipe_ids: selectedRecipeIds.value,
      product: {
        media_ids: mediaIds,
        description: productDescription.value?.trim() || null,
        category: productCategory.value?.trim() || null,
      },
      aspect_ratio: aspectRatio.value,
      image_count: imageCount.value,
      instructions: instructions.value?.trim() ? [instructions.value.trim()] : [],
    }

    const result = await visualStylesService.generate(payload)

    run.value = result?.id ? { id: result.id, status: result.status ?? 'queued', outputs: result.outputs } : null
    showSnackbar('Đã xếp hàng tạo ảnh. Theo dõi tiến trình bên dưới.')
    if (run.value?.id && !TERMINAL.includes(run.value.status))
      schedulePoll()
  }
  catch (reason) {
    runError.value = reason?.data?.message ?? 'Gửi yêu cầu tạo ảnh thất bại.'
    showSnackbar(runError.value, 'error')
  }
  finally {
    generating.value = false
    uploading.value = false
  }
}

// =====================================================================
// Input: run.id. Output: cập nhật status + outputs; dừng poll khi terminal.
// Side effect: GET generations/{id}; lỗi dừng poll và ghi runError.
// =====================================================================
async function pollRun() {
  if (!run.value?.id) return
  try {
    const data = await visualStylesService.getGeneration(run.value.id)

    run.value = { ...run.value, ...data }
    if (TERMINAL.includes(data?.status))
      clearTimeout(pollTimer)
    else
      schedulePoll()
  }
  catch (reason) {
    clearTimeout(pollTimer)
    runError.value = reason?.data?.message ?? 'Không đọc được trạng thái tạo ảnh.'
  }
}

function schedulePoll() {
  clearTimeout(pollTimer)
  pollTimer = setTimeout(pollRun, 4000)
}

// =====================================================================
// Input: output item (shot thất bại). Output: POST generate lại chỉ shot đó.
// =====================================================================
async function retryShot(item) {
  if (!item?.shot_id || retryingShot.value) return
  retryingShot.value = item.shot_id
  try {
    const mediaIds = run.value?.product_media_ids ?? []

    const result = await visualStylesService.generate({
      style_id: styleId.value,
      shot_recipe_ids: [item.shot_id],
      product: {
        media_ids: mediaIds,
        description: productDescription.value?.trim() || null,
        category: productCategory.value?.trim() || null,
      },
      aspect_ratio: aspectRatio.value,
      image_count: 1,
      instructions: [],
    })

    showSnackbar('Đã xếp hàng tạo lại ảnh cho recipe này.')
    if (result?.id) {
      run.value = { id: result.id, status: result.status ?? 'queued', outputs: result.outputs }
      schedulePoll()
    }
  }
  catch (reason) {
    showSnackbar(reason?.data?.message ?? 'Retry thất bại.', 'error')
  }
  finally {
    retryingShot.value = null
  }
}

onMounted(loadStyles)
onScopeDispose(() => clearTimeout(pollTimer))
</script>

<template>
  <div class="visual-style-generate-page">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-space-between gap-y-4 gap-x-6 mb-6">
      <div>
        <h4 class="text-h4 font-weight-medium">
          Tạo ảnh theo Visual Style
        </h4>
        <div class="text-body-1">
          Áp Style DNA + Shot Recipes lên ảnh sản phẩm để tạo batch ảnh đồng nhất.
        </div>
      </div>
      <VBtn
        color="secondary"
        variant="tonal"
        prepend-icon="tabler-arrow-left"
        :to="{ name: 'visual-styles' }"
      >
        Danh sách style
      </VBtn>
    </div>

    <VRow>
      <!-- Cấu hình -->
      <VCol
        cols="12"
        lg="5"
      >
        <VCard class="mb-6">
          <VCardText>
            <!-- Bước 1: Ảnh sản phẩm -->
            <div class="d-flex align-center mb-3">
              <VAvatar
                color="primary"
                variant="tonal"
                rounded
                size="38"
                class="me-3"
              >
                <VIcon icon="tabler-package" />
              </VAvatar>
              <div>
                <div class="text-subtitle-1 font-weight-medium">
                  1. Ảnh sản phẩm
                </div>
                <div class="text-body-2 text-medium-emphasis">
                  Tải ảnh sản phẩm cần áp style
                </div>
              </div>
            </div>
            <ProductUpload
              v-model:files="productFiles"
              :disabled="generating || uploading"
              class="mb-3"
            />
            <AppTextField
              v-model="productDescription"
              label="Mô tả sản phẩm"
              placeholder="Áo thun cotton trắng..."
              class="mb-3"
              :disabled="generating || uploading"
            />
            <AppTextField
              v-model="productCategory"
              label="Nhóm sản phẩm"
              placeholder="fashion, cosmetics..."
              class="mb-6"
              :disabled="generating || uploading"
            />

            <!-- Bước 2: Style -->
            <div class="d-flex align-center mb-3">
              <VAvatar
                color="primary"
                variant="tonal"
                rounded
                size="38"
                class="me-3"
              >
                <VIcon icon="tabler-palette" />
              </VAvatar>
              <div>
                <div class="text-subtitle-1 font-weight-medium">
                  2. Chọn style
                </div>
                <div class="text-body-2 text-medium-emphasis">
                  Style DNA giữ đồng nhất cho cả batch
                </div>
              </div>
            </div>
            <AppSelect
              :model-value="styleId"
              :items="styleOptions"
              label="Visual Style (active)"
              placeholder="Chọn style đã activate"
              :loading="stylesLoading"
              :disabled="generating || uploading"
              class="mb-2"
              @update:model-value="styleId = $event; onStyleChange($event)"
            />
            <VAlert
              v-if="stylesError"
              type="error"
              variant="tonal"
              density="compact"
              class="mb-4"
            >
              {{ stylesError }}
            </VAlert>
            <VAlert
              v-else-if="!stylesLoading && !styleOptions.length"
              type="warning"
              variant="tonal"
              density="compact"
              class="mb-4"
            >
              Chưa có style nào ở trạng thái Hoạt động — activate một style trước.
            </VAlert>

            <!-- Bước 3: Recipes -->
            <div class="d-flex align-center mb-3 mt-4">
              <VAvatar
                color="primary"
                variant="tonal"
                rounded
                size="38"
                class="me-3"
              >
                <VIcon icon="tabler-camera" />
              </VAvatar>
              <div>
                <div class="text-subtitle-1 font-weight-medium">
                  3. Shot recipes (1–4)
                </div>
                <div class="text-body-2 text-medium-emphasis">
                  Mỗi recipe = một góc/bố cục khác nhau
                </div>
              </div>
            </div>
            <VProgressLinear
              v-if="shotsLoading"
              indeterminate
              class="mb-3"
            />
            <div
              v-else-if="!shots.length"
              class="text-body-2 text-medium-emphasis mb-4"
            >
              {{ styleId ? 'Style chưa có recipe active nào.' : 'Chọn style để xem recipes.' }}
            </div>
            <div
              v-else
              class="d-flex flex-column gap-2 mb-6"
            >
              <VCard
                v-for="shot in shots"
                :key="shot.id"
                :variant="selectedRecipeIds.includes(shot.id) ? 'tonal' : 'outlined'"
                :color="selectedRecipeIds.includes(shot.id) ? 'primary' : undefined"
                class="recipe-card"
                @click="toggleRecipe(shot.id)"
              >
                <VCardText class="d-flex align-center py-3">
                  <VCheckbox
                    :model-value="selectedRecipeIds.includes(shot.id)"
                    hide-details
                    density="compact"
                    class="me-2 flex-grow-0"
                    @click.stop
                    @update:model-value="toggleRecipe(shot.id)"
                  />
                  <div class="flex-grow-1 min-w-0">
                    <div class="text-body-2 font-weight-medium">
                      {{ shot.name }}
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      <code>{{ shot.code }}</code>
                      <span v-if="shot.suggested_aspect_ratio"> · {{ shot.suggested_aspect_ratio }}</span>
                    </div>
                  </div>
                </VCardText>
              </VCard>
            </div>

            <!-- Bước 4: Tùy chọn -->
            <div class="d-flex align-center mb-3">
              <VAvatar
                color="primary"
                variant="tonal"
                rounded
                size="38"
                class="me-3"
              >
                <VIcon icon="tabler-adjustments" />
              </VAvatar>
              <div>
                <div class="text-subtitle-1 font-weight-medium">
                  4. Tùy chọn
                </div>
              </div>
            </div>
            <VRow dense>
              <VCol
                cols="12"
                sm="6"
              >
                <AppSelect
                  v-model="aspectRatio"
                  :items="ASPECT_RATIOS"
                  label="Tỷ lệ khung hình"
                  :disabled="generating || uploading"
                />
              </VCol>
              <VCol
                cols="12"
                sm="6"
              >
                <AppTextField
                  v-model.number="imageCount"
                  type="number"
                  :min="1"
                  :max="8"
                  label="Số ảnh mỗi recipe"
                  :disabled="generating || uploading"
                />
              </VCol>
              <VCol cols="12">
                <AppTextarea
                  v-model="instructions"
                  label="Ghi chú thêm (tùy chọn)"
                  placeholder="Yêu cầu riêng cho batch này..."
                  rows="3"
                  auto-grow
                  :disabled="generating || uploading"
                />
              </VCol>
            </VRow>
          </VCardText>

          <VCardText class="pt-0">
            <VBtn
              block
              color="primary"
              size="large"
              prepend-icon="tabler-sparkles"
              :loading="generating || uploading"
              :disabled="!canGenerate"
              @click="generate"
            >
              {{ uploading ? 'Đang tải ảnh...' : 'Tạo ảnh' }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Outputs -->
      <VCol
        cols="12"
        lg="7"
      >
        <VCard class="h-100">
          <VCardItem>
            <template #title>
              Kết quả
            </template>
            <template #append>
              <VChip
                v-if="taskStatus"
                :color="taskStatus === 'ready' ? 'success' : ['failed', 'cancelled', 'expired'].includes(taskStatus) ? 'error' : 'info'"
                variant="tonal"
                size="small"
              >
                {{ taskStatus }}
              </VChip>
            </template>
          </VCardItem>
          <VCardText>
            <VAlert
              v-if="runError"
              type="error"
              variant="tonal"
              class="mb-4"
            >
              {{ runError }}
            </VAlert>
            <VAlert
              v-if="run?.error_message && taskStatus === 'failed'"
              type="error"
              variant="tonal"
              class="mb-4"
            >
              {{ run.error_message }}
            </VAlert>

            <div
              v-if="run && !TERMINAL.includes(taskStatus)"
              class="d-flex align-center mb-4"
            >
              <VProgressCircular
                indeterminate
                size="20"
                width="2"
                class="me-3"
              />
              <div class="flex-grow-1">
                <div class="text-body-2">
                  Đang tạo ảnh qua queue — kết quả tự cập nhật.
                </div>
                <VProgressLinear
                  v-if="typeof run.progress === 'number'"
                  :model-value="run.progress"
                  color="primary"
                  class="mt-2"
                />
              </div>
            </div>

            <div
              v-if="!flatOutputs.length && !run"
              class="text-center pa-10 text-medium-emphasis"
            >
              <VIcon
                size="56"
                icon="tabler-photo-sparkles"
                class="mb-3"
              />
              <div class="text-subtitle-1 font-weight-medium mb-1">
                Chưa có kết quả
              </div>
              <div class="text-body-2">
                Cấu hình bên trái và bấm “Tạo ảnh” — outputs sẽ hiện ở đây với prompt_used từng shot.
              </div>
            </div>

            <div
              v-else-if="run?.summary"
              class="text-body-2 text-medium-emphasis mb-4"
            >
              Thành công {{ run.summary.succeeded ?? 0 }} · Thất bại {{ run.summary.failed ?? 0 }}
            </div>

            <VRow v-if="flatOutputs.length">
              <VCol
                v-for="(item, index) in flatOutputs"
                :key="`${item.recipe_code}-${item.slot_no ?? index}`"
                cols="12"
                sm="6"
                md="4"
              >
                <VCard class="output-card h-100 d-flex flex-column">
                  <div class="output-card__media">
                    <VImg
                      v-if="item.media_asset_id"
                      :src="`/storage/media/${item.media_asset_id}`"
                      cover
                      aspect-ratio="1"
                    />
                    <div
                      v-else
                      class="d-flex flex-column align-center justify-center text-medium-emphasis output-card__placeholder"
                    >
                      <VIcon
                        size="32"
                        :icon="item.status === 'failed' ? 'tabler-alert-circle' : 'tabler-loader-2'"
                      />
                      <span class="text-caption mt-1">{{ item.status }}</span>
                    </div>
                  </div>
                  <VCardText class="py-2 flex-grow-1">
                    <div class="d-flex align-center justify-space-between mb-1">
                      <div class="text-caption text-medium-emphasis text-truncate">
                        {{ item.recipe_code }}
                      </div>
                      <VChip
                        :color="item.status === 'ready' ? 'success' : item.status === 'failed' ? 'error' : 'info'"
                        variant="tonal"
                        size="x-small"
                      >
                        {{ item.status }}
                      </VChip>
                    </div>
                    <div
                      v-if="item.error_message"
                      class="text-caption text-error mb-1"
                    >
                      {{ item.error_message }}
                    </div>
                    <VExpansionPanels
                      v-if="item.prompt_used"
                      variant="accordion"
                      density="compact"
                    >
                      <VExpansionPanel>
                        <VExpansionPanelTitle class="text-caption pa-2">
                          prompt_used
                        </VExpansionPanelTitle>
                        <VExpansionPanelText>
                          <div class="text-caption prompt-used pa-2 rounded">
                            {{ item.prompt_used }}
                          </div>
                        </VExpansionPanelText>
                      </VExpansionPanel>
                    </VExpansionPanels>
                  </VCardText>
                  <VCardText
                    v-if="item.status === 'failed'"
                    class="pt-0"
                  >
                    <VBtn
                      block
                      size="small"
                      color="secondary"
                      variant="tonal"
                      prepend-icon="tabler-refresh"
                      :loading="retryingShot === item.shot_id"
                      :disabled="retryingShot === item.shot_id"
                      @click="retryShot(item)"
                    >
                      Retry recipe
                    </VBtn>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VSnackbar
      v-model="snackbar.show"
      :color="snackbar.color"
      location="bottom end"
      :timeout="3200"
    >
      {{ snackbar.text }}
    </VSnackbar>
  </div>
</template>

<style scoped>
.visual-style-generate-page {
  min-inline-size: 0;
}
.recipe-card {
  cursor: pointer;
}
.output-card__media {
  background-color: rgba(var(--v-theme-on-surface), 0.04);
}
.output-card__placeholder {
  aspect-ratio: 1;
}
.prompt-used {
  background-color: rgba(var(--v-theme-on-surface), 0.04);
  white-space: pre-wrap;
  word-break: break-word;
}
</style>
