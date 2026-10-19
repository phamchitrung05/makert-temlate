<!--
  =====================================================================
  CHỨC NĂNG FILE: Nối API Social Post vào giao diện Marketing hiện có.
  =====================================================================

  Giữ layout, CSS, sparkline và dữ liệu mẫu cho các nền tảng chưa có API.
  Tất cả/Facebook dùng list, KPI, phân trang và lifecycle thật; form tạo và
  dialog chi tiết dùng lại component hiện có. Tên/avatar tác giả vẫn là mẫu
  vì response chỉ có created_by, chưa trả thông tin người tạo.

  CÁC HÀM/COMPUTED TRONG FILE:
  - isApiPlatform/staticPosts/paginatedPosts: chọn dữ liệu API hoặc mẫu theo tab.
  - kpiStats: ghép số liệu API với trend/sparkline tĩnh.
  - totalItems/totalPages/startItem/endItem: phân trang server hoặc dữ liệu mẫu.
  - toCard(): ánh xạ Facebook content/gallery vào card hiện tại.
  - syncFilters(): gửi platform/khoảng ngày và reset trang.
  - openCreate()/handleCreate(): tải options và tạo draft qua API.
  - openDetail()/handleReview(): xem chi tiết, duyệt/từ chối bài thật.
  - showMessage(): thông báo kết quả hoặc lỗi API.
  - watcher filter/page: tải list; watcher error: hiển thị lỗi có thể thử lại.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : thao tác filter, chuyển chế độ xem và phân trang.
  - OUTPUT: layout hiện tại với dữ liệu API, dialog và mutation được người dùng chọn.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Filter/payload theo contract Laravel Social Post. */
import { ref, computed, shallowRef, watch } from 'vue'
import { useSocialPosts } from '@/composables/useSocialPosts'
import SocialPostCreateDialog from '@/views/marketing/social-post/components/SocialPostCreateDialog.vue'
import SocialPostDetailDialog from '@/views/marketing/social-post/components/SocialPostDetailDialog.vue'

definePage({ meta: { navActiveLink: 'marketing-social-post-list' } })

const selectedPlatform = ref('all')
const dateFilter = ref('')
const viewMode = ref('grid')

const {
  items: apiPosts, stats, products, campaigns, accounts, loading, error,
  page: currentPage, itemsPerPage, total: apiTotal, filters,
  load, loadOptions, create, approve, reject,
} = useSocialPosts()

itemsPerPage.value = 8

const createOpen = shallowRef(false)
const detailOpen = shallowRef(false)
const selectedPostId = shallowRef(null)
const actionLoading = shallowRef(false)
const activePostId = shallowRef(null)
const snackbar = ref({ visible: false, message: '', color: 'success' })
const isApiPlatform = computed(() => ['all', 'facebook'].includes(selectedPlatform.value))
const totalItems = computed(() => isApiPlatform.value ? apiTotal.value : staticPosts.value.length)

const totalPages = computed(() => Math.max(1, Math.ceil(totalItems.value / itemsPerPage.value)))
const startItem = computed(() => totalItems.value ? (currentPage.value - 1) * itemsPerPage.value + 1 : 0)
const endItem = computed(() => Math.min(currentPage.value * itemsPerPage.value, totalItems.value))

