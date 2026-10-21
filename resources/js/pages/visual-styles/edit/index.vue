<!--
  =====================================================================
  CHỨC NĂNG FILE: Trang chỉnh sửa một Visual Style (metadata + DNA + recipes).
  =====================================================================
  Đọc style theo `route.params.id`; gồm: form metadata (name/category/is_enabled),
  trạng thái phân tích (queued/analyzing/failed + lỗi), form chỉnh Style DNA
  trực quan qua DnaEditForm, và danh sách Shot Recipes (thêm/sửa/xóa/sắp xếp
  qua ShotRecipeDialog + nút lên/xuống + reorder). Nút Lưu DNA gọi PUT dna với
  expected_version (optimistic lock) và Activate khi đủ điều kiện.
  Backend `/api/admin/ai/visual-styles/{id}` + `/dna` + `/shots` + `/activate`.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - load(): tải style detail (kèm shots) theo id route.
  - saveMeta(): PUT name/category/is_enabled.
  - saveDna(): PUT dna + expected_version; conflict → báo tải lại.
  - activate(): POST activate; style chưa đủ điều kiện bị reject backend.
  - openShotDialog()/saveShot(): mở dialog và create/update recipe.
  - deleteShot(): xóa recipe (confirm inline).
  - moveShot()/persistOrder(): đổi sort_order rồi POST reorder.
  - watcher route.params.id: reload khi đổi style.
  - props/emits: không có; page dùng route param + service.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : route id, form metadata/DNA, thao tác recipes.
  - OUTPUT: form edit + bảng recipes + snackbar; điều hướng list khi cần.
  - SIDE EFFECT: GET/PUT/POST/DELETE qua visualStylesService; polling reload khi analyzing.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Request keys follow the Laravel Visual Style API contract. */
import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { visualStylesService } from '@/services/visualStyles'
import DnaEditForm from '@/views/visual-styles/DnaEditForm.vue'
import ShotRecipeDialog from '@/views/visual-styles/ShotRecipeDialog.vue'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'

definePage({ meta: { action: 'manage', subject: 'ai_settings' } })

const route = useRoute()
const router = useRouter()

// State style + metadata form
const style = ref(null)
const meta = ref({ name: '', category: '', is_enabled: false })
const dna = ref({})
const loading = ref(false)
const error = ref('')
const savingMeta = ref(false)
const savingDna = ref(false)
const activating = ref(false)
const snackbar = ref({ show: false, text: '', color: 'success' })

// Shot recipes state
const shots = ref([])
const shotDialogOpen = ref(false)
const editingShot = ref(null)
const savingShot = ref(false)
const confirmDeleteOpen = ref(false)
const deletingShot = ref(null)

const styleId = computed(() => Number(route.params.id))

const statusMeta = computed(() => {
  const map = {
    draft: { label: 'Nháp', color: 'secondary' },
    queued: { label: 'Chờ phân tích', color: 'warning' },
    analyzing: { label: 'Đang phân tích', color: 'info' },
    analyzed: { label: 'Đã phân tích', color: 'info' },
    ready: { label: 'Sẵn sàng', color: 'info' },
    active: { label: 'Hoạt động', color: 'success' },
    failed: { label: 'Lỗi', color: 'error' },
    archived: { label: 'Đã lưu trữ', color: 'secondary' },
  }

  return map[style.value?.status] ?? { label: '—', color: 'secondary' }
})

const isProcessing = computed(() => ['queued', 'analyzing'].includes(style.value?.status))
const hasDna = computed(() => style.value?.dna_json && Object.keys(style.value.dna_json).length)
const canActivate = computed(() => ['analyzed', 'ready', 'draft'].includes(style.value?.status) && hasDna.value && style.value?.status !== 'active')

let pollTimer = null

function showSnackbar(text, color = 'success') {
  snackbar.value = { show: true, text, color }
}

// =====================================================================
// Input: id từ route. Output: style + meta + dna + shots vào state.
// Side effect: GET show; nếu analyzing → bật polling reload.
// =====================================================================
async function load() {
  loading.value = true
  error.value = ''
  try {
    const data = await visualStylesService.show(styleId.value)

    style.value = data
    meta.value = {
      name: data.name ?? '',
      category: data.category ?? '',
      is_enabled: data.is_enabled === true,
    }
    dna.value = data.dna_json ? { ...data.dna_json } : {}
    shots.value = Array.isArray(data.shots) ? [...data.shots] : []

    // Polling nhẹ khi đang phân tích để cập nhật DNA khi worker xong
    if (['queued', 'analyzing'].includes(data.status)) {
      clearTimeout(pollTimer)
      pollTimer = setTimeout(load, 4000)
    }
  }
  catch (reason) {
    error.value = reason?.data?.message ?? 'Không tải được style.'
  }
  finally {
    loading.value = false
  }
}

