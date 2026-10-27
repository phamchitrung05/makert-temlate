<!--
  =====================================================================
  CHỨC NĂNG FILE: Tab "Chiến Dịch" của trang Lịch Đăng Bài (post-schedule).
  =====================================================================

  Panel con render bởi index.vue trong VWindowItem value="campaign".
  Quản lý danh sách chiến dịch marketing: tạo, sửa, theo dõi trạng thái;
  hiện là dữ liệu mẫu (chưa nối API).

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - campaigns: danh sách chiến dịch đang chạy / nháp / đã kết thúc.
  - statusMeta: map trạng thái → màu chip + nhãn.
  - platformMeta: map nền tảng → icon tabler + màu brand.
  - filteredCampaigns: danh sách đã lọc theo search.
  - handleCampaignCreated(newCampaign): thêm chiến dịch mới tạo từ dialog vào đầu danh sách.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : không có props; state giữ trong component.
  - OUTPUT: UI danh sách chiến dịch; chưa emit/persist vì chưa có API.
  =====================================================================
-->
<script setup>
import { computed, onMounted, ref, shallowRef } from 'vue'
import CampaignCreateDialog from '@/views/marketing/post-schedule/CampaignCreateDialog.vue'
import { postScheduleService } from '@/services/postSchedule'

const createOpen = shallowRef(false)
const loading = ref(false)

// ─── State filters ────────────────────────────────────────────────────────────
const searchQuery = ref('')
const itemsPerPage = ref(10)
const page = ref(1)
const selectedRows = ref([])

// ─── Trạng thái chiến dịch (map enum backend → nhãn + màu) ────────────────────
const statusMeta = {
  'Đang chạy': { color: 'success' },
  'Sắp bắt đầu': { color: 'info' },
  'Đã hoàn thành': { color: 'secondary' },
  'Tạm dừng': { color: 'warning' },
  'Nháp': { color: 'secondary' },
}

const statusLabel = {
  active: 'Đang chạy',
  scheduled: 'Sắp bắt đầu',
  completed: 'Đã hoàn thành',
  paused: 'Tạm dừng',
  draft: 'Nháp',
}

// ─── Nền tảng MXH: icon tabler + màu brand ────────────────────────────────────
const platformMeta = {
  fb: { icon: 'tabler-brand-facebook', color: '#1877F2' },
  ig: { icon: 'tabler-brand-instagram', color: '#E4405F' },
  tt: { icon: 'tabler-brand-tiktok', color: '#000000' },
  yt: { icon: 'tabler-brand-youtube', color: '#FF0000' },
}

// ─── Danh sách chiến dịch mẫu ─────────────────────────────────────────────────
const campaigns = ref([
  {
    id: 1,
    name: 'BST Đồ uống mùa thu',
    subtitle: 'Giới thiệu menu mới và ưu đãi mùa thu',
    dateRange: '01/10 – 31/10/2026',
    duration: '31 ngày',
    objective: 'Product',
    fullObjective: 'Product – Giới thiệu sản phẩm',
    platforms: ['fb', 'ig', 'tt'],
    posts: 24,
    progress: 72,
    status: 'Đang chạy',
    image: 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=200',
  },
  {
    id: 2,
    name: 'Khai trương chi nhánh 5',
    subtitle: 'Truyền thông khai trương, ưu đãi tuần đầu',
    dateRange: '15/11 – 30/11/2026',
    duration: '16 ngày',
    objective: 'Branding',
    fullObjective: 'Branding – Nhận diện thương hiệu',
    platforms: ['fb', 'ig', 'yt'],
    posts: 12,
    progress: 25,
    status: 'Sắp bắt đầu',
    image: 'https://images.unsplash.com/photo-1509722747041-616f39b57569?w=200',
  },
  {
    id: 3,
    name: 'Cà phê thật – Câu chuyện thật',
    subtitle: 'Chia sẻ câu chuyện và quy trình chọn hạt',
    dateRange: '01/09 – 30/09/2026',
    duration: '30 ngày',
    objective: 'Engagement',
    fullObjective: 'Engagement – Tăng tương tác',
    platforms: ['fb', 'ig'],
    posts: 18,
    progress: 100,
    status: 'Đã hoàn thành',
    image: 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=200',
  },
  {
    id: 4,
    name: 'Mini game cuối tuần',
    subtitle: 'Tăng tương tác, nhận quà dễ thương',
    dateRange: '05/10 – 19/10/2026',
    duration: '15 ngày',
    objective: 'Engagement',
    fullObjective: 'Engagement – Tăng tương tác',
    platforms: ['fb', 'tt'],
    posts: 8,
    progress: 50,
    status: 'Tạm dừng',
    image: 'https://images.unsplash.com/photo-1517701550927-30cf4ba1dba5?w=200',
  },
  {
    id: 5,
    name: 'BST Hương vị mới',
    subtitle: 'Giới thiệu dòng sản phẩm mới',
    dateRange: '20/10 – 10/11/2026',
    duration: '22 ngày',
    objective: 'Product',
    fullObjective: 'Product – Giới thiệu sản phẩm',
    platforms: ['fb', 'ig', 'yt'],
    posts: 16,
    progress: 0,
    status: 'Nháp',
    image: 'https://images.unsplash.com/photo-1513558161293-cdaf765ed2fd?w=200',
  },
])

