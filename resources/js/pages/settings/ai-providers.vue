<!--
  =====================================================================
  CHỨC NĂNG FILE: Trang Settings quản trị connection và catalog model.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE:
  - providerModels(provider): lọc model theo tìm kiếm của từng provider.
  - statusType(provider): chọn loại thông báo trạng thái kết nối.
  - providerDescription(provider), providerIcon(provider), providerIconColor(provider): mô tả và biểu tượng provider.
  - providerAvailabilityPercent(provider), providerAvailabilityLabel(provider): tính mức khả dụng của catalog.
  - providerCapability(provider, capability), formatDate(value): kiểm tra capability và định dạng thời gian.
  - perform(action, options): điều phối thao tác API, loading và feedback.
  - refreshCatalog(), selectProvider(provider), showSnackbar(): tải catalog, chọn provider và hiển thị thông báo.
  - openNew(), editProvider(provider), saveProviderForm(payload): tạo/sửa connection provider.
  - test(provider), sync(provider), disable(provider): kiểm tra, đồng bộ và tắt provider.
  - openModel(provider, model), saveModelForm(payload), testModel(provider, model): thêm/sửa capability và test model.
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT: catalog provider/model từ composable và thao tác quản trị.
  - OUTPUT: dialog tạo/sửa, catalog phản hồi và thông báo kết quả.
  SIDE EFFECT: gọi composable API; không tự chứa HTTP hoặc API key.
  EXCEPTION/TRANSACTION: page không mở transaction; API/error lifecycle do composable xử lý.
  =====================================================================
-->
<script setup>
import { computed, onMounted, ref } from 'vue'
import { useAiProviderSettings } from '@/composables/useAiProviderSettings'
import { alertColors, getAlertColor } from '@/config/alertColors'
import AiModelDialog from '@/views/settings/ai/AiModelDialog.vue'
import AiProviderConnectionDialog from '@/views/settings/ai/AiProviderConnectionDialog.vue'

definePage({ meta: { action: 'manage', subject: 'ai_settings' } })

const { providers, presets, loading, saving, error, load, saveProvider, disableProvider, testProvider, syncProvider, saveModel } = useAiProviderSettings()
const providerDialog = ref(false)
const modelDialog = ref(false)
const selectedProvider = ref(null)
const editingProvider = ref(null)
const selectedModel = ref(null)
const modelTestStatuses = ref({})
const snackbar = ref({ visible: false, message: '', color: alertColors.completed })
const busyId = ref(null)
const modelSearch = ref({})
const searchQuery = ref('')
const statusFilter = ref('all')
const activeDetailTab = ref('overview')

const statusFilters = [
  { title: 'Tất cả trạng thái', value: 'all' },
  { title: 'Đang hoạt động', value: 'active' },
  { title: 'Đã tắt', value: 'inactive' },
]

/**
 * =====================================================================
 * CHỨC NĂNG: Lọc model theo ô tìm kiếm của từng provider
 * =====================================================================
 * INPUT: provider catalog và search text theo provider ID.
 * OUTPUT: danh sách model khớp label hoặc remote ID.
 * SIDE EFFECT: chỉ đọc reactive state; không gọi API.
 * EXCEPTION/TRANSACTION: không mở transaction.
 * =====================================================================
 */
const providerModels = provider => {
  if (!provider) return []

  const query = (modelSearch.value[provider.id] ?? '').trim().toLowerCase()

  return (provider.models ?? []).filter(model => !query || `${model.label} ${model.remote_model_id}`.toLowerCase().includes(query))
}

/**
 * =====================================================================
 * CHỨC NĂNG: Resolve loại thông báo theo trạng thái kiểm tra provider
 * =====================================================================
 * INPUT: provider test_status.
 * OUTPUT: loại thông báo Vuetify success/error/warning.
 * SIDE EFFECT: hàm thuần, không gọi API.
 * EXCEPTION/TRANSACTION: không mở transaction.
 * =====================================================================
 */
const statusType = provider => provider.test_status === 'success' ? 'success' : provider.test_status === 'failed' ? 'error' : 'warning'

/**
 * =====================================================================
 * CHỨC NĂNG: Các computed phục vụ dashboard provider
 * =====================================================================
 * INPUT: reactive provider catalog và search/filter của giao diện.
 * OUTPUT: số liệu KPI, provider đang chọn và danh sách đã lọc.
 * SIDE EFFECT: computed chỉ đọc state; không gọi API.
 * EXCEPTION/TRANSACTION: không mở transaction.
 * =====================================================================
 */
const activeProviderCount = computed(() => providers.value.filter(provider => provider.is_active).length)
const inactiveProviderCount = computed(() => providers.value.length - activeProviderCount.value)
const availableModelCount = computed(() => providers.value.reduce((total, provider) => total + (provider.models ?? []).filter(model => model.is_enabled && model.is_available).length, 0))
const totalModelCount = computed(() => providers.value.reduce((total, provider) => total + (provider.models ?? []).length, 0))
const selectedProviderDetail = computed(() => providers.value.find(provider => provider.id === selectedProvider.value?.id) ?? null)