// =====================================================================
// Input: meta form. Output: style refresh; snackbar.
// Side effect: PUT update; lỗi → snackbar error.
// =====================================================================
async function saveMeta() {
  savingMeta.value = true
  try {
    const data = await visualStylesService.update(styleId.value, {
      name: meta.value.name?.trim(),
      category: meta.value.category?.trim() || null,
      is_enabled: meta.value.is_enabled === true,
    })

    style.value = data
    showSnackbar('Đã lưu thông tin style.')
  }
  catch (reason) {
    showSnackbar(reason?.data?.message ?? 'Lưu thông tin thất bại.', 'error')
  }
  finally {
    savingMeta.value = false
  }
}

// =====================================================================
// Input: dna object hiện tại + version. Output: style version++; snackbar.
// Side effect: PUT dna; conflict expected_version → báo tải lại.
// =====================================================================
async function saveDna() {
  if (!style.value) return
  savingDna.value = true
  try {
    const data = await visualStylesService.updateDna(styleId.value, {
      dna: dna.value,
      expected_version: style.value.version ?? 1,
    })

    style.value = data
    dna.value = data.dna_json ? { ...data.dna_json } : {}
    showSnackbar('Đã lưu Style DNA.')
  }
  catch (reason) {
    const message = reason?.data?.message ?? 'Lưu DNA thất bại.'
    if (reason?.status === 422 || /expected_version|version/i.test(message))
      showSnackbar('DNA đã thay đổi ở phiên khác — tải lại trước khi lưu.', 'warning')
    else
      showSnackbar(message, 'error')
  }
  finally {
    savingDna.value = false
  }
}

// =====================================================================
// Input: không có. Output: style status=active; snackbar.
// Side effect: POST activate; lỗi lifecycle → snackbar error.
// =====================================================================
async function activate() {
  activating.value = true
  try {
    const data = await visualStylesService.activate(styleId.value)

    style.value = data
    showSnackbar('Đã activate style.')
  }
  catch (reason) {
    showSnackbar(reason?.data?.message ?? 'Activate thất bại.', 'error')
  }
  finally {
    activating.value = false
  }
}

// =====================================================================
// Input: shot hoặc null. Output: mở dialog ở chế độ sửa/thêm.
// =====================================================================
function openShotDialog(shot = null) {
  editingShot.value = shot
  shotDialogOpen.value = true
}

// =====================================================================
// Input: payload từ ShotRecipeDialog. Output: shots reload; đóng dialog.
// Side effect: POST/PUT shot; lỗi → snackbar.
// =====================================================================
async function saveShot(payload) {
  savingShot.value = true
  try {
    if (payload.id)
      await visualStylesService.updateShot(styleId.value, payload.id, payload)
    else
      await visualStylesService.createShot(styleId.value, payload)
    shotDialogOpen.value = false
    showSnackbar(payload.id ? 'Đã lưu recipe.' : 'Đã tạo recipe.')
    await load()
  }
  catch (reason) {
    showSnackbar(reason?.data?.message ?? 'Lưu recipe thất bại.', 'error')
  }
  finally {
    savingShot.value = false
  }
}

// =====================================================================
// Input: shot. Output: mở confirm xóa.
// =====================================================================
function confirmDeleteShot(shot) {
  deletingShot.value = shot
  confirmDeleteOpen.value = true
}

// =====================================================================
// Input: deletingShot. Output: xóa + reload; snackbar.
// =====================================================================
async function deleteShot() {
  if (!deletingShot.value) return
  savingShot.value = true
  try {
    await visualStylesService.deleteShot(styleId.value, deletingShot.value.id)
    showSnackbar('Đã xóa recipe.')
    await load()
  }
  catch (reason) {
    showSnackbar(reason?.data?.message ?? 'Xóa recipe thất bại.', 'error')
  }
  finally {
    savingShot.value = false
    deletingShot.value = null
  }
}

// =====================================================================
// Input: index + hướng (-1/+1). Output: hoán đổi sort_order cục bộ rồi persist.
// =====================================================================
function moveShot(index, direction) {
  const target = index + direction
  if (target < 0 || target >= shots.value.length) return
  const next = [...shots.value]
  const temp = next[index]

  next[index] = next[target]
  next[target] = temp
  shots.value = next
  persistOrder()
}

