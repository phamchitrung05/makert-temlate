<!--
  =====================================================================
  CHỨC NĂNG FILE: Tab "Cài đặt chung" của trang Lịch Đăng Bài (post-schedule).
  =====================================================================

  Panel con render bởi index.vue trong VWindowItem value="general".
  Chứa cài đặt số lượng/khung giờ tạo bài, công tắc tự động gửi duyệt/lên lịch,
  các trường chiến dịch/danh mục/ngôn ngữ, lịch đăng tuần, giới hạn nền tảng,
  kiểu văn phong AI và phong cách tạo ảnh.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - incrementDailyPostCount()/decrementDailyPostCount(): đổi số bài/ngày.
  - addGenerationTime()/removeGenerationTime(idx): quản lý khung giờ tạo bài.
  - addDaySlot(day)/removeDaySlot(day, idx): quản lý slot giờ từng ngày.
  - incrementPlatformLimit(pf)/decrementPlatformLimit(pf): đổi giới hạn nền tảng.
  - regenerateSample(): đổi câu văn mẫu theo văn phong đang chọn.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : không có props; state giữ trong component.
  - OUTPUT: UI cài đặt chung; chưa emit/persist vì chưa có API.
  =====================================================================
-->
<template>
  <div class="general-settings-tab-content">
    <VRow>
      <!-- ================= CỘT 1: CÀI ĐẶT SỐ LƯỢNG & QUY TRÌNH ================= -->
      <VCol
        cols="12"
        lg="4"
      >
        <div class="border rounded pa-5 h-100">
          <!-- Số lượng tạo bài mỗi ngày -->
          <div class="mb-5">
            <div class="text-body-1 font-weight-medium text-high-emphasis">
              Số lượng tạo bài mỗi ngày
            </div>
            <div class="text-body-2 text-medium-emphasis mb-3">
              AI sẽ tự động tạo nội dung theo số lượng bạn thiết lập
            </div>
            <div class="d-flex align-center gap-3">
              <div class="d-inline-flex align-center border rounded pa-1">
                <VBtn
                  icon="tabler-minus"
                  variant="text"
                  size="x-small"
                  color="secondary"
                  :disabled="dailyPostCount <= 1"
                  @click="decrementDailyPostCount"
                />
                <span class="px-5 text-body-2 font-weight-bold text-high-emphasis">{{ dailyPostCount }}</span>
                <VBtn
                  icon="tabler-plus"
                  variant="text"
                  size="x-small"
                  color="secondary"
                  @click="incrementDailyPostCount"
                />
              </div>
              <span class="text-body-2 text-medium-emphasis">bài/ngày</span>
            </div>
          </div>

          <VDivider class="my-4" />

          <!-- Khung giờ tạo bài -->
          <div class="mb-5">
            <div class="text-body-1 font-weight-medium text-high-emphasis">
              Khung giờ tạo bài
            </div>
            <div class="text-body-2 text-medium-emphasis mb-3">
              Chọn thời gian AI tự động tạo bài viết
            </div>
            <div class="d-flex flex-wrap align-center gap-2">
              <VChip
                v-for="(time, idx) in generationTimes"
                :key="idx"
                size="small"
                color="primary"
                variant="tonal"
                closable
                class="font-weight-medium"
                @click:close="removeGenerationTime(idx)"
              >
                {{ time }}
              </VChip>
              <VBtn
                variant="outlined"
                color="secondary"
                size="small"
                prepend-icon="tabler-plus"
                class="text-none border-dashed"
                @click="addGenerationTime"
              >
                Thêm giờ
              </VBtn>
            </div>
          </div>

          <VDivider class="my-4" />

          <!-- Công tắc tự động gửi duyệt & lên lịch -->
          <div class="d-flex align-center justify-space-between mb-4">
            <div class="pe-2">
              <div class="text-body-2 font-weight-medium text-high-emphasis">
                Tự động gửi duyệt
              </div>
              <div class="text-body-2 text-medium-emphasis">
                Sau khi tạo xong, bài viết sẽ được gửi đến hàng chờ duyệt
              </div>
            </div>
            <VSwitch
              v-model="autoSendApproval"
              color="primary"
              density="compact"
              hide-details
              inset
            />
          </div>

          <div class="d-flex align-center justify-space-between mb-5">
            <div class="pe-2">
              <div class="text-body-2 font-weight-medium text-high-emphasis">
                Tự động lên lịch sau khi duyệt
              </div>
              <div class="text-body-2 text-medium-emphasis">
                Tự động lên lịch đăng theo khung giờ đã thiết lập
              </div>
            </div>
            <VSwitch
              v-model="autoScheduleAfterApprove"
              color="primary"
              density="compact"
              hide-details
              inset
            />
          </div>

          <VDivider class="my-4" />

          <!-- Chiến dịch, Danh mục & Ngôn ngữ -->
          <AppSelect
            v-model="defaultCampaign"
            :items="['Content tháng 10', 'Chiến dịch Black Friday', 'Ra mắt sản phẩm mới']"
            label="Chiến dịch mặc định"
            class="mb-4"
          />

          <AppCombobox
            v-model="contentCategories"
            :items="['Sản phẩm', 'Khuyến mãi', 'Chia sẻ kiến thức', 'Tin tức', 'Tuyển dụng']"
            label="Danh mục nội dung"
            multiple
            chips
            closable-chips
            class="mb-4"
          />

          <AppSelect
            v-model="postLanguage"
            :items="['Tiếng Việt', 'Tiếng Anh (English)', 'Tiếng Nhật']"
            label="Ngôn ngữ bài viết"
          />
        </div>
      </VCol>

      <!-- ================= CỘT 2: LỊCH ĐĂNG TỰ ĐỘNG & GIỚI HẠN ================= -->
      <VCol
        cols="12"
        lg="4"
      >
        <div class="border rounded pa-5 h-100">
          <div class="text-body-1 font-weight-medium text-high-emphasis">
            Lịch đăng tự động
          </div>
          <div class="text-body-2 text-medium-emphasis mb-3">
            Thiết lập khung giờ đăng cho từng ngày trong tuần
          </div>

          <!-- Danh sách 7 ngày trong tuần -->
          <div class="d-flex flex-column gap-2 mb-4">
            <div
              v-for="day in weeklySchedule"
              :key="day.name"
              class="d-flex align-center justify-space-between py-1"
            >
              <div
                class="d-flex align-center"
                style="min-inline-size: 95px;"
              >
                <VCheckboxBtn
                  v-model="day.enabled"
                  color="primary"
                  density="compact"
                  class="me-1"
                />
                <span
                  class="text-body-2 font-weight-medium"
                  :class="day.enabled ? 'text-high-emphasis' : 'text-medium-emphasis'"
                >
                  {{ day.name }}
                </span>
              </div>

              <div class="d-flex align-center flex-wrap gap-1 justify-end flex-grow-1">
                <VChip
                  v-for="(slot, sIdx) in day.slots"
                  :key="sIdx"
                  size="x-small"
                  :color="day.enabled ? 'primary' : 'secondary'"
                  variant="tonal"
                  closable
                  class="font-weight-medium px-2"
                  @click:close="removeDaySlot(day, sIdx)"
                >
                  {{ slot }}
                </VChip>
                <VBtn
                  icon="tabler-plus"
                  size="22"
                  variant="tonal"
                  color="secondary"
                  class="rounded ms-1"
                  @click="addDaySlot(day)"
                />
              </div>
            </div>
          </div>

          <VDivider class="my-4" />

          <!-- Múi giờ -->
          <div class="mb-4">
            <AppSelect
              v-model="timezone"
              :items="['(GMT+7) Hồ Chí Minh, Việt Nam', '(GMT+8) Singapore', '(GMT+9) Tokyo, Nhật Bản']"
              label="Múi giờ"
              class="mb-2"
            />
            <div class="text-body-2 text-medium-emphasis d-flex align-center">
              <VIcon
                icon="tabler-info-circle"
                size="15"
                class="me-1"
              />
              Tất cả thời gian sẽ được lưu theo múi giờ này
            </div>
          </div>

          <VDivider class="my-4" />

          <!-- Giới hạn đăng mỗi ngày (mỗi nền tảng) -->
          <div>
            <div class="text-body-1 font-weight-medium text-high-emphasis mb-3">
              Giới hạn đăng mỗi ngày (mỗi nền tảng)
            </div>

            <div class="d-flex flex-column gap-3">
              <div
                v-for="pf in platformLimits"
                :key="pf.name"
                class="d-flex align-center justify-space-between"
              >
                <div class="d-flex align-center">
                  <VAvatar
                    :color="pf.bg"
                    size="28"
                    rounded
                    class="me-2"
                  >
                    <VIcon
                      :icon="pf.icon"
                      color="white"
                      size="16"
                    />
                  </VAvatar>
                  <span class="text-body-2 font-weight-medium text-high-emphasis">{{ pf.name }}</span>
                </div>

                <div class="d-flex align-center gap-2">
                  <div class="d-inline-flex align-center border rounded pa-1">
                    <VBtn
                      icon="tabler-minus"
                      variant="text"
                      size="20"
                      color="secondary"
                      :disabled="pf.limit <= 0"
                      @click="decrementPlatformLimit(pf)"
                    />
                    <span class="px-3 text-body-2 font-weight-bold text-high-emphasis">{{ pf.limit }}</span>
                    <VBtn
                      icon="tabler-plus"
                      variant="text"
                      size="20"
                      color="secondary"
                      @click="incrementPlatformLimit(pf)"
                    />
                  </div>
                  <span class="text-body-2 text-medium-emphasis">bài/ngày</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </VCol>

      <!-- ================= CỘT 3: VĂN PHONG AI & PHONG CÁCH ẢNH ================= -->
      <VCol
        cols="12"
        lg="4"
      >
        <div class="border rounded pa-5 h-100">
          <!-- Kiểu văn phong cho AI -->
          <div class="text-body-1 font-weight-medium text-high-emphasis">
            Kiểu văn phong cho AI
          </div>
          <div class="text-body-2 text-medium-emphasis mb-3">
            Chọn phong cách viết phù hợp với thương hiệu của bạn
          </div>

          <VRow
            dense
            class="mb-4"
          >
            <VCol
              v-for="tone in aiTones"
              :key="tone.id"
              cols="12"
              sm="4"
            >
              <div
                class="tone-card pa-3 rounded border h-100 cursor-pointer position-relative"
                :class="{ 'tone-card-active': selectedTone === tone.id }"
                @click="selectedTone = tone.id"
              >
                <VIcon
                  v-if="selectedTone === tone.id"
                  icon="tabler-circle-check-filled"
                  size="16"
                  color="primary"
                  class="tone-radio-dot"
                />

                <VAvatar
                  :color="tone.bg"
                  size="30"
                  rounded
                  class="mb-2"
                >
                  <VIcon
                    :icon="tone.icon"
                    :color="tone.color"
                    size="16"
                  />
                </VAvatar>
                <div class="text-body-2 font-weight-medium text-high-emphasis">
                  {{ tone.title }}
                </div>
                <div class="text-caption text-medium-emphasis mt-1 tone-desc">
                  {{ tone.desc }}
                </div>
              </div>
            </VCol>
          </VRow>

          <!-- Ví dụ văn phong -->
          <div class="mb-5">
            <div class="text-body-2 font-weight-medium text-high-emphasis mb-2">
              Ví dụ văn phong
            </div>
            <div class="pa-3 rounded border d-flex align-center justify-space-between gap-3 sample-tone-box">
              <p class="text-body-2 text-high-emphasis mb-0 sample-tone-text">
                {{ sampleToneText }}
              </p>
              <VBtn
                variant="outlined"
                color="secondary"
                size="x-small"
                prepend-icon="tabler-refresh"
                class="text-none flex-shrink-0"
                @click="regenerateSample"
              >
                Thử tạo lại
              </VBtn>
            </div>
          </div>

          <VDivider class="my-4" />

          <!-- Prompt phong cách tạo ảnh -->
          <div>
            <div class="text-body-1 font-weight-medium text-high-emphasis">
              Prompt phong cách tạo ảnh
            </div>
            <div class="text-body-2 text-medium-emphasis mb-3">
              Chọn phong cách hình ảnh mà AI sẽ sử dụng khi tạo ảnh minh họa
            </div>

            <div class="image-style-grid">
              <div
                v-for="style in imageStyles"
                :key="style.id"
                class="style-item text-center cursor-pointer"
                @click="selectedImageStyle = style.id"
              >
                <div
                  class="style-thumb-wrapper rounded overflow-hidden mb-1"
                  :class="{ 'style-thumb-active': selectedImageStyle === style.id }"
                >
                  <VImg
                    :src="style.img"
                    height="52"
                    cover
                    class="bg-grey-lighten-3"
                  />
                </div>
                <div
                  class="text-caption text-truncate"
                  :class="selectedImageStyle === style.id ? 'text-primary font-weight-bold' : 'text-medium-emphasis'"
                >
                  {{ style.name }}
                </div>
              </div>

              <!-- Ô Tùy chỉnh -->
              <div
                class="style-item text-center cursor-pointer"
                @click="selectedImageStyle = 'custom'"
              >
                <div
                  class="custom-style-box rounded d-flex flex-column align-center justify-center mb-1"
                  :class="{ 'style-thumb-active': selectedImageStyle === 'custom' }"
                >
                  <VIcon
                    icon="tabler-plus"
                    color="primary"
                    size="18"
                  />
                  <span class="text-primary font-weight-medium text-caption">Tùy chỉnh</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </VCol>
    </VRow>
  </div>