// 4 Thẻ KPI trên đầu trang
const kpiPlaceholders = [
  {
    title: 'Chờ duyệt',
    color: 'primary',
    value: 24,
    trend: '12%',
    isUp: true,
    icon: 'tabler-hourglass',
    linePath: 'M5 28 Q 30 25, 50 18 T 90 15 T 115 8',
    areaPath: 'M5 28 Q 30 25, 50 18 T 90 15 T 115 8 L 115 35 L 5 35 Z',
  },
  {
    title: 'Đã duyệt',
    color: 'success',
    value: 12,
    trend: '8%',
    isUp: true,
    icon: 'tabler-check-bold',
    linePath: 'M5 26 Q 35 28, 60 20 T 95 14 T 115 10',
    areaPath: 'M5 26 Q 35 28, 60 20 T 95 14 T 115 10 L 115 35 L 5 35 Z',
  },
  {
    title: 'Đã đăng',
    color: 'info',
    value: 128,
    trend: '18%',
    isUp: true,
    icon: 'tabler-send',
    linePath: 'M5 30 Q 25 18, 55 22 T 90 12 T 115 6',
    areaPath: 'M5 30 Q 25 18, 55 22 T 90 12 T 115 6 L 115 35 L 5 35 Z',
  },
  {
    title: 'Từ chối',
    color: 'error',
    value: 5,
    trend: '20%',
    isUp: false,
    icon: 'tabler-circle-x',
    linePath: 'M5 22 Q 35 25, 65 16 T 95 20 T 115 12',
    areaPath: 'M5 22 Q 35 25, 65 16 T 95 20 T 115 12 L 115 35 L 5 35 Z',
  },
]

// API có số lượng trạng thái; trend/sparkline chưa có endpoint nên giữ mẫu.
const kpiStats = computed(() => kpiPlaceholders.map((stat, index) => ({
  ...stat,
  value: stats.value[['pending_review', 'approved', 'published', 'failed'][index]] ?? 0,
})))

// Danh sách các nền tảng mạng xã hội
const platforms = [
  { id: 'all', name: 'Tất cả', count: 189, icon: '', color: 'primary' },
  { id: 'facebook', name: 'Facebook', count: 24, icon: 'tabler-brand-facebook', color: '#1877F2' },
  { id: 'instagram', name: 'Instagram', count: 28, icon: 'tabler-brand-instagram', color: '#E4405F' },
  { id: 'tiktok', name: 'TikTok', count: 18, icon: 'tabler-brand-tiktok', color: '#111111' },
  { id: 'youtube', name: 'YouTube', count: 12, icon: 'tabler-brand-youtube', color: '#FF0000' },
  { id: 'twitter', name: 'X (Twitter)', count: 6, icon: 'tabler-brand-x', color: '#111111' },
  { id: 'linkedin', name: 'LinkedIn', count: 6, icon: 'tabler-brand-linkedin', color: '#0A66C2' },
]