// ─── Filtered list (chỉ lọc theo search) ─────────────────────────────────────
const filteredCampaigns = computed(() => {
  if (!searchQuery.value)
    return campaigns.value

  const q = searchQuery.value.toLowerCase()

  return campaigns.value.filter(c =>
    c.name.toLowerCase().includes(q) || c.subtitle.toLowerCase().includes(q),
  )
})

// Chiến dịch đang chọn (hiển thị panel chi tiết bên phải)
const selectedCampaign = ref(campaigns.value[0])

/**
 * =====================================================================
 * CHỨC NĂNG: Nạp danh sách chiến dịch từ API /marketing/campaigns.
 * =====================================================================
 * INPUT : không có; gọi khi mounted.
 * OUTPUT: gán campaigns từ backend (map enum status -> nhãn VN); nếu rỗng
 *   giữ danh sách mẫu hiện có.
 * SIDE EFFECT: gọi GET campaigns; không ghi DB.
 * =====================================================================
 */
const objectiveLabel = {
  product: 'Product', branding: 'Branding', engagement: 'Engagement',
  sales: 'Sales', event: 'Event', other: 'Other',
}

const loadCampaigns = async () => {
  loading.value = true
  try {
    const res = await postScheduleService.campaigns()
    const items = res?.items ?? res?.data ?? (Array.isArray(res) ? res : [])
    if (! Array.isArray(items) || items.length === 0)
      return
    campaigns.value = items.map(c => {
      const start = c.starts_at ? c.starts_at.slice(0, 10).split('-').reverse().join('/') : ''
      const end = c.ends_at ? c.ends_at.slice(0, 10).split('-').reverse().join('/') : ''

      const days = c.starts_at && c.ends_at
        ? Math.max(1, Math.round((new Date(c.ends_at) - new Date(c.starts_at)) / 86400000) + 1)
        : null

      const status = statusLabel[c.status] || 'Nháp'

      return {
        id: c.id,
        name: c.name,
        subtitle: c.objective || 'Chiến dịch đăng bài tự động',
        dateRange: start && end ? `${start} – ${end}` : '—',
        duration: days ? `${days} ngày` : '—',
        objective: objectiveLabel[c.objective] || 'Product',
        fullObjective: c.objective || '',
        platforms: c.platforms_json ?? ['fb'],
        posts: 0,
        progress: status === 'Đã hoàn thành' ? 100 : (status === 'Đang chạy' ? 50 : 0),
        status,
        image: 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=200',
      }
    })
    selectedCampaign.value = campaigns.value[0] ?? null
  } catch {
    // Giữ dữ liệu mẫu khi API chưa sẵn sàng.
  } finally {
    loading.value = false
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Thêm chiến dịch mới tạo từ CampaignCreateDialog vào danh sách
 * =====================================================================
 * INPUT : newCampaign - object chiến dịch do CampaignCreateDialog emit.
 * OUTPUT: thêm vào đầu mảng campaigns và chọn làm selectedCampaign.
 * SIDE EFFECT: cập nhật state cục bộ campaigns.
 * =====================================================================
 */
function handleCampaignCreated(newCampaign) {
  campaigns.value.unshift(newCampaign)
  selectedCampaign.value = newCampaign
}

onMounted(loadCampaigns)
</script>

<template>
  <div class="campaign-tab-content">
    <VRow dense>
      <!-- ================= BẢNG CHIẾN DỊCH TOÀN BỘ ================= -->
      <VCol cols="12">
        <div class="d-flex flex-column gap-4">
          <!-- Toolbar: search + actions -->
          <div class="d-flex align-center flex-wrap gap-4">
            <AppTextField
              v-model="searchQuery"
              placeholder="Tìm chiến dịch..."
              style="inline-size: 240px;"
              class="me-3"
            />
            <VSpacer />
            <div class="d-flex gap-4 align-center">
              <AppSelect
                v-model="itemsPerPage"
                :items="[5, 10, 20, 50]"
                style="inline-size: 110px;"
              />
              <VBtn
                color="primary"
                prepend-icon="tabler-plus"
                @click="createOpen = true"
              >
                Tạo chiến dịch
              </VBtn>
            </div>
          </div>

          <!-- DATA TABLE -->
          <VDataTableServer
            v-model:items-per-page="itemsPerPage"
            v-model:model-value="selectedRows"
            v-model:page="page"
            :headers="[
              { title: 'Chiến dịch', key: 'name' },
              { title: 'Thời gian', key: 'dateRange' },
              { title: 'Mục tiêu', key: 'objective' },
              { title: 'Nền tảng', key: 'platforms' },
              { title: 'Bài viết', key: 'posts', align: 'center' },
              { title: 'Tiến độ', key: 'progress' },
              { title: 'Trạng thái', key: 'status' },
              { title: 'Thao tác', key: 'actions', sortable: false, align: 'end' },
            ]"
            :items="filteredCampaigns"
            :items-length="filteredCampaigns.length"
            class="text-no-wrap"
            @click:row="(_, { item }) => selectedCampaign = item"
          >
            <!-- Chiến dịch -->
            <template #item.name="{ item }">
              <div class="d-flex align-center gap-3 py-2">
                <VAvatar
                  rounded="lg"
                  size="40"
                  class="flex-shrink-0 border"
                >
                  <VImg
                    :src="item.image"
                    cover
                  />
                </VAvatar>
                <div
                  class="text-truncate"
                  style="max-width: 200px;"
                >
                  <div class="text-body-1 font-weight-bold text-high-emphasis text-truncate">
                    {{ item.name }}
                  </div>
                  <div class="text-body-2 text-medium-emphasis text-truncate">
                    {{ item.subtitle }}
                  </div>
                </div>
              </div>
            </template>

            <!-- Thời gian -->
            <template #item.dateRange="{ item }">
              <div class="text-body-2 font-weight-medium text-high-emphasis">
                {{ item.dateRange }}
              </div>
              <div class="text-caption text-disabled">
                ({{ item.duration }})
              </div>
            </template>

            <!-- Mục tiêu -->
            <template #item.objective="{ item }">
              <VChip
                size="small"
                color="primary"
                variant="tonal"
              >
                {{ item.objective }}
              </VChip>
            </template>

            <!-- Nền tảng -->
            <template #item.platforms="{ item }">
              <div class="d-flex align-center gap-1">
                <VAvatar
                  v-for="pf in item.platforms"
                  :key="pf"
                  :color="platformMeta[pf].color"
                  size="30"
                  rounded="sm"
                >
                  <VIcon
                    :icon="platformMeta[pf].icon"
                    color="white"
                    size="16"
                  />
                </VAvatar>
              </div>
            </template>

            <!-- Bài viết -->
            <template #item.posts="{ item }">
              <span class="text-body-2 font-weight-bold text-high-emphasis">
                {{ item.posts }}
              </span>
            </template>

            <!-- Tiến độ -->
            <template #item.progress="{ item }">
              <div class="text-body-2 font-weight-bold text-high-emphasis mb-1">
                {{ item.progress }}%
              </div>
              <VProgressLinear
                :model-value="item.progress"
                color="primary"
                bg-color="grey-lighten-3"
                height="5"
                rounded
              />
            </template>

            <!-- Trạng thái -->
            <template #item.status="{ item }">
              <VChip
                size="small"
                :color="statusMeta[item.status]?.color"
                variant="tonal"
              >
                {{ item.status }}
              </VChip>
            </template>

            <!-- Thao tác -->
            <template #item.actions>
              <IconBtn>
                <VIcon icon="tabler-pencil" />
              </IconBtn>
              <IconBtn>
                <VIcon icon="tabler-dots-vertical" />
                <VMenu activator="parent">
                  <VList>
                    <VListItem prepend-icon="tabler-pencil">
                      Chỉnh sửa
                    </VListItem>
                    <VListItem prepend-icon="tabler-copy">
                      Nhân bản
                    </VListItem>
                    <VListItem prepend-icon="tabler-trash">
                      Xóa
                    </VListItem>
                  </VList>
                </VMenu>
              </IconBtn>
            </template>

            <!-- Pagination -->
            <template #bottom>
              <TablePagination
                v-model:page="page"
                :items-per-page="itemsPerPage"
                :total-items="filteredCampaigns.length"
              />
            </template>
          </VDataTableServer>
        </div>
      </VCol>
    </VRow>
    <CampaignCreateDialog
      v-model="createOpen"
      @created="handleCampaignCreated"
    />
  </div>
</template>