</template>

<script setup>
/* eslint-disable camelcase -- Keys follow the marketing_schedule settings contract. */
import { onMounted, ref, watch } from 'vue'
import { postScheduleService } from '@/services/postSchedule'

// =====================================================================
// CỘT 1: Cài đặt số lượng & quy trình
// =====================================================================
const dailyPostCount = ref(3)
const generationTimes = ref(['08:00', '13:00', '19:00'])
const autoSendApproval = ref(true)
const autoScheduleAfterApprove = ref(false)
const defaultCampaign = ref('Content tháng 10')
const contentCategories = ref(['Sản phẩm', 'Khuyến mãi', 'Chia sẻ kiến thức'])
const postLanguage = ref('Tiếng Việt')

// Trạng thái settings từ backend marketing_schedule.
const loading = ref(false)
const saving = ref(false)
const settingsLoaded = ref(false)

const incrementDailyPostCount = () => {
  dailyPostCount.value += 1
}

const decrementDailyPostCount = () => {
  if (dailyPostCount.value > 1)
    dailyPostCount.value -= 1
}

const addGenerationTime = () => {
  generationTimes.value.push('21:00')
}

const removeGenerationTime = idx => {
  generationTimes.value.splice(idx, 1)
}

// =====================================================================
// CỘT 2: Lịch đăng tự động & Giới hạn nền tảng
// =====================================================================
const weeklySchedule = ref([
  { name: 'Thứ 2', enabled: true, slots: ['08:00', '12:00', '19:00'] },
  { name: 'Thứ 3', enabled: true, slots: ['08:00', '12:00', '19:00'] },
  { name: 'Thứ 4', enabled: true, slots: ['08:00', '12:00', '19:00'] },
  { name: 'Thứ 5', enabled: true, slots: ['08:00', '12:00', '19:00'] },
  { name: 'Thứ 6', enabled: true, slots: ['08:00', '12:00', '19:00'] },
  { name: 'Thứ 7', enabled: false, slots: ['09:00', '15:00'] },
  { name: 'Chủ nhật', enabled: false, slots: ['09:00', '15:00'] },
])