// Danh sách 8 bài viết khớp với giao diện
const posts = ref([
  {
    id: 1,
    platform: 'facebook',
    platformIcon: 'tabler-brand-facebook',
    platformColor: 'white',
    platformBg: '#1877F2',
    status: 'Chờ duyệt',
    title: 'Cà phê sáng năng lượng ☕',
    description: 'Một ngày mới bắt đầu với ly cà phê thơm ngon. Năng lượng tích cực, làm việc hiệu quả hơn!',
    tags: ['#caphesang', '#workmode', '#coffeetime'],
    thumbnail: 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=500',
    duration: '0:30',
    authorName: 'Trung Phạm',
    authorAvatar: 'https://i.pravatar.cc/150?img=11',
    createdAt: '2 giờ trước',
  },
  {
    id: 2,
    platform: 'instagram',
    platformIcon: 'tabler-brand-instagram',
    platformColor: 'white',
    platformBg: '#E4405F',
    status: 'Chờ duyệt',
    title: 'Khám phá hương vị mới 🌿',
    description: 'Trà đào nhiệt đới – tươi mát, sảng khoái cho những ngày nắng nóng.',
    tags: ['#tradao', '#summer', '#healthy'],
    thumbnail: 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=500',
    duration: '0:28',
    authorName: 'Nguyễn Linh',
    authorAvatar: 'https://i.pravatar.cc/150?img=5',
    createdAt: '4 giờ trước',
  },
  {
    id: 3,
    platform: 'tiktok',
    platformIcon: 'tabler-brand-tiktok',
    platformColor: 'white',
    platformBg: '#000000',
    status: 'Chờ duyệt',
    title: 'Hành trình khám phá đảo Kỳ Co',
    description: 'Video review chi tiết về chuyến đi 2 ngày 1 đêm tại Kỳ Co – Quy Nhơn.',
    tags: ['#travel', '#kyco', '#dulich'],
    thumbnail: 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=500',
    duration: '1:12',
    hasPlayIcon: true,
    authorName: 'Minh Anh',
    authorAvatar: 'https://i.pravatar.cc/150?img=9',
    createdAt: '5 giờ trước',
  },
  {
    id: 4,
    platform: 'youtube',
    platformIcon: 'tabler-brand-youtube',
    platformColor: 'white',
    platformBg: '#FF0000',
    status: 'Chờ duyệt',
    title: 'Review món bánh mì đặc biệt',
    description: 'Thử ngay phiên bản bánh mì mới với hương vị độc đáo, bạn nhất định phải thử!',
    tags: ['#review', '#banhmi', '#food'],
    thumbnail: 'https://images.unsplash.com/photo-1509722747041-616f39b57569?w=500',
    duration: '8:24',
    authorName: 'Trung Phạm',
    authorAvatar: 'https://i.pravatar.cc/150?img=11',
    createdAt: '1 ngày trước',
  },
  {
    id: 5,
    platform: 'facebook',
    platformIcon: 'tabler-brand-facebook',
    platformColor: 'white',
    platformBg: '#1877F2',
    status: 'Chờ duyệt',
    title: 'Menu mùa hè mới 🌱',
    description: 'Giới thiệu menu đồ uống mới cho mùa hè 2026.',
    tags: ['#menu', '#muahe', '#douong'],
    thumbnail: 'https://images.unsplash.com/photo-1513558161293-cdaf765ed2fd?w=500',
    authorName: 'Nguyễn Linh',
    authorAvatar: 'https://i.pravatar.cc/150?img=5',
    createdAt: '1 ngày trước',
  },
  {
    id: 6,
    platform: 'instagram',
    platformIcon: 'tabler-brand-instagram',
    platformColor: 'white',
    platformBg: '#E4405F',
    status: 'Chờ duyệt',
    title: 'Khuyến mãi tháng 10 🎉',
    description: 'Giảm 20% cho tất cả sản phẩm trong tháng 10. Đừng bỏ lỡ!',
    tags: ['#khuyenmai', '#sale', '#thang10'],
    thumbnail: 'https://images.unsplash.com/photo-1513885535751-8b9238bd345a?w=500',
    authorName: 'Trung Phạm',
    authorAvatar: 'https://i.pravatar.cc/150?img=11',
    createdAt: '2 ngày trước',
  },
  {
    id: 7,
    platform: 'tiktok',
    platformIcon: 'tabler-brand-tiktok',
    platformColor: 'white',
    platformBg: '#000000',
    status: 'Chờ duyệt',
    title: '5 mẹo chăm sóc thú cưng',
    description: 'Những mẹo nhỏ giúp việc chăm sóc thú cưng trở nên dễ dàng hơn.',
    tags: ['#petcare', '#meovat', '#dog'],
    thumbnail: 'https://images.unsplash.com/photo-1543466835-00a7907e9de1?w=500',
    duration: '0:58',
    authorName: 'Minh Anh',
    authorAvatar: 'https://i.pravatar.cc/150?img=9',
    createdAt: '2 ngày trước',
  },
  {
    id: 8,
    platform: 'linkedin',
    platformIcon: 'tabler-brand-linkedin',
    platformColor: 'white',
    platformBg: '#0A66C2',
    status: 'Chờ duyệt',
    title: 'Báo cáo xu hướng thị trường 2026',
    description: 'Tổng quan và phân tích xu hướng thị trường trong năm 2026.',
    tags: ['#business', '#market', '#2026'],
    thumbnail: 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=500',
    authorName: 'Nguyễn Linh',
    authorAvatar: 'https://i.pravatar.cc/150?img=5',
    createdAt: '3 ngày trước',
  },
])