// =====================================================================
// Input: thứ tự shots hiện tại. Output: POST reorder ordered_ids.
// Side effect: lỗi → reload để khôi phục thứ tự server.
// =====================================================================
async function persistOrder() {
  try {
    const ordered = shots.value.map(shot => shot.id)

    await visualStylesService.reorderShots(styleId.value, ordered)
  }
  catch {
    await load()
    showSnackbar('Sắp xếp thất bại — đã tải lại thứ tự.', 'error')
  }
}

watch(() => route.params.id, id => {
  if (id) void load()
})

onMounted(load)
onScopeDispose(() => clearTimeout(pollTimer))
</script>

<template>
  <div class="visual-style-edit-page">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-space-between gap-y-4 gap-x-6 mb-6">
      <div>
        <h4 class="text-h4 font-weight-medium">
          Chỉnh sửa Visual Style
        </h4>
        <div class="text-body-1">
          {{ style?.name || 'Đang tải...' }}
          <VChip
            :color="statusMeta.color"
            variant="tonal"
            size="small"
            class="ms-2"
          >
            {{ statusMeta.label }}
          </VChip>
          <span
            v-if="style?.version"
            class="text-caption text-medium-emphasis ms-2"
          >v{{ style.version }}</span>
        </div>
      </div>
      <div class="d-flex flex-wrap align-center gap-3">
        <VBtn
          color="secondary"
          variant="tonal"
          prepend-icon="tabler-arrow-left"
          :to="{ name: 'visual-styles' }"
        >
          Danh sách
        </VBtn>
        <VBtn
          color="primary"
          prepend-icon="tabler-bolt"
          :loading="activating"
          :disabled="!canActivate || activating"
          @click="activate"
        >
          Activate
        </VBtn>
      </div>
    </div>

    <VAlert
      v-if="error"
      type="error"
      variant="tonal"
      class="mb-6"
    >
      {{ error }}
      <VBtn
        size="small"
        variant="text"
        class="ms-2"
        @click="load"
      >
        Thử lại
      </VBtn>
    </VAlert>

    <!-- Đang phân tích -->
    <VAlert
      v-if="isProcessing"
      type="info"
      variant="tonal"
      class="mb-6"
    >
      <div class="d-flex align-center">
        <VProgressCircular
          indeterminate
          size="20"
          width="2"
          class="me-3"
        />
        Đang phân tích ảnh mẫu thành Style DNA — trang tự cập nhật khi xong.
      </div>
    </VAlert>

    <!-- Lỗi phân tích -->
    <VAlert
      v-if="style?.status === 'failed' && style?.error_message"
      type="error"
      variant="tonal"
      class="mb-6"
    >
      Phân tích thất bại: {{ style.error_message }}
    </VAlert>

    <VRow>
      <!-- Metadata -->
      <VCol
        cols="12"
        md="4"
      >
        <VCard
          title="Thông tin style"
          class="mb-6"
        >
          <VCardText>
            <AppTextField
              v-model="meta.name"
              label="Tên style"
              class="mb-4"
            />
            <AppTextField
              v-model="meta.category"
              label="Nhóm phong cách"
              class="mb-4"
            />
            <VSwitch
              v-model="meta.is_enabled"
              label="Bật dùng cho generation"
              color="primary"
              :disabled="style?.status !== 'active'"
              :hint="style?.status === 'active' ? 'Style đang active.' : 'Chỉ bật khi style đã activate.'"
              persistent-hint
              class="mb-4"
            />
            <VBtn
              block
              color="primary"
              :loading="savingMeta"
              :disabled="savingMeta || isProcessing"
              @click="saveMeta"
            >
              Lưu thông tin
            </VBtn>
          </VCardText>
        </VCard>

        <VCard
          v-if="style?.summary"
          title="Tóm tắt phân tích"
          class="mb-6"
        >
          <VCardText>
            <div class="text-body-2">
              {{ style.summary }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- DNA + Recipes -->
      <VCol
        cols="12"
        md="8"
      >
        <!-- DNA -->
        <VCard class="mb-6">
          <VCardItem>
            <template #title>
              Style DNA
            </template>
            <template #append>
              <VBtn
                color="primary"
                size="small"
                :loading="savingDna"
                :disabled="savingDna || isProcessing || !hasDna"
                @click="saveDna"
              >
                Lưu DNA
              </VBtn>
            </template>
          </VCardItem>
          <VCardText>
            <VAlert
              v-if="!hasDna"
              type="warning"
              variant="tonal"
              density="compact"
              class="mb-4"
            >
              Chưa có Style DNA — tạo từ ảnh (phân tích) hoặc chờ worker hoàn tất.
            </VAlert>
            <DnaEditForm
              v-else
              v-model:dna="dna"
              :saving="savingDna"
              @save="saveDna"
            />
          </VCardText>
        </VCard>

        <!-- Shot Recipes -->
        <VCard>
          <VCardItem>
            <template #title>
              Shot Recipes
              <VChip
                size="small"
                color="primary"
                variant="tonal"
                class="ms-2"
              >
                {{ shots.length }}
              </VChip>
            </template>
            <template #append>
              <VBtn
                color="primary"
                size="small"
                prepend-icon="tabler-plus"
                :disabled="isProcessing"
                @click="openShotDialog(null)"
              >
                Thêm recipe
              </VBtn>
            </template>
          </VCardItem>
          <VCardText>
            <div
              v-if="!shots.length"
              class="text-center pa-8 text-medium-emphasis"
            >
              <VIcon
                size="48"
                icon="tabler-camera-off"
                class="mb-2"
              />
              <div class="text-subtitle-1 font-weight-medium">
                Chưa có shot recipe
              </div>
              <div class="text-body-2">
                Thêm recipe để định nghĩa các góc chụp khác nhau giữ nguyên Style DNA.
              </div>
            </div>
            <VTable
              v-else
              density="comfortable"
            >
              <thead>
                <tr>
                  <th style="width: 80px">
                    #
                  </th>
                  <th>Code</th>
                  <th>Tên</th>
                  <th>Ratio</th>
                  <th>Active</th>
                  <th style="width: 170px">
                    Thao tác
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="(shot, index) in shots"
                  :key="shot.id"
                >
                  <td>
                    <div class="d-flex flex-column">
                      <VBtn
                        icon="tabler-chevron-up"
                        variant="text"
                        size="x-small"
                        :disabled="index === 0"
                        @click="moveShot(index, -1)"
                      />
                      <VBtn
                        icon="tabler-chevron-down"
                        variant="text"
                        size="x-small"
                        :disabled="index === shots.length - 1"
                        @click="moveShot(index, 1)"
                      />
                    </div>
                  </td>
                  <td>
                    <code class="text-body-2">{{ shot.code }}</code>
                  </td>
                  <td>
                    <div class="text-body-2 font-weight-medium">
                      {{ shot.name }}
                    </div>
                    <div
                      v-if="shot.description"
                      class="text-caption text-medium-emphasis text-truncate"
                      style="max-inline-size: 240px;"
                    >
                      {{ shot.description }}
                    </div>
                  </td>
                  <td>{{ shot.suggested_aspect_ratio || '—' }}</td>
                  <td>
                    <VIcon
                      :icon="shot.is_active ? 'tabler-check' : 'tabler-x'"
                      :color="shot.is_active ? 'success' : 'secondary'"
                      size="20"
                    />
                  </td>
                  <td>
                    <VBtn
                      icon="tabler-edit"
                      variant="text"
                      size="small"
                      @click="openShotDialog(shot)"
                    />
                    <VBtn
                      icon="tabler-trash"
                      variant="text"
                      size="small"
                      color="error"
                      @click="confirmDeleteShot(shot)"
                    />
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Dialog recipe -->
    <ShotRecipeDialog
      v-model="shotDialogOpen"
      :shot="editingShot"
      :saving="savingShot"
      @save="saveShot"
      @after-leave="editingShot = null"
    />

    <!-- Confirm xóa recipe -->
    <VDialog
      v-model="confirmDeleteOpen"
      max-width="420"
      scrollable
      :persistent="savingShot"
    >
      <AppDialogLayout
        title="Xóa recipe"
        :close-disabled="savingShot"
        @close="confirmDeleteOpen = false"
      >
        <VCardText>
          <div class="text-body-1">
            Xóa recipe “{{ deletingShot?.name }}”? Thao tác không thể hoàn tác.
          </div>
        </VCardText>
        <template #footer>
          <VCardActions class="justify-end">
            <VBtn
              color="secondary"
              variant="tonal"
              :disabled="savingShot"
              @click="confirmDeleteOpen = false"
            >
              Hủy
            </VBtn>
            <VBtn
              color="error"
              :loading="savingShot"
              :disabled="savingShot"
              @click="deleteShot(); confirmDeleteOpen = false"
            >
              Xóa
            </VBtn>
          </VCardActions>
        </template>
      </AppDialogLayout>
    </VDialog>

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
.visual-style-edit-page {
  min-inline-size: 0;
}
</style>