const filteredProviders = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()

  return providers.value.filter(provider => {
    const matchesStatus = statusFilter.value === 'all'
      || (statusFilter.value === 'active' && provider.is_active)
      || (statusFilter.value === 'inactive' && !provider.is_active)

    const searchable = `${provider.name} ${provider.driver} ${provider.base_url}`.toLowerCase()

    return matchesStatus && (!query || searchable.includes(query))
  })
})

const modelHeaders = [
  { title: 'Model', key: 'label' },
  { title: 'Capabilities', key: 'capabilities', sortable: false },
  { title: 'Catalog', key: 'is_available' },
  { title: 'Thao tác', key: 'actions', sortable: false, align: 'end' },
]

// INPUT: capability key từ catalog; OUTPUT: nhãn thống nhất với dialog chỉnh sửa model.
const capabilityLabels = {
  'text_generation': 'Tạo nội dung',
  'structured_output': 'Structured output',
  'image_generation': 'Tạo ảnh',
  vision: 'Vision',
  embedding: 'Embedding',
}

const providerDescription = provider => provider.kind === 'official'
  ? `Kết nối chính thức qua driver ${provider.driver}.`
  : 'Gateway OpenAI-compatible với catalog model từ endpoint.'

const providerIcon = provider => provider.driver === 'gemini'
  ? 'tabler-brand-google'
  : provider.kind === 'official' ? 'tabler-sparkles' : 'tabler-cloud-network'

const providerIconColor = provider => provider.driver === 'gemini'
  ? 'error'
  : provider.kind === 'official' ? 'primary' : 'info'

const providerAvailabilityPercent = provider => {
  const models = provider?.models ?? []
  if (!models.length) return 0

  const available = models.filter(model => model.is_enabled && model.is_available).length

  return Math.round(available / models.length * 100)
}

const providerAvailabilityLabel = provider => {
  const models = provider?.models ?? []
  const available = models.filter(model => model.is_enabled && model.is_available).length

  return `${available}/${models.length} model khả dụng`
}

const providerCapability = (provider, capability) => (provider?.models ?? []).some(model => model.capabilities?.includes(capability))