const staticPosts = computed(() => posts.value.filter(post => post.platform === selectedPlatform.value))

const paginatedPosts = computed(() => isApiPlatform.value
  ? apiPosts.value.map(toCard)
  : staticPosts.value.slice((currentPage.value - 1) * itemsPerPage.value, currentPage.value * itemsPerPage.value))

/**
 * Input: Social Post đã normalize từ service. Output: dữ liệu cho card cũ.
 * Content/gallery/status/thời gian lấy từ API; tác giả vẫn dùng placeholder.
 * Không gán video hoặc ảnh mẫu vào bài thật khi API chưa có ảnh.
 */
function toCard(post) {
  const content = post.facebook_variant?.content ?? {}
  const image = post.facebook_variant?.images?.[0]?.asset ?? post.image
  const facebook = platforms.find(platform => platform.id === 'facebook')

  return {
    id: post.id,
    isApi: true,
    reviewStatus: post.reviewStatus,
    platform: 'facebook',
    platformIcon: facebook.icon,
    platformColor: 'white',
    platformBg: facebook.color,
    status: post.publicationStatus === 'published' ? 'Đã đăng' : ({
      draft: 'Bản nháp', pending_review: 'Chờ duyệt', approved: 'Đã duyệt',
      rejected: 'Từ chối', revision_requested: 'Cần chỉnh sửa',
    }[post.reviewStatus] ?? 'Bản nháp'),
    title: content.title || post.campaign?.name || 'Social Post',
    description: content.body ?? post.caption ?? '',
    tags: (content.hashtags ?? []).map(tag => tag.startsWith('#') ? tag : `#${tag}`),
    thumbnail: image?.file?.preview_url || image?.file?.url || '',
    authorName: posts.value[0].authorName,
    authorAvatar: posts.value[0].authorAvatar,
    createdAt: post.created_at ? new Date(post.created_at).toLocaleString('vi-VN') : '',
  }
}

/** Input: tab/khoảng ngày/số dòng. Output: query API và trang 1; không ghi dữ liệu. */
function syncFilters() {
  const dates = dateFilter.value?.match(/\d{4}-\d{2}-\d{2}/g) ?? []

  filters.value = {
    platform: selectedPlatform.value === 'all' ? '' : selectedPlatform.value,
    created_from: dates[0] ?? '',
    created_to: dates[1] ?? dates[0] ?? '',
  }
  currentPage.value = 1
}

/** Input: nội dung/màu. Output: snackbar kết quả; không gọi API. */
function showMessage(message, color = 'success') {
  snackbar.value = { visible: true, message, color }
}

/** Input: nút tạo bài. Output: tải options thật rồi mở dialog; lỗi giữ nguyên list. */
async function openCreate() {
  if (actionLoading.value) return
  actionLoading.value = true
  try {
    if (await loadOptions()) createOpen.value = true
  } finally {
    actionLoading.value = false
  }
}

/** Input: payload form. Output: tạo draft, refresh list và đóng form nếu thành công. */
async function handleCreate(payload) {
  if (actionLoading.value) return
  actionLoading.value = true
  try {
    const result = await create(payload)

    createOpen.value = false
    showMessage(result.generation ? 'Đã tạo draft và xếp hàng AI.' : 'Đã tạo draft Social Post.')
  } catch {
    // Composable cung cấp lỗi cho snackbar; giữ form và dữ liệu để người dùng sửa.
  } finally {
    actionLoading.value = false
  }
}

/** Input: card. Output: mở detail của bài API; card mẫu không gọi endpoint bằng ID giả. */
function openDetail(post) {
  if (!post.isApi) return
  selectedPostId.value = post.id
  detailOpen.value = true
}

/**
 * Input: approve/reject và card API pending_review.
 * Output: gọi endpoint hiện có rồi refresh; lỗi hiển thị, không báo thành công giả.
 * Side effect: thay đổi trạng thái review; chặn click trùng và ID của dữ liệu mẫu.
 */