const timezone = ref('(GMT+7) Hồ Chí Minh, Việt Nam')

const platformLimits = ref([
  { name: 'Facebook', icon: 'tabler-brand-facebook', bg: '#1877F2', limit: 1 },
  { name: 'Instagram', icon: 'tabler-brand-instagram', bg: '#E4405F', limit: 1 },
  { name: 'TikTok', icon: 'tabler-brand-tiktok-filled', bg: '#000000', limit: 1 },
  { name: 'YouTube', icon: 'tabler-brand-youtube', bg: '#FF0000', limit: 1 },
])

const addDaySlot = day => {
  day.slots.push('21:00')
}

const removeDaySlot = (day, idx) => {
  day.slots.splice(idx, 1)
}

const incrementPlatformLimit = pf => {
  pf.limit += 1
}

const decrementPlatformLimit = pf => {
  if (pf.limit > 0)
    pf.limit -= 1
}

// =====================================================================
// CỘT 3: Văn phong AI & Phong cách ảnh
// =====================================================================
const selectedTone = ref('humorous')

const aiTones = [
  { id: 'professional', title: 'Chuyên nghiệp', desc: 'Trang trọng, rõ ràng, đáng tin cậy', icon: 'tabler-briefcase', bg: '#eef2ff', color: '#7367F0' },
  { id: 'friendly', title: 'Thân thiện', desc: 'Gần gũi, dễ hiểu, phù hợp đại chúng', icon: 'tabler-heart-handshake', bg: '#fff2e8', color: '#ff9f43' },
  { id: 'humorous', title: 'Hài hước', desc: 'Vui nhộn, dí dỏm, thu hút tương tác', icon: 'tabler-mood-smile', bg: '#fff8e1', color: '#f5a623' },
  { id: 'inspiring', title: 'Truyền cảm hứng', desc: 'Tích cực, truyền động lực', icon: 'tabler-sparkles', bg: '#fff2e8', color: '#ff9f43' },
  { id: 'sales', title: 'Bán hàng', desc: 'Tập trung lợi ích, kêu gọi hành động', icon: 'tabler-shopping-cart', bg: '#e8fadf', color: '#28c76f' },
  { id: 'educational', title: 'Chia sẻ kiến thức', desc: 'Giáo dục, cung cấp thông tin hữu ích', icon: 'tabler-book', bg: '#e0f2fe', color: '#00cfe8' },
]