const formatDate = value => value
  ? new Intl.DateTimeFormat('vi-VN', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
  : 'Chưa có'

const statCards = computed(() => [
  {
    title: 'Tổng providers',
    value: String(providers.value.length),
    caption: `${activeProviderCount.value} đang hoạt động`,
    captionClass: 'text-success',
    icon: 'tabler-plug-connected',
    color: 'primary',
  },
  {
    title: 'Model khả dụng',
    value: String(availableModelCount.value),
    caption: `${totalModelCount.value} model trong catalog`,
    captionClass: 'text-info',
    icon: 'tabler-cpu',
    color: 'info',
  },
])

/**
 * =====================================================================
 * CHỨC NĂNG: Điều phối feedback/loading chung cho thao tác settings.
 * =====================================================================
 * INPUT: API action và success callback cục bộ.
 * OUTPUT: snackbar nhất quán, không để spinner treo hoặc chạy chồng thao tác.
 * SIDE EFFECT: gọi composable API; cập nhật snackbar/dialog sau khi thành công.
 * EXCEPTION/TRANSACTION: lỗi API hiển thị message an toàn; finally luôn dọn busyId.
 * =====================================================================
 */
async function perform(action, { key, message, after } = {}) {
  if (busyId.value) return
  busyId.value = key ?? 'settings-save'
  snackbar.value.visible = false
  try {
    const result = await action()

    await after?.(result)
    showSnackbar(typeof message === 'function' ? message(result) : message ?? result?.message ?? 'Đã hoàn tất.')
  }
  catch (reason) {
    showSnackbar(reason?.data?.message ?? reason?.message ?? 'Thao tác AI thất bại.', 'error')
  }
  finally {
    busyId.value = null
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Reload catalog và giữ provider đang được chọn
 * =====================================================================
 * INPUT: catalog provider từ backend.
 * OUTPUT: danh sách mới và selected provider hợp lệ.
 * SIDE EFFECT: gọi composable load(); không chứa HTTP trực tiếp.
 * EXCEPTION/TRANSACTION: lỗi được ném lại để caller hiển thị snackbar.
 * =====================================================================
 */
async function refreshCatalog() {
  const selectedId = selectedProvider.value?.id

  await load()
  selectedProvider.value = providers.value.find(provider => provider.id === selectedId) ?? providers.value[0] ?? null
}

/**
 * =====================================================================
 * CHỨC NĂNG: Chọn provider để hiển thị panel chi tiết
 * =====================================================================
 * INPUT: provider catalog item.
 * OUTPUT: detail panel đồng bộ provider được chọn.
 * SIDE EFFECT: đổi state giao diện; không gọi API.
 * EXCEPTION/TRANSACTION: không mở transaction.
 * =====================================================================
 */
function selectProvider(provider) {
  selectedProvider.value = provider
  activeDetailTab.value = 'overview'
}

/**
 * =====================================================================
 * CHỨC NĂNG: Hiển thị thông báo bằng snackbar theo màu trạng thái chung
 * =====================================================================
 * INPUT: nội dung và loại thông báo.
 * OUTPUT: snackbar ở góc trên bên phải.
 * SIDE EFFECT: cập nhật feedback state; không gọi API.
 * EXCEPTION/TRANSACTION: không mở transaction.
 * =====================================================================
 */
function showSnackbar(message, type = 'success') {
  snackbar.value = { visible: true, message, color: getAlertColor(type) }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Mở dialog tạo provider mới
 * =====================================================================
 * INPUT: không có.
 * OUTPUT: dialog tạo mới độc lập với provider được chọn ở detail panel.
 * SIDE EFFECT: reset editing provider và đổi state dialog sau khi catalog đã tải.
 * EXCEPTION/TRANSACTION: không gọi API hoặc mở transaction.
 * =====================================================================
 */
function openNew() {
  if (loading.value || saving.value) return
  editingProvider.value = null
  providerDialog.value = true
}

/**
 * =====================================================================
 * CHỨC NĂNG: Mở dialog sửa connection đã chọn
 * =====================================================================
 * INPUT: provider catalog item.
 * OUTPUT: dialog nhận provider hiện tại để edit metadata/key write-only.
 * SIDE EFFECT: đổi state dialog; không ghi API key vào frontend state ngoài draft dialog.
 * EXCEPTION/TRANSACTION: không gọi API hoặc mở transaction.
 * =====================================================================
 */
function editProvider(provider) {
  selectedProvider.value = provider
  editingProvider.value = provider
  providerDialog.value = true
}

/**
 * =====================================================================
 * CHỨC NĂNG: Lưu provider và đóng dialog khi thành công
 * =====================================================================
 * INPUT: metadata và optional write-only api_key từ dialog.
 * OUTPUT: snackbar thành công hoặc lỗi an toàn.
 * SIDE EFFECT: gọi composable, cập nhật catalog reactive và đóng dialog.
 * EXCEPTION/TRANSACTION: perform() luôn dọn busy state; lỗi API không bị nuốt.
 * =====================================================================
 */
function saveProviderForm(payload) {
  return perform(() => saveProvider(payload, editingProvider.value?.id), {
    message: 'Đã lưu provider.',
    after: savedProvider => {
      providerDialog.value = false
      selectedProvider.value = savedProvider
    },
  })
}

/**
 * =====================================================================
 * CHỨC NĂNG: Test connection provider
 * =====================================================================
 * INPUT: provider catalog item.
 * OUTPUT: snackbar test và catalog được reload.
 * SIDE EFFECT: gọi endpoint test server-side; không hiển thị key.
 * EXCEPTION/TRANSACTION: lỗi API hiển thị trong snackbar; perform() dọn busy state.
 * =====================================================================
 */
function test(provider) {
  return perform(() => testProvider(provider.id), { key: `provider-test-${provider.id}`, after: refreshCatalog })
}

/**
 * =====================================================================
 * CHỨC NĂNG: Đồng bộ model catalog provider
 * =====================================================================
 * INPUT: provider catalog item.
 * OUTPUT: snackbar số model imported/unavailable.
 * SIDE EFFECT: gọi endpoint sync server-side; backend giữ catalog cũ nếu sync lỗi.
 * EXCEPTION/TRANSACTION: lỗi API hiển thị trong snackbar; perform() dọn busy state.
 * =====================================================================
 */
function sync(provider) {
  return perform(() => syncProvider(provider.id), {
    key: `sync-${provider.id}`,
    message: result => `Đã đồng bộ ${result.count ?? 0} model.`,
  })
}

/**
 * =====================================================================
 * CHỨC NĂNG: Disable mềm provider nhưng giữ model cho audit
 * =====================================================================
 * INPUT: provider catalog item.
 * OUTPUT: provider inactive trong catalog.
 * SIDE EFFECT: hỏi xác nhận rồi gọi endpoint disable.
 * EXCEPTION/TRANSACTION: người dùng từ chối thì không gọi API; lỗi hiển thị trong snackbar.
 * =====================================================================
 */
function disable(provider) {
  if (!window.confirm(`Tắt ${provider.name}? Model vẫn được giữ để audit.`)) return

  return perform(() => disableProvider(provider.id), { message: 'Đã tắt provider.' })
}

/**
 * =====================================================================
 * CHỨC NĂNG: Mở dialog thêm hoặc chỉnh sửa model catalog
 * =====================================================================
 * INPUT: provider catalog và model cần sửa; null khi thêm mới.
 * OUTPUT: dialog nhận model hiện tại để sửa capability hoặc form thêm mới.
 * SIDE EFFECT: đổi state dialog; không gọi endpoint.
 * EXCEPTION/TRANSACTION: không mở transaction.
 * =====================================================================
 */
function openModel(provider, model = null) {
  selectedProvider.value = provider
  selectedModel.value = model
  modelDialog.value = true
}

/**
 * =====================================================================
 * CHỨC NĂNG: Thêm model thủ công hoặc lưu chỉnh sửa capability
 * =====================================================================
 * INPUT: model payload từ AiModelDialog và ID model đang chỉnh sửa nếu có.
 * OUTPUT: catalog reload và snackbar thành công; model cũ được cập nhật đúng ID.
 * SIDE EFFECT: gọi composable model API, cập nhật catalog và đóng dialog.
 * EXCEPTION/TRANSACTION: lỗi validation/API hiển thị trong snackbar.
 * =====================================================================
 */
function saveModelForm(payload) {
  return perform(() => saveModel(selectedProvider.value.id, payload, selectedModel.value?.id ?? null), {
    message: 'Đã lưu model.',
    after: () => { modelDialog.value = false },
  })
}

/**
 * =====================================================================
 * CHỨC NĂNG: Test một model catalog cụ thể
 * =====================================================================
 * INPUT: provider và model catalog item.
 * OUTPUT: snackbar và trạng thái test ngay tại model; không tải lại catalog.
 * SIDE EFFECT: gọi request model test server-side; giữ nguyên tab/filter hiện tại.
 * EXCEPTION/TRANSACTION: lỗi hiển thị trong snackbar, đánh dấu thất bại và luôn dọn busyId.
 * =====================================================================
 */
async function testModel(provider, model) {
  if (busyId.value) return

  busyId.value = `model-test-${model.id}`
  modelTestStatuses.value[model.id] = 'testing'
  snackbar.value.visible = false

  try {
    const result = await testProvider(provider.id, model.id)

    modelTestStatuses.value[model.id] = 'success'
    showSnackbar(result?.message ?? `Model ${model.label || model.remote_model_id} đã phản hồi thành công.`)
  }
  catch (reason) {
    modelTestStatuses.value[model.id] = 'failed'
    showSnackbar(reason?.data?.message ?? reason?.message ?? 'Kiểm tra model thất bại.', 'error')
  }
  finally {
    busyId.value = null
  }
}

onMounted(() => refreshCatalog().catch(() => showSnackbar(error.value || 'Không thể tải cấu hình AI.', 'error')))
</script>

<template>
  <div class="ai-providers-page">

      <VRow>
        <VCol cols="12">
          <div class="d-flex flex-wrap justify-space-between gap-y-4 gap-x-6 mb-6">
            <div>
              <h4 class="text-h4 font-weight-medium">
                AI Providers
              </h4>
              <div class="text-body-1">
                Quản lý connection, API key và catalog model cho luồng tạo nội dung, hình ảnh.
              </div>
            </div>
            <VBtn
              prepend-icon="tabler-plus"
              :disabled="loading || saving"
              @click="openNew"
            >
              Thêm provider
            </VBtn>
          </div>
        </VCol>

        <VCol
          v-for="stat in statCards"
          :key="stat.title"
          cols="12"
          sm="6"
          lg="6"
        >
          <VCard
            border
            elevation="0"
            class="h-100"
          >
            <VCardText class="d-flex align-center justify-space-between">
              <div>
                <div class="text-body-2 text-medium-emphasis mb-1">
                  {{ stat.title }}
                </div>
                <div class="text-h4 font-weight-bold">
                  {{ stat.value }}
                </div>
                <div
                  class="text-caption mt-1"
                  :class="stat.captionClass"
                >
                  {{ stat.caption }}
                </div>
              </div>
              <VAvatar
                :color="stat.color"
                variant="tonal"
                rounded="lg"
                size="46"
              >
                <VIcon :icon="stat.icon" />
              </VAvatar>
            </VCardText>
          </VCard>
        </VCol>

        <VCol
          cols="12"
          lg="7"
        >
          <VCard
            border
            elevation="0"
            class="h-100"
          >
            <VCardItem>
              <template #prepend>
                <VAvatar
                  color="primary"
                  variant="tonal"
                  rounded="lg"
                  size="40"
                >
                  <VIcon icon="tabler-building-store" />
                </VAvatar>
              </template>
              <VCardTitle>Providers</VCardTitle>
              <VCardSubtitle>{{ activeProviderCount }} active · {{ inactiveProviderCount }} inactive</VCardSubtitle>
              <template #append>
                <VChip
                  color="primary"
                  variant="tonal"
                  size="small"
                >
                  {{ providers.length }} kết nối
                </VChip>
              </template>
            </VCardItem>

            <VCardText class="pt-0">
              <div class="d-flex flex-wrap align-center gap-3 mb-4">
                <VTextField
                  v-model="searchQuery"
                  prepend-inner-icon="tabler-search"
                  placeholder="Tìm provider, driver hoặc endpoint..."
                  density="compact"
                  variant="outlined"
                  hide-details
                  class="flex-grow-1"
                  style="min-inline-size: 220px;"
                />
                <VSelect
                  v-model="statusFilter"
                  :items="statusFilters"
                  item-title="title"
                  item-value="value"
                  density="compact"
                  variant="outlined"
                  hide-details
                  style="min-inline-size: 180px;"
                />
              </div>

              <VProgressLinear
                v-if="loading"
                color="primary"
                indeterminate
                rounded
                class="mb-4"
              />

              <VAlert
                v-if="!filteredProviders.length && !loading"
                type="info"
                :color="alertColors.completed"
                variant="tonal"
                class="mb-0"
              >
                Chưa có provider phù hợp. Hãy thêm provider hoặc thay đổi bộ lọc.
              </VAlert>

              <div class="d-flex flex-column gap-3">
                <VCard
                  v-for="provider in filteredProviders"
                  :key="provider.id"
                  variant="outlined"
                  class="provider-item pa-4"
                  :class="{ 'provider-active': selectedProviderDetail?.id === provider.id }"
                  @click="selectProvider(provider)"
                >
                  <div class="d-flex flex-wrap align-center gap-3">
                    <div class="d-flex align-center provider-summary">
                      <VAvatar
                        :color="providerIconColor(provider)"
                        variant="tonal"
                        rounded="lg"
                        size="42"
                        class="me-3"
                      >
                        <VIcon :icon="providerIcon(provider)" />
                      </VAvatar>
                      <div class="min-width-0">
                        <div class="d-flex align-center flex-wrap gap-2">
                          <span class="text-body-1 font-weight-bold text-truncate">{{ provider.name }}</span>
                          <VChip
                            :color="provider.is_active ? 'success' : 'secondary'"
                            size="x-small"
                            variant="tonal"
                          >
                            {{ provider.is_active ? 'Active' : 'Inactive' }}
                          </VChip>
                        </div>
                        <div class="text-caption text-medium-emphasis text-truncate">
                          {{ providerDescription(provider) }}
                        </div>
                      </div>
                    </div>

                    <div class="provider-status">
                      <div class="text-caption text-medium-emphasis mb-1">
                        Connection
                      </div>
                      <VChip
                        :color="getAlertColor(statusType(provider))"
                        size="small"
                        variant="tonal"
                      >
                        {{ provider.test_status === 'success' ? 'Healthy' : provider.test_status === 'failed' ? 'Failed' : 'Pending' }}
                      </VChip>
                    </div>

                    <div class="provider-coverage">
                      <div class="d-flex align-center justify-space-between text-caption mb-1">
                        <span class="text-medium-emphasis">Model catalog</span>
                        <span class="font-weight-medium">{{ providerAvailabilityLabel(provider) }}</span>
                      </div>
                      <VProgressLinear
                        :model-value="providerAvailabilityPercent(provider)"
                        :color="provider.is_active ? 'primary' : 'secondary'"
                        height="6"
                        rounded
                      />
                    </div>

                    <div class="d-flex align-center ms-auto">
                      <VBtn
                        icon="tabler-pencil"
                        variant="text"
                        size="small"
                        :aria-label="`Sửa ${provider.name}`"
                        @click.stop="editProvider(provider)"
                      />
                      <VMenu location="bottom end">
                        <template #activator="{ props: menuProps }">
                          <VBtn
                            v-bind="menuProps"
                            icon="tabler-dots-vertical"
                            variant="text"
                            size="small"
                            aria-label="Thao tác provider"
                            @click.stop
                          />
                        </template>
                        <VList density="compact">
                          <VListItem @click="test(provider)">
                            <template #prepend>
                              <VIcon icon="tabler-plug" />
                            </template>
                            <VListItemTitle>Test connection</VListItemTitle>
                          </VListItem>
                          <VListItem @click="sync(provider)">
                            <template #prepend>
                              <VIcon icon="tabler-refresh" />
                            </template>
                            <VListItemTitle>Đồng bộ model</VListItemTitle>
                          </VListItem>
                          <VListItem @click="editProvider(provider)">
                            <template #prepend>
                              <VIcon icon="tabler-settings" />
                            </template>
                            <VListItemTitle>Chỉnh sửa</VListItemTitle>
                          </VListItem>
                          <VListItem
                            v-if="provider.is_active"
                            @click="disable(provider)"
                          >
                            <template #prepend>
                              <VIcon icon="tabler-player-stop" />
                            </template>
                            <VListItemTitle>Tắt provider</VListItemTitle>
                          </VListItem>
                        </VList>
                      </VMenu>
                    </div>
                  </div>
                </VCard>
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol
          cols="12"
          lg="5"
        >
          <VCard
            v-if="selectedProviderDetail"
            border
            elevation="0"
            class="provider-detail-card d-flex flex-column"
          >
            <VCardItem class="flex-shrink-0">
              <template #prepend>
                <VAvatar
                  :color="providerIconColor(selectedProviderDetail)"
                  variant="tonal"
                  rounded="lg"
                  size="42"
                >
                  <VIcon :icon="providerIcon(selectedProviderDetail)" />
                </VAvatar>
              </template>
              <VCardTitle>{{ selectedProviderDetail.name }}</VCardTitle>
              <VCardSubtitle>{{ selectedProviderDetail.driver }} · {{ selectedProviderDetail.kind }}</VCardSubtitle>
              <template #append>
                <VBtn
                  icon="tabler-pencil"
                  variant="text"
                  size="small"
                  aria-label="Sửa provider đang chọn"
                  @click="editProvider(selectedProviderDetail)"
                />
              </template>
            </VCardItem>

            <VTabs
              v-model="activeDetailTab"
              density="compact"
              class="px-4 flex-shrink-0"
            >
              <VTab value="overview">
                Overview
              </VTab>
              <VTab value="models">
                Models
              </VTab>
              <VTab value="api">
                API
              </VTab>
              <VTab value="advanced">
                Advanced
              </VTab>
            </VTabs>
            <VDivider class="flex-shrink-0" />

            <VWindow
              v-model="activeDetailTab"
              class="provider-detail-window"
            >
              <VWindowItem value="overview">
                <VCardText>
                  <div class="d-flex align-center justify-space-between mb-3">
                    <div>
                      <div class="text-subtitle-1 font-weight-bold">
                        Connection overview
                      </div>
                      <div class="text-caption text-medium-emphasis">
                        Thông số hiện tại của provider
                      </div>
                    </div>
                    <VChip
                      :color="selectedProviderDetail.is_active ? 'success' : 'secondary'"
                      size="small"
                      variant="tonal"
                    >
                      {{ selectedProviderDetail.is_active ? 'Active' : 'Inactive' }}
                    </VChip>
                  </div>

                  <VRow dense>
                    <VCol
                      cols="12"
                      sm="6"
                    >
                      <div class="detail-info pa-3 rounded">
                        <div class="text-caption text-medium-emphasis">
                          API key
                        </div>
                        <div class="text-body-2 font-weight-medium mt-1">
                          {{ selectedProviderDetail.has_api_key ? '••••••••••••••••' : 'Chưa cấu hình' }}
                        </div>
                        <div class="text-caption text-medium-emphasis mt-1">
                          Key chỉ được xử lý server-side
                        </div>
                      </div>
                    </VCol>
                    <VCol
                      cols="12"
                      sm="6"
                    >
                      <div class="detail-info pa-3 rounded">
                        <div class="text-caption text-medium-emphasis">
                          Base URL
                        </div>
                        <div class="text-body-2 font-weight-medium text-truncate mt-1">
                          {{ selectedProviderDetail.base_url || '—' }}
                        </div>
                        <div class="text-caption text-medium-emphasis mt-1">
                          {{ selectedProviderDetail.discovery_mode === 'models_endpoint' ? `Đồng bộ lần cuối: ${formatDate(selectedProviderDetail.last_synced_at)}` : 'Nhập model thủ công' }}
                        </div>
                      </div>
                    </VCol>
                  </VRow>

                  <div class="d-flex flex-wrap gap-2 mt-4">
                    <VChip
                      v-if="providerCapability(selectedProviderDetail, 'text_generation')"
                      color="primary"
                      size="small"
                      variant="tonal"
                    >
                      Text generation
                    </VChip>
                    <VChip
                      v-if="providerCapability(selectedProviderDetail, 'image_generation')"
                      color="info"
                      size="small"
                      variant="tonal"
                    >
                      Image generation
                    </VChip>
                    <VChip
                      v-if="providerCapability(selectedProviderDetail, 'vision')"
                      color="warning"
                      size="small"
                      variant="tonal"
                    >
                      Vision
                    </VChip>
                  </div>

                  <VCard
                    variant="outlined"
                    class="mt-4"
                  >
                    <VCardText>
                      <div class="d-flex align-center justify-space-between gap-3">
                        <div>
                          <div class="text-subtitle-2 font-weight-bold">
                            Model catalog
                          </div>
                          <div class="text-caption text-medium-emphasis mt-1">
                            {{ providerAvailabilityLabel(selectedProviderDetail) }} đã bật và sẵn sàng sử dụng.
                          </div>
                        </div>
                        <VBtn
                          variant="tonal"
                          color="primary"
                          size="small"
                          @click="activeDetailTab = 'models'"
                        >
                          Quản lý model
                        </VBtn>
                      </div>
                    </VCardText>
                  </VCard>

                  <div class="d-flex flex-wrap gap-2 mt-4">
                    <VBtn
                      color="primary"
                      prepend-icon="tabler-plug"
                      :loading="busyId === `provider-test-${selectedProviderDetail.id}`"
                      @click="test(selectedProviderDetail)"
                    >
                      Test connection
                    </VBtn>
                    <VBtn
                      variant="tonal"
                      prepend-icon="tabler-refresh"
                      :loading="busyId === `sync-${selectedProviderDetail.id}`"
                      @click="sync(selectedProviderDetail)"
                    >
                      Sync models
                    </VBtn>
                  </div>
                </VCardText>
              </VWindowItem>

              <VWindowItem value="models">
                <VCardText>
                  <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
                    <VTextField
                      v-model="modelSearch[selectedProviderDetail.id]"
                      prepend-inner-icon="tabler-search"
                      placeholder="Tìm model..."
                      density="compact"
                      variant="outlined"
                      hide-details
                      class="flex-grow-1"
                    />
                    <VBtn
                      color="primary"
                      prepend-icon="tabler-plus"
                      size="small"
                      @click="openModel(selectedProviderDetail)"
                    >
                      Thêm model
                    </VBtn>
                  </div>

                  <VDataTable
                    :headers="modelHeaders"
                    :items="providerModels(selectedProviderDetail)"
                    :loading="loading"
                    density="compact"
                    class="text-no-wrap"
                    hide-default-footer
                  >
                    <template #item.capabilities="{ item }">
                      <div class="d-flex flex-wrap gap-1 py-1">
                        <VChip
                          v-for="capability in item.capabilities"
                          :key="capability"
                          :color="capability === 'image_generation' ? 'info' : 'primary'"
                          size="x-small"
                          variant="tonal"
                        >
                          {{ capabilityLabels[capability] ?? capability }}
                        </VChip>
                        <span
                          v-if="!item.capabilities?.length"
                          class="text-caption text-medium-emphasis"
                        >
                          Chưa xác nhận
                        </span>
                      </div>
                    </template>
                    <template #item.is_available="{ item }">
                      <VChip
                        :color="item.is_available && item.is_enabled ? 'success' : 'secondary'"
                        size="x-small"
                        variant="tonal"
                      >
                        {{ item.is_available && item.is_enabled ? 'Available' : 'Disabled' }}
                      </VChip>
                    </template>
                    <template #item.actions="{ item }">
                      <div class="d-flex align-center justify-end gap-1">
                        <VBtn
                          icon="tabler-edit"
                          variant="text"
                          size="small"
                          :disabled="!!busyId || saving"
                          aria-label="Chỉnh sửa model"
                          title="Chỉnh sửa model và capabilities"
                          @click="openModel(selectedProviderDetail, item)"
                        />
                        <VBtn
                          icon="tabler-player-play"
                          variant="text"
                          size="small"
                          :loading="busyId === `model-test-${item.id}`"
                          aria-label="Test model"
                          @click="testModel(selectedProviderDetail, item)"
                        />
                        <VIcon
                          v-if="modelTestStatuses[item.id] === 'success'"
                          icon="tabler-check"
                          :color="alertColors.completed"
                          size="20"
                          aria-label="Test model thành công"
                        />
                        <VIcon
                          v-else-if="modelTestStatuses[item.id] === 'failed'"
                          icon="tabler-x"
                          :color="alertColors.danger"
                          size="20"
                          aria-label="Test model thất bại"
                        />
                      </div>
                    </template>
                    <template #no-data>
                      <div class="py-8 text-center text-medium-emphasis">
                        Provider chưa có model trong catalog.
                      </div>
                    </template>
                  </VDataTable>
                </VCardText>
              </VWindowItem>

              <VWindowItem value="api">
                <VCardText>
                  <div class="text-subtitle-1 font-weight-bold mb-1">
                    API connection
                  </div>
                  <div class="text-caption text-medium-emphasis mb-4">
                    API key không được hiển thị lại trên trình duyệt; chỉ metadata an toàn được render.
                  </div>
                  <VList
                    lines="two"
                    border
                    rounded
                  >
                    <VListItem>
                      <template #prepend>
                        <VIcon icon="tabler-code" />
                      </template>
                      <VListItemTitle>Driver</VListItemTitle>
                      <VListItemSubtitle>{{ selectedProviderDetail.driver }}</VListItemSubtitle>
                    </VListItem>
                    <VListItem>
                      <template #prepend>
                        <VIcon icon="tabler-link" />
                      </template>
                      <VListItemTitle>Endpoint</VListItemTitle>
                      <VListItemSubtitle class="text-break">
                        {{ selectedProviderDetail.base_url || '—' }}
                      </VListItemSubtitle>
                    </VListItem>
                    <VListItem>
                      <template #prepend>
                        <VIcon icon="tabler-list-search" />
                      </template>
                      <VListItemTitle>Discovery</VListItemTitle>
                      <VListItemSubtitle>{{ selectedProviderDetail.discovery_mode }}</VListItemSubtitle>
                    </VListItem>
                    <VListItem>
                      <template #prepend>
                        <VIcon icon="tabler-clock" />
                      </template>
                      <VListItemTitle>Thời gian chờ</VListItemTitle>
                      <VListItemSubtitle>{{ selectedProviderDetail.request_timeout }} giây · Thử lại thủ công khi mất kết nối</VListItemSubtitle>
                    </VListItem>
                    <VListItem>
                      <template #prepend>
                        <VIcon icon="tabler-clock-check" />
                      </template>
                      <VListItemTitle>Last tested</VListItemTitle>
                      <VListItemSubtitle>{{ formatDate(selectedProviderDetail.last_tested_at) }}</VListItemSubtitle>
                    </VListItem>
                  </VList>
                  <VBtn
                    color="primary"
                    variant="tonal"
                    prepend-icon="tabler-settings"
                    class="mt-4"
                    @click="editProvider(selectedProviderDetail)"
                  >
                    Chỉnh sửa connection
                  </VBtn>
                </VCardText>
              </VWindowItem>

              <VWindowItem value="advanced">
                <VCardText>
                  <div class="text-subtitle-1 font-weight-bold mb-1">
                    Advanced settings
                  </div>
                  <div class="text-caption text-medium-emphasis mb-4">
                    Trạng thái kiểm tra và các thao tác quản trị connection.
                  </div>
                  <VAlert
                    :type="statusType(selectedProviderDetail)"
                    :color="getAlertColor(statusType(selectedProviderDetail))"
                    variant="tonal"
                    class="mb-4"
                  >
                    <div class="font-weight-medium">
                      {{ selectedProviderDetail.test_status === 'success' ? 'Connection hoạt động.' : selectedProviderDetail.test_status === 'failed' ? 'Lần test gần nhất thất bại.' : 'Provider chưa được test.' }}
                    </div>
                    <div
                      v-if="selectedProviderDetail.test_message"
                      class="text-caption mt-1"
                    >
                      {{ selectedProviderDetail.test_message }}
                    </div>
                  </VAlert>
                  <div class="d-flex flex-wrap gap-2">
                    <VBtn
                      color="primary"
                      prepend-icon="tabler-settings"
                      @click="editProvider(selectedProviderDetail)"
                    >
                      Sửa thông số
                    </VBtn>
                    <VBtn
                      v-if="selectedProviderDetail.is_active"
                      color="error"
                      variant="tonal"
                      prepend-icon="tabler-player-stop"
                      @click="disable(selectedProviderDetail)"
                    >
                      Tắt provider
                    </VBtn>
                  </div>
                </VCardText>
              </VWindowItem>
            </VWindow>
          </VCard>

          <VCard
            v-else
            border
            elevation="0"
            class="provider-detail-card d-flex align-center justify-center"
          >
            <VCardText class="text-center py-12">
              <VAvatar
                color="primary"
                variant="tonal"
                size="64"
                class="mb-4"
              >
                <VIcon
                  icon="tabler-plug-connected-x"
                  size="32"
                />
              </VAvatar>
              <div class="text-h6 font-weight-bold">
                Chọn một provider
              </div>
              <div class="text-body-2 text-medium-emphasis mt-1">
                Panel chi tiết connection và model sẽ hiển thị tại đây.
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

  </div>

  <VSnackbar
    v-model="snackbar.visible"
    :color="snackbar.color"
    :timeout="4000"
    location="top end"
  >
    {{ snackbar.message }}
    <template #actions>
      <VBtn
        icon="tabler-x"
        variant="text"
        size="small"
        aria-label="Đóng thông báo"
        @click="snackbar.visible = false"
      />
    </template>
  </VSnackbar>

  <AiProviderConnectionDialog
    v-model="providerDialog"
    :provider="editingProvider"
    :presets="presets"
    :saving="saving"
    @save="saveProviderForm"
  />

  <AiModelDialog
    v-model="modelDialog"
    :provider="selectedProvider"
    :model="selectedModel"
    :saving="saving || busyId === 'settings-save'"
    @save="saveModelForm"
  />
</template>

<style scoped>
.ai-providers-page {
  min-block-size: 100%;
}

/* Giữ panel ổn định khi chuyển tab; nội dung dài chỉ cuộn trong vùng tab. */
.provider-detail-card {
  block-size: 640px;
}

.provider-detail-window {
  flex: 1 1 0;
  min-block-size: 0;
  overflow-x: hidden;
  overflow-y: auto;
  scrollbar-gutter: stable;
}

.provider-item {
  border-color: rgba(var(--v-border-color), var(--v-border-opacity));
  cursor: pointer;
  transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
}

.provider-item:hover,
.provider-active {
  border-color: rgb(var(--v-theme-primary)) !important;
  box-shadow: 0 4px 18px rgba(var(--v-theme-primary), 0.12);
}

.provider-active {
  background: rgba(var(--v-theme-primary), 0.04);
}

.provider-summary {
  flex: 1 1 220px;
  min-inline-size: 220px;
}

.provider-status {
  flex: 0 0 94px;
  text-align: center;
}

.provider-coverage {
  flex: 1 1 180px;
  min-inline-size: 160px;
}

.detail-info {
  background: rgba(var(--v-theme-on-surface), 0.04);
}

@media (max-width: 959px) {
  .provider-status {
    text-align: start;
  }
}
</style>