async function handleReview(action, post) {
  if (!post.isApi || post.reviewStatus !== 'pending_review' || actionLoading.value) return
  actionLoading.value = true
  activePostId.value = post.id
  try {
    if (action === 'approve') await approve(post.id)
    else await reject(post.id)
    showMessage(action === 'approve' ? 'Đã duyệt bài viết.' : 'Đã từ chối bài viết.')
  } catch {
    // Thông báo lỗi từ composable được watcher đưa vào snackbar.
  } finally {
    actionLoading.value = false
    activePostId.value = null
  }
}

watch([selectedPlatform, dateFilter, itemsPerPage], syncFilters, { flush: 'sync' })
watch([filters, currentPage, itemsPerPage], load, { immediate: true })
watch(error, message => { if (message) showMessage(message, 'error') })
</script>

<template>
  <div class="social-posts-page">
    <!-- ================= 1. HEADER ================= -->
    <div class="d-flex flex-wrap justify-space-between gap-y-4 gap-x-6 mb-6">
      <div>
        <h4 class="text-h4 font-weight-medium">
          Social Post
        </h4>
        <div class="text-body-1">
          Bài viết đang chờ duyệt để đăng lên các nền tảng mạng xã hội
        </div>
      </div>
      <div class="d-flex flex-wrap gap-3">
        <VBtn
          color="secondary"
          variant="tonal"
          prepend-icon="tabler-calendar-event"
        >
          Lịch nội dung
        </VBtn>
        <VBtn
          prepend-icon="tabler-plus"
          :loading="actionLoading && !activePostId"
          @click="openCreate"
        >
          Tạo bài viết
        </VBtn>
      </div>
    </div>

    <!-- ================= 2. 4 THẺ THỐNG KÊ (KPI CARDS) ================= -->
    <VRow class="mb-6">
      <VCol
        v-for="stat in kpiStats"
        :key="stat.title"
        cols="12"
        sm="6"
        lg="3"
      >
        <VCard class="pa-4 position-relative overflow-hidden">
          <div class="d-flex justify-space-between align-start">
            <div class="d-flex align-center">
              <VAvatar
                :color="stat.color"
                rounded="lg"
                size="46"
                variant="tonal"
                class="me-3"
              >
                <VIcon
                  :icon="stat.icon"
                  :color="stat.color"
                  size="24"
                />
              </VAvatar>
              <div>
                <div class="text-body-2 text-medium-emphasis font-weight-medium mb-1">
                  {{ stat.title }}
                </div>
                <div class="d-flex align-center gap-2">
                  <span class="text-h4 font-weight-bold text-high-emphasis">{{ stat.value }}</span>
                  <span
                    class="text-caption font-weight-bold"
                    :class="stat.isUp ? 'text-success' : 'text-error'"
                  >
                    {{ stat.isUp ? '↑' : '↓' }} {{ stat.trend }}
                  </span>
                </div>
              </div>
            </div>

            <VBtn
              icon="tabler-dots-vertical"
              variant="text"
              size="x-small"
              color="secondary"
            />
          </div>

          <!-- Biểu đồ sóng mini (Sparkline SVG) góc dưới phải -->
          <svg
            class="sparkline-svg"
            viewBox="0 0 120 35"
            fill="none"
          >
            <path
              :d="stat.areaPath"
              fill="currentColor"
              fill-opacity="0.12"
            />
            <path
              :d="stat.linePath"
              stroke="currentColor"
              stroke-width="2"
              fill="none"
              stroke-linecap="round"
            />
          </svg>
        </VCard>
      </VCol>
    </VRow>

    <!-- ================= 3. THANH LỌC NỀN TẢNG & CÔNG CỤ ================= -->
    <VCard class="px-4 py-3 mb-6">
      <div class="d-flex align-center justify-space-between flex-wrap gap-3">
        <!-- Các tab nền tảng mạng xã hội -->
        <div class="d-flex align-center flex-wrap gap-2">
          <VBtn
            v-for="pf in platforms"
            :key="pf.id"
            size="small"
            variant="text"
            color="secondary"
            :class="[`platform-filter--${pf.id}`, { 'platform-filter--active': selectedPlatform === pf.id }]"
            rounded="lg"
            class="platform-filter text-none font-weight-medium px-3"
            @click="selectedPlatform = pf.id"
          >
            <VIcon
              v-if="pf.icon"
              :icon="pf.icon"
              :color="selectedPlatform === pf.id ? 'white' : pf.color"
              size="16"
              class="me-2"
            />
            <span>{{ pf.name }}</span>
            <VChip
              size="x-small"
              :color="selectedPlatform === pf.id ? 'white' : 'secondary'"
              :class="{ 'platform-filter__count--active': selectedPlatform === pf.id }"
              variant="text"
              class="ms-2 font-weight-bold px-2"
            >
              {{ pf.count }}
            </VChip>
          </VBtn>
        </div>

        <!-- Bộ lọc thời gian & Chuyển đổi chế độ xem -->
        <div class="d-flex align-center gap-2">
          <AppDateTimePicker
            v-model="dateFilter"
            :config="{ mode: 'range', dateFormat: 'Y-m-d' }"
            placeholder="Chọn thời gian"
            prepend-inner-icon="tabler-calendar"
            variant="outlined"
            density="compact"
            hide-details
            clearable
            class="social-posts-page__date-filter"
          />

          <VBtn
            icon="tabler-adjustments-horizontal"
            variant="outlined"
            size="small"
            color="secondary"
            class="rounded-lg"
          />

          <VBtnToggle
            v-model="viewMode"
            mandatory
            density="compact"
            color="primary"
            rounded="lg"
            class="border"
          >
            <VBtn
              icon="tabler-layout-grid"
              value="grid"
              size="small"
            />
            <VBtn
              icon="tabler-list"
              value="list"
              size="small"
            />
          </VBtnToggle>
        </div>
      </div>
    </VCard>

    <!-- ================= 4. LƯỚI DANH SÁCH BÀI VIẾT (SOCIAL POSTS GRID) ================= -->
    <VRow :aria-busy="loading && isApiPlatform">
      <VCol
        v-for="post in paginatedPosts"
        :key="post.id"
        cols="12"
        sm="6"
        md="4"
        lg="3"
        xl="2"
      >
        <VCard class="pa-3 h-100 d-flex flex-column justify-space-between">
          <div>
            <!-- Header Card: Icon MXH + Trạng thái + Menu -->
            <div class="d-flex justify-space-between align-center mb-3">
              <div class="d-flex align-center gap-2">
                <VAvatar
                  :color="post.platformBg"
                  size="26"
                  rounded="md"
                >
                  <VIcon
                    :icon="post.platformIcon"
                    :color="post.platformColor"
                    size="16"
                  />
                </VAvatar>
                <VChip
                  size="x-small"
                  color="warning"
                  variant="tonal"
                  prepend-icon="tabler-clock"
                  class="font-weight-medium px-2"
                >
                  {{ post.status }}
                </VChip>
              </div>
              <VBtn
                icon="tabler-dots-vertical"
                variant="text"
                size="x-small"
                color="secondary"
              />
            </div>

            <!-- Hình ảnh / Video Thumbnail -->
            <div class="position-relative rounded-md overflow-hidden mb-3">
              <VImg
                :src="post.thumbnail"
                height="155"
                cover
                class="bg-surface-variant"
              />

              <!-- Nút Play ở giữa nếu là Video nổi bật -->
              <div
                v-if="post.hasPlayIcon"
                class="play-overlay d-flex align-center justify-center"
              >
                <VAvatar
                  color="rgba(0, 0, 0, 0.55)"
                  size="42"
                >
                  <VIcon
                    icon="tabler-player-play"
                    color="white"
                    size="24"
                  />
                </VAvatar>
              </div>

              <!-- Icon loại media góc trái dưới -->
              <div class="media-type-badge">
                <VIcon
                  :icon="post.duration ? 'tabler-video' : 'tabler-photo'"
                  size="13"
                  color="white"
                />
              </div>

              <!-- Thời lượng video góc phải dưới -->
              <div
                v-if="post.duration"
                class="duration-badge text-white font-weight-medium"
              >
                {{ post.duration }}
              </div>
            </div>

            <!-- Tiêu đề & Nội dung mô tả -->
            <div class="text-body-2 font-weight-bold text-high-emphasis mb-1 text-truncate">
              {{ post.title }}
            </div>
            <p class="text-body-2 text-medium-emphasis line-clamp-2 mb-3 social-post-description">
              {{ post.description }}
            </p>

            <!-- Danh sách Hashtags -->
            <div class="d-flex flex-wrap gap-1 mb-3">
              <VChip
                v-for="tag in post.tags"
                :key="tag"
                size="x-small"
                color="primary"
                variant="tonal"
                class="font-weight-medium"
              >
                {{ tag }}
              </VChip>
            </div>
          </div>

          <!-- Footer Card: Tác giả & Cụm nút Duyệt / Từ chối -->
          <div class="pt-3 border-t d-flex align-center justify-space-between">
            <div class="d-flex align-center text-truncate me-2">
              <VAvatar
                size="28"
                class="me-2"
              >
                <VImg
                  :src="post.authorAvatar"
                  cover
                />
              </VAvatar>
              <div class="text-truncate">
                <div class="text-body-2 font-weight-medium text-high-emphasis text-truncate post-author-name">
                  {{ post.authorName }}
                </div>
                <div class="text-caption text-disabled post-created-at">
                  {{ post.createdAt }}
                </div>
              </div>
            </div>

            <!-- Cụm nút hành động nhanh -->
            <div class="d-flex align-center gap-1 flex-shrink-0">
              <VBtn
                icon="tabler-eye"
                size="26"
                variant="tonal"
                color="secondary"
                class="rounded-md"
                title="Xem chi tiết"
                @click="openDetail(post)"
              />
              <VBtn
                icon="tabler-check"
                size="26"
                variant="tonal"
                color="success"
                class="rounded-md"
                title="Duyệt bài"
                :loading="activePostId === post.id"
                :disabled="post.isApi && (post.reviewStatus !== 'pending_review' || actionLoading)"
                @click="handleReview('approve', post)"
              />
              <VBtn
                icon="tabler-x"
                size="26"
                variant="tonal"
                color="error"
                class="rounded-md"
                title="Từ chối"
                :disabled="post.isApi && (post.reviewStatus !== 'pending_review' || actionLoading)"
                @click="handleReview('reject', post)"
              />
              <VBtn
                icon="tabler-dots"
                size="26"
                variant="tonal"
                color="secondary"
                class="rounded-md"
              />
            </div>
          </div>
        </VCard>
      </VCol>
      <VCol
        v-if="!paginatedPosts.length"
        cols="12"
      >
        <VCard class="pa-6 text-center text-medium-emphasis">
          <VProgressCircular
            v-if="loading"
            indeterminate
            color="primary"
          />
          <template v-else>
            <div>{{ error || 'Chưa có bài viết trong danh sách này.' }}</div>
            <VBtn
              v-if="isApiPlatform"
              variant="text"
              class="mt-3"
              @click="load"
            >
              Tải lại
            </VBtn>
          </template>
        </VCard>
      </VCol>
    </VRow>

    <!-- ================= 5. THANH PHÂN TRANG (PAGINATION BAR) ================= -->
    <VCard class="px-5 py-3 mt-6">
      <div class="d-flex align-center justify-space-between flex-wrap gap-4">
        <!-- Chọn số lượng hiển thị & Thông tin tổng số bài -->
        <div class="d-flex align-center flex-wrap gap-3 text-caption text-medium-emphasis">
          <div class="d-flex align-center">
            <span class="me-2">Hiển thị</span>
            <AppSelect
              v-model="itemsPerPage"
              :items="[8, 12, 16, 24]"
              variant="outlined"
              density="compact"
              hide-details
              class="social-posts-page__per-page"
            />
            <span class="ms-2">bài viết mỗi trang</span>
          </div>
          <VDivider
            vertical
            class="my-1 d-none d-sm-block"
          />
          <span>
            Đang xem <strong class="text-high-emphasis">{{ startItem }} - {{ endItem }}</strong> trong tổng số
            <strong class="text-high-emphasis">{{ totalItems }}</strong> bài viết
          </span>
        </div>

        <!-- Điều hướng trang -->
        <div class="d-flex align-center gap-2">
          <VPagination
            v-model="currentPage"
            :length="totalPages"
            :total-visible="5"
            density="comfortable"
            active-color="primary"
            rounded="lg"
          />
        </div>
      </div>
    </VCard>
    <SocialPostCreateDialog
      v-model="createOpen"
      :campaigns="campaigns"
      :products="products"
      :accounts="accounts"
      @submit="handleCreate"
    />
    <SocialPostDetailDialog
      v-model="detailOpen"
      :post-id="selectedPostId"
      @saved="load"
    />
    <VSnackbar
      v-model="snackbar.visible"
      :color="snackbar.color"
      :timeout="6000"
    >
      {{ snackbar.message }}
    </VSnackbar>
    <VOverlay
      :model-value="actionLoading && !activePostId"
      class="align-center justify-center"
      persistent
    >
      <VProgressCircular
        indeterminate
        color="primary"
      />
    </VOverlay>
  </div>