const sampleToneText = ref(
  'Lướt trúng bản mẫu thiệp cưới ghi ngày 25.12.2099 mà giật mình, chuẩn bị cho ngày trọng đại trước cả mấy chục năm thế này thì quả là chu đáo luôn 😂',
)

/**
 * =====================================================================
 * CHỨC NĂNG: Đổi câu văn mẫu theo văn phong đang chọn
 * =====================================================================
 * INPUT : không có; đọc selectedTone hiện tại.
 * OUTPUT: cập nhật sampleToneText.
 * SIDE EFFECT: thay đổi nội dung preview văn phong.
 * =====================================================================
 */
const regenerateSample = () => {
  const samples = {
    humorous: 'Cà phê sáng nay đắng hơn mọi ngày, nhưng không sao vì deadline cuối tuần còn đắng gấp đôi! Chốt đơn lẹ kẻo sếp gọi cả nhà ơi 😂',
    professional: 'Giải pháp quản lý lịch đăng tự động giúp đội marketing tiết kiệm 40% thời gian vận hành nội dung mỗi tuần.',
    friendly: 'Bạn ơi, đừng bỏ lỡ ưu đãi cuối tuần nha! Tụi mình dành riêng cho bạn món quà nhỏ xíu thôi nhưng siêu thiệt tình ❤️',
    inspiring: 'Mỗi ngày là một cơ hội mới để bắt đầu lại. Đừng chờ thời điểm hoàn hảo — hãy làm cho khoảnh khắc này hoàn hảo. 🔥',
    sales: 'Chỉ còn 24h! Giảm ngay 30% toàn bộ đơn hàng đầu tiên. Nhấn vào link để chốt đơn ngay kẻo lỡ! 🛍️',
    educational: '3 mẹo nhỏ giúp bài đăng của bạn đạt tương tác cao hơn: 1. Đặt câu hỏi mở, 2. Đăng đúng khung giờ vàng, 3. Dùng hình ảnh thật. 📖',
  }

  sampleToneText.value = samples[selectedTone.value] || samples.humorous
}