</template>

<style scoped lang="scss">
.social-posts-page {
  min-inline-size: 0;
}
.social-posts-page__date-filter { min-inline-size: 175px; }
.social-posts-page__per-page { inline-size: 78px; }
.platform-filter {
  --platform-brand-color: rgb(var(--v-theme-primary));
  transition: background-color 0.2s ease, color 0.2s ease;
  :deep(.v-btn__overlay), :deep(.v-btn__underlay) { opacity: 0 !important; }
  &--all { --platform-brand-color: rgb(var(--v-theme-primary)); }
  &--facebook { --platform-brand-color: #1877f2; }
  &--instagram { --platform-brand-color: #e4405f; }
  &--tiktok { --platform-brand-color: #111; }
  &--youtube { --platform-brand-color: #f00; }
  &--twitter { --platform-brand-color: #111; }
  &--linkedin { --platform-brand-color: #0a66c2; }
  &--active {
    background-color: var(--platform-brand-color) !important;
    color: #fff !important;
    &:hover, &:focus-visible {
      background-color: var(--platform-brand-color) !important;
      color: #fff !important;
    }
  }
}
.platform-filter__count--active {
  background-color: rgb(255 255 255 / 94%) !important;
  color: var(--platform-brand-color) !important;
}
.sparkline-svg {
  position: absolute;
  inset-block-end: 0;
  inset-inline-end: 12px;
  inline-size: 110px;
  block-size: 32px;
  pointer-events: none;
}
.line-clamp-2 {
  display: -webkit-box;
  min-block-size: 34px;
  overflow: hidden;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}
.play-overlay { position: absolute; inset: 0; background: rgba(0, 0, 0, 0.15); }
.media-type-badge, .duration-badge {
  position: absolute;
  inset-block-end: 6px;
  background: rgba(0, 0, 0, 0.7);
  border-radius: 4px;
}
.media-type-badge { inset-inline-start: 6px; display: flex; align-items: center; padding: 2px 5px; }
.duration-badge { inset-inline-end: 6px; padding: 1px 6px; font-size: 0.625rem; }
</style>