const selectedImageStyle = ref('realistic')

const imageStyles = [
  { id: 'realistic', name: 'Ảnh thật', img: 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=200' },
  { id: 'cinematic', name: 'Cinematic', img: 'https://images.unsplash.com/photo-1518709268805-4e9042af9f23?w=200' },
  { id: '3d', name: '3D Illustration', img: 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=200' },
  { id: 'cartoon', name: 'Cartoon', img: 'https://images.unsplash.com/photo-1563089145-599997674d42?w=200' },
  { id: 'watercolor', name: 'Watercolor', img: 'https://images.unsplash.com/photo-1579783902614-a3fb3927b675?w=200' },
  { id: 'minimal', name: 'Minimal', img: 'https://images.unsplash.com/photo-1513519245088-0e12902e5a38?w=200' },
  { id: 'flat', name: 'Flat Design', img: 'https://images.unsplash.com/photo-1558655146-d09347e92766?w=200' },
  { id: 'vintage', name: 'Vintage', img: 'https://images.unsplash.com/photo-1509198397868-475647b2a1e5?w=200' },
  { id: 'cyberpunk', name: 'Cyberpunk', img: 'https://images.unsplash.com/photo-1515630278258-407f66498911?w=200' },
]

// =====================================================================
// Đồng bộ cấu hình hệ thống marketing_schedule (General/Global config)
// =====================================================================
/**
 * =====================================================================
 * CHỨC NĂNG: Nạp cấu hình global từ backend vào các control có binding.
 * =====================================================================
 * INPUT : không có; gọi khi component mounted.
 * OUTPUT: gán autoSendApproval/autoScheduleAfterApprove/postLanguage/timezone.
 * SIDE EFFECT: gọi API GET /marketing/post-schedule/settings; không ghi DB.
 * =====================================================================
 */
const loadSettings = async () => {
  loading.value = true
  try {
    const section = await postScheduleService.settings()
    const values = section?.values ?? section ?? {}

    autoSendApproval.value = values.auto_submit_review !== false
    autoScheduleAfterApprove.value = values.auto_schedule_publish !== false
    postLanguage.value = values.default_language === 'en' ? 'Tiếng Anh (English)' : (values.default_language === 'ja' ? 'Tiếng Nhật' : 'Tiếng Việt')
    timezone.value = values.default_timezone || timezone.value
    settingsLoaded.value = true
  } catch {
    // Không block UI khi chưa có settings; giữ mặc định.
  } finally {
    loading.value = false
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Lưu cấu hình global về backend khi người dùng đổi công tắc.
 * =====================================================================
 * INPUT : không có; đọc state hiện tại.
 * OUTPUT: gọi API PUT settings; không trả giá trị.
 * SIDE EFFECT: ghi group marketing_schedule.
 * =====================================================================
 */
const persistSettings = async () => {
  if (! settingsLoaded.value || saving.value)
    return
  saving.value = true
  try {
    const lang = postLanguage.value === 'Tiếng Anh (English)' ? 'en' : (postLanguage.value === 'Tiếng Nhật' ? 'ja' : 'vi')

    await postScheduleService.updateSettings({
      auto_submit_review: autoSendApproval.value,
      auto_schedule_publish: autoScheduleAfterApprove.value,
      default_language: lang,
      default_timezone: timezone.value,
      platform_quotas_json: platformLimits.value.map(p => ({ name: p.name, quota: p.limit })),
    })
  } catch {
    // Lỗi lưu được backend trả; UI giữ nguyên và cho phép thử lại.
  } finally {
    saving.value = false
  }
}

onMounted(loadSettings)
watch([autoSendApproval, autoScheduleAfterApprove], persistSettings)
</script>

<style lang="scss" scoped>
/* Viền nét đứt nút thêm giờ */
.border-dashed {
  border-style: dashed !important;
}

/* Thẻ chọn kiểu văn phong AI */
.tone-card {
  transition: border-color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
}
.tone-card:hover {
  border-color: rgb(var(--v-theme-primary)) !important;
  background-color: rgba(var(--v-theme-primary), 0.04);
}
.tone-card-active {
  border: 1.5px solid rgb(var(--v-theme-primary)) !important;
  background-color: rgba(var(--v-theme-primary), 0.06);
  box-shadow: 0 4px 14px rgba(var(--v-theme-primary), 0.12);
}
.tone-radio-dot {
  position: absolute;
  inset-block-start: 8px;
  inset-inline-end: 8px;
}
.tone-desc {
  line-height: 1.35;
}

/* Khung ví dụ văn phong */
.sample-tone-box {
  background-color: rgba(var(--v-theme-on-surface), 0.03);
}
.sample-tone-text {
  line-height: 1.5;
}

/* Lưới phong cách tạo ảnh: 5 cột desktop, 3 cột mobile */
.image-style-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 8px;
}

@media (max-width: 600px) {
  .image-style-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

.style-thumb-wrapper {
  border: 2px solid transparent;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.style-thumb-active {
  border-color: rgb(var(--v-theme-primary)) !important;
  box-shadow: 0 2px 8px rgba(var(--v-theme-primary), 0.25);
}

.custom-style-box {
  block-size: 52px;
  border: 1.5px dashed rgba(var(--v-theme-primary), 0.4);
  background-color: rgba(var(--v-theme-primary), 0.04);
  transition: border-color 0.15s ease, background-color 0.15s ease;
}
.custom-style-box:hover {
  background-color: rgba(var(--v-theme-primary), 0.08);
  border-color: rgb(var(--v-theme-primary));
}
</style>
