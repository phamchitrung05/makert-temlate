<!--
  =====================================================================
  CHỨC NĂNG FILE: Dialog tạo chiến dịch mới trong tab "Chiến Dịch"
  (post-schedule). Wizard 5 bước theo chuẩn kiến trúc Campaign-Driven:
  1. Thông tin chung
  2. Nội dung & Văn phong (Từ khóa chiến dịch + Pool Văn phong AI)
  3. Phong cách hình ảnh (Ảnh tham chiếu chủ thể thật + Đa góc chụp + Pool Visual Styles)
  4. Nền tảng & Lịch đăng (Kênh MXH + Tần suất/Thứ đăng + Khung giờ vàng + Story Arc)
  5. Nâng cao (Thuật toán ghép cặp 4 tầng + Cooldown chống lặp + Batch sáng Chủ Nhật)
  =====================================================================

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - currentStep: bước đang active trong wizard (1 -> 5).
  - form: state tổng hợp toàn bộ cấu hình chiến dịch 5 bước.
  - filteredWritingProfiles: danh sách văn phong AI lọc theo trụ cột và từ khóa.
  - filteredVisualStyles: danh sách phong cách hình ảnh lọc theo danh mục và từ khóa.
  - activeShotPresets: danh sách 3–5 góc máy áp dụng theo số lượng ảnh/bài đã chọn.
  - activeDaysPerWeek / estimatedTotalPosts / estimatedTotalImages: computed tính sản lượng tự động.
  - toggleWritingProfile(id) / selectRecommendedTones() / clearWritingProfiles(): quản lý Pool Văn phong.
  - toggleVisualStyle(id) / selectRecommendedStyles() / clearVisualStyles(): quản lý Pool Visual Styles.
  - handleMediaSelect(assets) / addSampleSubjectImage() / removeSubjectImage(id): quản lý 4–6 ảnh tham chiếu thật.
  - togglePlatform(id) / addWeekdaySlot(day) / removeWeekdaySlot(day, idx) / addDailySlot() / removeDailySlot(idx): quản lý lịch đăng.
  - nextStep() / prevStep() / goToStep(stepId): điều hướng giữa các bước.
  - close() / onAfterLeave() / submitCampaign(mode): đóng dialog, dọn state sau hiệu ứng đóng và phát sự kiện tạo chiến dịch.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : modelValue (Boolean) - trạng thái mở/đóng dialog.
  - OUTPUT: emit update:modelValue (Boolean), created (Object chiến dịch mới tạo).
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Payload keys follow the CampaignRequest backend contract. */
import { computed, reactive, ref, shallowRef } from 'vue'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import MediaLibraryDialog from '@/views/apps/media/field/MediaLibraryDialog.vue'
import { postScheduleService } from '@/services/postSchedule'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'created'])

// ─── Wizard Navigation State ─────────────────────────────────────────────────
const currentStep = ref(1)
const isMediaPickerOpen = shallowRef(false)

const steps = [
  { id: 1, title: 'Thông tin chung', subtitle: 'Mục tiêu & thời hạn', icon: 'tabler-info-circle' },
  { id: 2, title: 'Nội dung & Văn phong', subtitle: 'Từ khóa & Pool giọng văn', icon: 'tabler-feather' },
  { id: 3, title: 'Phong cách hình ảnh', subtitle: 'Ảnh thật & Visual Styles', icon: 'tabler-palette' },
  { id: 4, title: 'Nền tảng & Lịch đăng', subtitle: 'Khung giờ & tần suất', icon: 'tabler-share' },
  { id: 5, title: 'Nâng cao', subtitle: 'Ghép cặp & duyệt tự động', icon: 'tabler-adjustments' },
]

// ─── Danh mục Mục tiêu Chiến dịch (Bước 1) ───────────────────────────────────
const objectives = [
  { id: 'product', label: 'Giới thiệu sản phẩm', icon: 'tabler-flag' },
  { id: 'engagement', label: 'Tăng tương tác', icon: 'tabler-heart' },
  { id: 'branding', label: 'Nhận diện thương hiệu', icon: 'tabler-shield' },
  { id: 'sales', label: 'Khuyến mãi & Ưu đãi', icon: 'tabler-tag' },
  { id: 'event', label: 'Sự kiện & Thông báo', icon: 'tabler-calendar-star' },
  { id: 'other', label: 'Khác', icon: 'tabler-dots' },
]

// ─── Kho Văn phong AI mẫu (Bước 2 — Đại diện kho 50 mẫu AiWritingProfile) ───
const pillarFilters = [
  { value: 'all', title: 'Tất cả trụ cột' },
  { value: 'inspire', title: 'Cảm hứng (Inspire)' },
  { value: 'knowledge', title: 'Kiến thức (Knowledge)' },
  { value: 'story', title: 'Câu chuyện (Story)' },
  { value: 'sales', title: 'Bán hàng (Sales)' },
  { value: 'engagement', title: 'Tương tác (Engagement)' },
]

const toneSearchQuery = ref('')
const selectedPillarFilter = ref('all')

const writingProfiles = [
  {
    id: 'humorous',
    name: 'Hài hước & Bắt trend',
    emoji: '😂',
    bg: '#fff8e1',
    pillar: 'inspire',
    pillarLabel: 'Cảm hứng / Tương tác',
    shortDesc: 'Vui nhộn, dí dỏm, câu ngắn có punchline thu hút tương tác tự nhiên.',
    tags: ['Dí dỏm', 'Bắt trend', 'Dễ viral'],
    recommended: true,
  },
  {
    id: 'inspiring',
    name: 'Truyền cảm hứng sáng sớm',
    emoji: '🔥',
    bg: '#fff2e8',
    pillar: 'inspire',
    pillarLabel: 'Cảm hứng (Đầu tuần)',
    shortDesc: 'Lan tỏa năng lượng tích cực đầu tuần, khơi gợi cảm xúc thưởng thức.',
    tags: ['Tích cực', 'Giàu cảm xúc'],
    recommended: true,
  },
  {
    id: 'educational',
    name: 'Chia sẻ kiến thức & Mẹo hay',
    emoji: '📖',
    bg: '#e0f2fe',
    pillar: 'knowledge',
    pillarLabel: 'Kiến thức (Giữa tuần)',
    shortDesc: 'Bóc tách nguyên liệu, quy trình pha chế và mẹo hữu ích dạng gạch đầu dòng.',
    tags: ['Chuyên sâu', 'Rõ ràng'],
    recommended: true,
  },
  {
    id: 'professional',
    name: 'Chuyên nghiệp & Chuẩn mực',
    emoji: '🎓',
    bg: '#eef2ff',
    pillar: 'knowledge',
    pillarLabel: 'Kiến thức / Thương hiệu',
    shortDesc: 'Giọng văn trang trọng, dẫn chứng rõ ràng, khẳng định chất lượng sản phẩm.',
    tags: ['Uy tín', 'Chuẩn mực'],
    recommended: true,
  },
  {
    id: 'storytelling',
    name: 'Kể chuyện hậu trường',
    emoji: '☕',
    bg: '#f3e5f5',
    pillar: 'story',
    pillarLabel: 'Câu chuyện (Thứ 5)',
    shortDesc: 'Tự sự nhẹ nhàng về nguồn gốc hạt cà phê, người pha chế và không gian quán.',
    tags: ['Tự sự', 'Chiều sâu'],
    recommended: true,
  },
  {
    id: 'friendly',
    name: 'Thân thiện & Tâm tình',
    emoji: '❤️',
    bg: '#ffeef0',
    pillar: 'story',
    pillarLabel: 'Câu chuyện / Đời sống',
    shortDesc: 'Ấm áp, cởi mở như một người bạn đang rủ rê ghé quán dịp cuối tuần.',
    tags: ['Gần gũi', 'Ấm áp'],
    recommended: true,
  },
  {
    id: 'sales',
    name: 'Bán hàng & Chốt đơn trực diện',
    emoji: '🛍️',
    bg: '#fce4ec',
    pillar: 'sales',
    pillarLabel: 'Bán hàng (Cuối tuần)',
    shortDesc: 'Tập trung thẳng vào hương vị nổi bật, combo ưu đãi và lời kêu gọi hành động.',
    tags: ['Chốt đơn', 'CTA mạnh'],
    recommended: true,
  },
  {
    id: 'fomo_promo',
    name: 'Săn Deal & Khẩn cấp (FOMO)',
    emoji: '⚡',
    bg: '#fff3e0',
    pillar: 'sales',
    pillarLabel: 'Bán hàng / Ưu đãi',
    shortDesc: 'Nhấn mạnh số lượng giới hạn theo mùa, thúc đẩy đặt hàng ngay kẻo lỡ.',
    tags: ['Khẩn cấp', 'Khuyến mãi'],
    recommended: false,
  },
  {
    id: 'minigame',
    name: 'Tương tác & Minigame cuối tuần',
    emoji: '🎁',
    bg: '#e8f5e9',
    pillar: 'engagement',
    pillarLabel: 'Tương tác (Chủ Nhật)',
    shortDesc: 'Đặt câu hỏi vui, bình chọn món yêu thích hoặc đố vui nhận quà cuối tuần.',
    tags: ['Thảo luận', 'Minigame'],
    recommended: true,
  },
  {
    id: 'review_ugc',
    name: 'Góc nhìn khách hàng (Review)',
    emoji: '⭐',
    bg: '#e0f7fa',
    pillar: 'story',
    pillarLabel: 'Trải nghiệm thực tế',
    shortDesc: 'Viết dưới góc nhìn cảm nhận thực tế của khách hàng sau lần đầu thưởng thức.',
    tags: ['Chân thực', 'Trust'],
    recommended: false,
  },
]

// ─── Kho Ảnh mẫu chủ thể & Phong cách hình ảnh (Bước 3) ──────────────────────
const sampleSubjectPool = [
  {
    id: 'subj-1',
    title: 'Ly Pumpkin Spice Latte chính diện',
    angleLabel: 'Góc chính diện',
    url: 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=400',
  },
  {
    id: 'subj-2',
    title: 'Trà Ô Long Nướng Kem Phô Mai',
    angleLabel: 'Góc nghiêng 45°',
    url: 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=400',
  },
  {
    id: 'subj-3',
    title: 'Cận cảnh lớp kem & bột quế',
    angleLabel: 'Cận cảnh chi tiết',
    url: 'https://images.unsplash.com/photo-1517701550927-30cf4ba1dba5?w=400',
  },
  {
    id: 'subj-4',
    title: 'Bàn gỗ sồi & không gian quán mùa thu',
    angleLabel: 'Bối cảnh thật',
    url: 'https://images.unsplash.com/photo-1501339847302-ac426a4a7cbb?w=400',
  },
  {
    id: 'subj-5',
    title: 'Bộ đôi đồ uống nóng & lạnh trên khay gỗ',
    angleLabel: 'Combo sản phẩm',
    url: 'https://images.unsplash.com/photo-1514432324607-a09d9b4aefdd?w=400',
  },
  {
    id: 'subj-6',
    title: 'Bao bì ly mang đi in logo thương hiệu',
    angleLabel: 'Bao bì & Logo',
    url: 'https://images.unsplash.com/photo-1513558161293-cdaf765ed2fd?w=400',
  },
]

const shotAnglePresets = [
  {
    slot: 1,
    code: 'hero_shot',
    name: 'Hero Shot (Chính diện / 3/4)',
    desc: 'Làm nổi bật chủ thể thật ở vị trí trung tâm, giữ chuẩn bao bì & logo.',
    icon: 'tabler-focus-2',
  },
  {
    slot: 2,
    code: 'macro_detail',
    name: 'Detail / Macro Close-up',
    desc: 'Đặc tả cận cảnh bề mặt, lớp kem, giọt sương hoặc chất liệu thật.',
    icon: 'tabler-zoom-in',
  },
  {
    slot: 3,
    code: 'lifestyle_context',
    name: 'Lifestyle / Bối cảnh sử dụng',
    desc: 'Đặt chủ thể thật trong không gian thưởng thức tự nhiên, giàu cảm xúc.',
    icon: 'tabler-cup',
  },
  {
    slot: 4,
    code: 'dynamic_45',
    name: 'Dynamic 45° Perspective',
    desc: 'Góc nghiêng 45 độ tạo chiều sâu không gian và ánh sáng ven nổi khối.',
    icon: 'tabler-angle',
  },
  {
    slot: 5,
    code: 'flatlay_overview',
    name: 'Flat-lay / Top-down Overview',
    desc: 'Góc chụp từ trên xuống bao quát tổng thể sản phẩm kèm phụ kiện decor.',
    icon: 'tabler-layout-grid',
  },
]

const styleSearchQuery = ref('')
const selectedStyleCategory = ref('Tất cả danh mục')

const visualStyles = [
  {
    id: 'photorealistic',
    name: 'Ảnh thật (Photorealistic)',
    shortDesc: 'Chân thực, ánh sáng tự nhiên, giữ trọn kết cấu sản phẩm thật.',
    category: 'Sản phẩm',
    tags: ['Chân thực', 'Sản phẩm'],
    image: 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=500',
    recommended: true,
  },
  {
    id: 'food-photography',
    name: 'Food & Drink Commercial',
    shortDesc: 'Chụp thương mại đồ ăn thức uống sắc nét, kích thích vị giác.',
    category: 'Đồ ăn',
    tags: ['Đồ ăn', 'Thương mại'],
    image: 'https://images.unsplash.com/photo-1509722747041-616f39b57569?w=500',
    recommended: true,
  },
  {
    id: 'cinematic',
    name: 'Cinematic Warm Tone',
    shortDesc: 'Ánh sáng điện ảnh ấm áp, bokeh chiều sâu chuẩn phim nhựa.',
    category: 'Nghệ thuật',
    tags: ['Điện ảnh', 'Warm Tone'],
    image: 'https://images.unsplash.com/photo-1506744038136-46273834b3fb?w=500',
    recommended: true,
  },
  {
    id: 'minimal',
    name: 'Minimal Studio Clean',
    shortDesc: 'Bố cục tối giản, nền sáng gọn gàng làm nổi bật chủ thể chính.',
    category: 'Hiện đại',
    tags: ['Tối giản', 'Studio'],
    image: 'https://images.unsplash.com/photo-1513519245088-0e12902e5a38?w=500',
    recommended: true,
  },
  {
    id: 'vintage',
    name: 'Vintage Autumn Film',
    shortDesc: 'Tông màu nâu trầm hoài niệm, rất hợp chủ đề mùa thu & cà phê.',
    category: 'Cổ điển',
    tags: ['Cổ điển', 'Mùa thu'],
    image: 'https://images.unsplash.com/photo-1509198397868-475647b2a1e5?w=500',
    recommended: true,
  },
  {
    id: '3d-illustration',
    name: '3D Illustration',
    shortDesc: 'Tạo hình 3D hiện đại, bề mặt mịn màng và ánh sáng studio mềm.',
    category: 'Hiện đại',
    tags: ['3D', 'Sáng tạo'],
    image: 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=500',
    recommended: true,
  },
  {
    id: 'watercolor',
    name: 'Watercolor Artistic',
    shortDesc: 'Hiệu ứng màu nước nhẹ nhàng, thơ mộng trên nền giấy mỹ thuật.',
    category: 'Nghệ thuật',
    tags: ['Màu nước', 'Nghệ thuật'],
    image: 'https://images.unsplash.com/photo-1579783902614-a3fb3927b675?w=500',
    recommended: true,
  },
  {
    id: 'isometric',
    name: 'Isometric Diorama',
    shortDesc: 'Góc nhìn nghiêng mô hình thu nhỏ độc đáo cho không gian quán.',
    category: 'Hiện đại',
    tags: ['Isometric', 'Không gian'],
    image: 'https://images.unsplash.com/photo-1554118811-1e0d58224f24?w=500',
    recommended: true,
  },
  {
    id: 'flat-design',
    name: 'Modern Flat Design',
    shortDesc: 'Thiết kế phẳng hiện đại, phù hợp bài infographic mẹo vặt & kiến thức.',
    category: 'Hiện đại',
    tags: ['Flat', 'Infographic'],
    image: 'https://images.unsplash.com/photo-1558655146-d09347e92766?w=500',
    recommended: false,
  },
  {
    id: 'cartoon',
    name: 'Vibrant Lifestyle 2D',
    shortDesc: 'Minh họa 2D tươi sáng, năng động cho các bài minigame cuối tuần.',
    category: 'Nhân vật',
    tags: ['Hoạt hình', 'Minigame'],
    image: 'https://images.unsplash.com/photo-1563089145-599997674d42?w=500',
    recommended: false,
  },
  {
    id: 'hand-drawn-sketch',
    name: 'Hand-drawn Artisan Sketch',
    shortDesc: 'Nét phác họa thủ công mộc mạc, tôn vinh giá trị nguyên bản.',
    category: 'Nghệ thuật',
    tags: ['Phác họa', 'Mộc mạc'],
    image: 'https://images.unsplash.com/photo-1513364776144-60967b0f800f?w=500',
    recommended: false,
  },
  {
    id: 'cyberpunk',
    name: 'Neon Night Vibes',
    shortDesc: 'Ánh đèn neon nổi bật cho các chiến dịch sự kiện buổi tối.',
    category: 'Hiện đại',
    tags: ['Neon', 'Ban đêm'],
    image: 'https://images.unsplash.com/photo-1515630278258-407f66498911?w=500',
    recommended: false,
  },
]

// ─── Danh sách Nền tảng & Chế độ Ghép cặp (Bước 4 & 5) ───────────────────────
const platformOptions = [
  {
    id: 'fb',
    name: 'Facebook Page',
    icon: 'tabler-brand-facebook',
    color: '#1877F2',
    desc: 'Đăng bài kèm Album 3–5 ảnh đa góc chụp & Caption đầy đủ.',
    safetyCap: 'Tối đa 2 bài/ngày',
  },
  {
    id: 'ig',
    name: 'Instagram Business',
    icon: 'tabler-brand-instagram',
    color: '#E4405F',
    desc: 'Tối ưu Carousel ảnh vuông/dọc 4:5 và bộ Hashtag dưới bình luận.',
    safetyCap: 'Tối đa 2 bài/ngày',
  },
  {
    id: 'tt',
    name: 'TikTok Photo Mode',
    icon: 'tabler-brand-tiktok',
    color: '#111111',
    desc: 'Trình chiếu ảnh trượt (Photo Carousel) kèm hook ngắn bắt trend.',
    safetyCap: 'Tối đa 1 bài/ngày',
  },
  {
    id: 'yt',
    name: 'YouTube Community',
    icon: 'tabler-brand-youtube',
    color: '#FF0000',
    desc: 'Bài đăng cộng đồng tương tác, bình chọn và quảng bá sản phẩm.',
    safetyCap: 'Tối đa 1 bài/ngày',
  },
]

const pairingModes = [
  {
    id: 'smart_4_layer',
    title: 'Tự động thông minh 4 tầng (Khuyên dùng)',
    icon: 'tabler-wand',
    badge: 'Tối ưu nhất',
    desc: 'Chấm điểm Khớp trụ cột ngày đăng (40đ) + Tương thích Giọng văn ↔ Thị giác (30đ) + Xoay vòng không lặp Pool (30đ) + Phối 3–5 góc máy.',
  },
  {
    id: 'sequential_rotation',
    title: 'Xoay vòng tuần tự (Round-Robin)',
    icon: 'tabler-repeat',
    badge: 'Tuần tự',
    desc: 'Lần lượt lấy từng Văn phong, Visual Style và Ảnh tham chiếu theo đúng thứ tự đã chọn trước khi quay vòng lại.',
  },
  {
    id: 'random_no_repeat',
    title: 'Ngẫu nhiên chống lặp trong tuần',
    icon: 'tabler-arrows-shuffle',
    badge: 'Đa dạng',
    desc: 'Xáo trộn ngẫu nhiên các tổ hợp trong Pool chiến dịch nhưng khóa cứng không trùng lặp bất kỳ mẫu nào trong cùng 1 tuần.',
  },
]

// ─── Khởi tạo dữ liệu mặc định cho Form 5 bước ───────────────────────────────
function createDefaultForm() {
  return {
    // Bước 1: Thông tin chung
    name: 'BST Đồ uống mùa thu',
    isActive: true,
    objective: 'product',
    description: 'Giới thiệu bộ sưu tập đồ uống mùa thu, nhấn mạnh hương vị mới, không gian ấm cúng và ưu đãi trong tháng.',
    dateRange: '01/10/2026 – 20/10/2026',
    durationDays: 20,
    budget: '5,000,000',
    tags: ['Sản phẩm mới', 'Mùa thu', 'Đồ uống'],

    // Bước 2: Nội dung & Văn phong
    keywords: [
      'Đồ uống mùa thu',
      'Pumpkin Spice Latte',
      'Trà Ô Long Nướng Kem Phô Mai',
      'Không gian ấm cúng',
      'Ưu đãi tháng 10',
    ],
    hashtags: ['#CaPheMoc', '#BSTDoUongMuaThu', '#AutumnVibes', '#CoffeeLover'],
    contentInstructions: 'Nhấn mạnh nguyên liệu tự nhiên, lớp kem phô mai sánh mịn và hương quế ấm áp đặc trưng mùa thu. Luôn kèm lời mời ghé quán hoặc đặt giao hàng nhanh.',
    writingProfileIds: ['humorous', 'inspiring', 'educational', 'professional', 'storytelling', 'friendly', 'sales', 'minigame'],

    // Bước 3: Phong cách hình ảnh & Ảnh tham chiếu
    subjectMedia: sampleSubjectPool.slice(0, 4).map(item => ({ ...item })),
    subjectDescription: 'Ly thủy tinh trong suốt in logo Cà Phê Mộc màu nâu vàng, lớp kem phô mai sánh mịn phủ bột quế trên mặt, đặt trên bàn gỗ sồi tự nhiên.',
    imageCount: 4,
    imageProfileIds: ['photorealistic', 'food-photography', 'cinematic', 'minimal', 'vintage', '3d-illustration', 'watercolor', 'isometric'],

    // Bước 4: Nền tảng & Lịch đăng
    platforms: ['fb', 'ig'],
    scheduleMode: 'weekdays',
    dailyPostCount: 1,
    dailyTimeSlots: ['08:00', '19:30'],
    weeklySchedule: [
      {
        key: 'mon',
        name: 'Thứ 2',
        enabled: true,
        pillar: 'inspire',
        pillarLabel: 'Cảm hứng (Inspire)',
        pillarColor: 'info',
        angleHint: 'Năng lượng đầu tuần & cảm hứng thưởng thức đồ uống mùa thu',
        slots: ['08:00'],
      },
      {
        key: 'tue',
        name: 'Thứ 3',
        enabled: false,
        pillar: 'inspire',
        pillarLabel: 'Cảm hứng (Inspire)',
        pillarColor: 'info',
        angleHint: 'Góc nhìn đời sống & thói quen buổi sáng',
        slots: ['08:30'],
      },
      {
        key: 'wed',
        name: 'Thứ 4',
        enabled: true,
        pillar: 'knowledge',
        pillarLabel: 'Kiến thức (Knowledge)',
        pillarColor: 'primary',
        angleHint: 'Điểm đặc biệt của nguyên liệu & hương vị mới',
        slots: ['12:00'],
      },
      {
        key: 'thu',
        name: 'Thứ 5',
        enabled: true,
        pillar: 'story',
        pillarLabel: 'Câu chuyện (Story)',
        pillarColor: 'secondary',
        angleHint: 'Chuyện hậu trường pha chế & trải nghiệm khách hàng',
        slots: ['19:30'],
      },
      {
        key: 'fri',
        name: 'Thứ 6',
        enabled: true,
        pillar: 'sales',
        pillarLabel: 'Bán hàng (Sales)',
        pillarColor: 'success',
        angleHint: 'Giới thiệu trực diện sản phẩm, ưu đãi chiến dịch & CTA chốt đơn',
        slots: ['17:30'],
      },
      {
        key: 'sat',
        name: 'Thứ 7',
        enabled: false,
        pillar: 'sales',
        pillarLabel: 'Bán hàng (Sales)',
        pillarColor: 'success',
        angleHint: 'Combo hẹn hò cuối tuần & ưu đãi nhóm',
        slots: ['17:30'],
      },
      {
        key: 'sun',
        name: 'Chủ Nhật',
        enabled: true,
        pillar: 'engagement',
        pillarLabel: 'Tương tác (Engagement)',
        pillarColor: 'warning',
        angleHint: 'Câu hỏi thảo luận vui, bình chọn hương vị hoặc minigame',
        slots: ['09:00'],
      },
    ],

    // Bước 5: Nâng cao
    pairingMode: 'smart_4_layer',
    strictWeeklyNoRepeat: true,
    rotateAllSubjectMedia: true,
    toneCooldownDays: 5,
    styleCooldownDays: 5,
    priorityLevel: 'normal',
    sundayBatchEnabled: true,
    sundayBatchTime: '07:00',
    generateCurrentWeekImmediately: true,
    autoSubmitReview: true,
    autoPublishAfterApprove: true,
    abTesting: false,
  }
}

const form = reactive(createDefaultForm())

// ─── Computed hỗ trợ lọc và tính toán tự động ────────────────────────────────
const filteredWritingProfiles = computed(() => {
  return writingProfiles.filter(profile => {
    const matchPillar = selectedPillarFilter.value === 'all' || profile.pillar === selectedPillarFilter.value
    const q = toneSearchQuery.value.trim().toLowerCase()

    const matchSearch = !q
      || profile.name.toLowerCase().includes(q)
      || profile.shortDesc.toLowerCase().includes(q)
      || profile.tags.some(t => t.toLowerCase().includes(q))

    return matchPillar && matchSearch
  })
})

const filteredVisualStyles = computed(() => {
  return visualStyles.filter(style => {
    const matchCat = selectedStyleCategory.value === 'Tất cả danh mục'
      || style.category === selectedStyleCategory.value
      || style.tags.includes(selectedStyleCategory.value)

    const q = styleSearchQuery.value.trim().toLowerCase()

    const matchSearch = !q
      || style.name.toLowerCase().includes(q)
      || style.shortDesc.toLowerCase().includes(q)
      || style.tags.some(t => t.toLowerCase().includes(q))

    return matchCat && matchSearch
  })
})

const activeShotPresets = computed(() => {
  return shotAnglePresets.slice(0, form.imageCount)
})

const activeDaysPerWeek = computed(() => {
  if (form.scheduleMode === 'daily')
    return 7

  return form.weeklySchedule.filter(day => day.enabled).length
})

const postsPerWeek = computed(() => {
  if (form.scheduleMode === 'daily')
    return form.dailyPostCount * 7

  return form.weeklySchedule
    .filter(day => day.enabled)
    .reduce((sum, day) => sum + Math.max(day.slots.length, 1), 0)
})

const estimatedTotalPosts = computed(() => {
  const days = Math.max(Number(form.durationDays) || 1, 1)
  if (form.scheduleMode === 'daily')
    return days * form.dailyPostCount

  return Math.max(Math.round((days / 7) * postsPerWeek.value), 1)
})

const estimatedTotalImages = computed(() => {
  return estimatedTotalPosts.value * form.imageCount
})

const selectedObjectiveLabel = computed(() => {
  return objectives.find(o => o.id === form.objective)?.label || 'Giới thiệu sản phẩm'
})

// ─── Các hàm xử lý Bước 2: Pool Văn phong AI ─────────────────────────────────
/**
 * =====================================================================
 * CHỨC NĂNG: Bật/tắt một Văn phong AI trong Pool của chiến dịch
 * =====================================================================
 * INPUT : profileId - mã văn phong được click.
 * OUTPUT: cập nhật mảng form.writingProfileIds.
 * SIDE EFFECT: thay đổi state form.writingProfileIds.
 * =====================================================================
 */
function toggleWritingProfile(profileId) {
  const idx = form.writingProfileIds.indexOf(profileId)
  if (idx >= 0)
    form.writingProfileIds.splice(idx, 1)
  else
    form.writingProfileIds.push(profileId)
}

/**
 * =====================================================================
 * CHỨC NĂNG: Chọn nhanh bộ 8 văn phong gợi ý phủ đủ 5 trụ cột
 * =====================================================================
 * INPUT : không có.
 * OUTPUT: gán các văn phong recommended vào form.writingProfileIds.
 * SIDE EFFECT: cập nhật state form.writingProfileIds.
 * =====================================================================
 */
function selectRecommendedTones() {
  form.writingProfileIds = writingProfiles.filter(p => p.recommended).map(p => p.id)
}

/**
 * =====================================================================
 * CHỨC NĂNG: Bỏ chọn toàn bộ văn phong trong Pool
 * =====================================================================
 * INPUT : không có.
 * OUTPUT: làm rỗng form.writingProfileIds.
 * SIDE EFFECT: cập nhật state form.writingProfileIds.
 * =====================================================================
 */
function clearWritingProfiles() {
  form.writingProfileIds = []
}

// ─── Các hàm xử lý Bước 3: Ảnh tham chiếu thật & Pool Visual Styles ──────────
/**
 * =====================================================================
 * CHỨC NĂNG: Nhận danh sách file ảnh từ MediaLibraryDialog và ghép vào Pool ảnh chủ thể
 * =====================================================================
 * INPUT : assets - mảng MediaAsset từ MediaLibraryDialog.
 * OUTPUT: cập nhật form.subjectMedia (tối đa 6 ảnh).
 * SIDE EFFECT: thay đổi state form.subjectMedia.
 * =====================================================================
 */
function handleMediaSelect(assets) {
  const list = Array.isArray(assets) ? assets : (assets ? [assets] : [])
  if (!list.length)
    return

  const existingIds = new Set(form.subjectMedia.map(item => String(item.id)))

  for (const asset of list) {
    if (form.subjectMedia.length >= 6)
      break
    const id = String(asset.id)
    if (existingIds.has(id))
      continue

    const previewUrl = asset?.file?.preview_url || asset?.file?.url || sampleSubjectPool[0].url

    form.subjectMedia.push({
      id: asset.id,
      title: asset.title || 'Ảnh tham chiếu sản phẩm',
      angleLabel: 'Thư viện Media',
      url: previewUrl,
    })
    existingIds.add(id)
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Thêm nhanh ảnh mẫu chủ thể tiếp theo vào danh sách (tối đa 6 ảnh)
 * =====================================================================
 * INPUT : không có.
 * OUTPUT: thêm 1 ảnh từ sampleSubjectPool chưa có trong form.subjectMedia.
 * SIDE EFFECT: thay đổi state form.subjectMedia.
 * =====================================================================
 */
function addSampleSubjectImage() {
  if (form.subjectMedia.length >= 6)
    return

  const usedIds = new Set(form.subjectMedia.map(item => item.id))

  const nextSample = sampleSubjectPool.find(item => !usedIds.has(item.id)) || {
    id: `subj-${Date.now()}`,
    title: `Ảnh chủ thể thật #${form.subjectMedia.length + 1}`,
    angleLabel: 'Góc bổ sung',
    url: sampleSubjectPool[form.subjectMedia.length % sampleSubjectPool.length].url,
  }

  form.subjectMedia.push({ ...nextSample })
}

/**
 * =====================================================================
 * CHỨC NĂNG: Xóa 1 ảnh tham chiếu chủ thể khỏi danh sách chiến dịch
 * =====================================================================
 * INPUT : mediaId - id của ảnh cần bỏ.
 * OUTPUT: loại bỏ phần tử khỏi form.subjectMedia.
 * SIDE EFFECT: thay đổi state form.subjectMedia.
 * =====================================================================
 */
function removeSubjectImage(mediaId) {
  const idx = form.subjectMedia.findIndex(item => item.id === mediaId)
  if (idx >= 0)
    form.subjectMedia.splice(idx, 1)
}

/**
 * =====================================================================
 * CHỨC NĂNG: Bật/tắt 1 Visual Style trong Pool phong cách hình ảnh
 * =====================================================================
 * INPUT : styleId - mã phong cách ảnh được click.
 * OUTPUT: cập nhật mảng form.imageProfileIds.
 * SIDE EFFECT: thay đổi state form.imageProfileIds.
 * =====================================================================
 */
function toggleVisualStyle(styleId) {
  const idx = form.imageProfileIds.indexOf(styleId)
  if (idx >= 0)
    form.imageProfileIds.splice(idx, 1)
  else
    form.imageProfileIds.push(styleId)
}

/**
 * =====================================================================
 * CHỨC NĂNG: Chọn nhanh 8 Visual Styles gợi ý cho chiến dịch
 * =====================================================================
 * INPUT : không có.
 * OUTPUT: gán danh sách style recommended vào form.imageProfileIds.
 * SIDE EFFECT: cập nhật state form.imageProfileIds.
 * =====================================================================
 */
function selectRecommendedStyles() {
  form.imageProfileIds = visualStyles.filter(s => s.recommended).map(s => s.id)
}

/**
 * =====================================================================
 * CHỨC NĂNG: Bỏ chọn toàn bộ Visual Styles trong Pool
 * =====================================================================
 * INPUT : không có.
 * OUTPUT: làm rỗng form.imageProfileIds.
 * SIDE EFFECT: cập nhật state form.imageProfileIds.
 * =====================================================================
 */
function clearVisualStyles() {
  form.imageProfileIds = []
}

// ─── Các hàm xử lý Bước 4: Nền tảng & Khung giờ đăng bài ─────────────────────
/**
 * =====================================================================
 * CHỨC NĂNG: Bật/tắt nền tảng mạng xã hội cho chiến dịch
 * =====================================================================
 * INPUT : platformId - mã nền tảng ('fb', 'ig', 'tt', 'yt').
 * OUTPUT: cập nhật form.platforms (giữ ít nhất 1 nền tảng).
 * SIDE EFFECT: thay đổi state form.platforms.
 * =====================================================================
 */
function togglePlatform(platformId) {
  const idx = form.platforms.indexOf(platformId)
  if (idx >= 0) {
    if (form.platforms.length > 1)
      form.platforms.splice(idx, 1)
  }
  else {
    form.platforms.push(platformId)
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Thêm khung giờ vàng cho một Thứ trong tuần
 * =====================================================================
 * INPUT : day - object ngày trong form.weeklySchedule.
 * OUTPUT: thêm mốc giờ mới vào day.slots và bật ngày đó.
 * SIDE EFFECT: cập nhật state form.weeklySchedule.
 * =====================================================================
 */
function addWeekdaySlot(day) {
  const candidateTimes = ['08:00', '12:00', '17:30', '19:30', '21:00']
  const nextTime = candidateTimes.find(t => !day.slots.includes(t)) || '20:00'

  day.slots.push(nextTime)
  day.enabled = true
}

/**
 * =====================================================================
 * CHỨC NĂNG: Xóa một khung giờ của Thứ trong tuần
 * =====================================================================
 * INPUT : day - object ngày, slotIdx - vị trí khung giờ.
 * OUTPUT: xóa phần tử tại slotIdx.
 * SIDE EFFECT: cập nhật state form.weeklySchedule.
 * =====================================================================
 */
function removeWeekdaySlot(day, slotIdx) {
  day.slots.splice(slotIdx, 1)
}

/**
 * =====================================================================
 * CHỨC NĂNG: Thêm khung giờ cho chế độ đăng cố định hàng ngày
 * =====================================================================
 * INPUT : không có.
 * OUTPUT: thêm giờ mới vào form.dailyTimeSlots.
 * SIDE EFFECT: cập nhật state form.dailyTimeSlots.
 * =====================================================================
 */
function addDailySlot() {
  const candidateTimes = ['08:00', '12:00', '17:30', '19:30', '21:00']
  const nextTime = candidateTimes.find(t => !form.dailyTimeSlots.includes(t)) || '20:30'

  form.dailyTimeSlots.push(nextTime)
}

/**
 * =====================================================================
 * CHỨC NĂNG: Xóa một khung giờ trong chế độ đăng hàng ngày
 * =====================================================================
 * INPUT : idx - vị trí cần xóa trong form.dailyTimeSlots.
 * OUTPUT: xóa phần tử tại idx.
 * SIDE EFFECT: cập nhật state form.dailyTimeSlots.
 * =====================================================================
 */
function removeDailySlot(idx) {
  if (form.dailyTimeSlots.length > 1)
    form.dailyTimeSlots.splice(idx, 1)
}

// ─── Điều hướng Wizard & Lưu Chiến Dịch ──────────────────────────────────────
/**
 * =====================================================================
 * CHỨC NĂNG: Chuyển sang bước kế tiếp trong Wizard 5 bước
 * =====================================================================
 * INPUT : không có.
 * OUTPUT: tăng currentStep thêm 1 nếu chưa ở bước cuối.
 * SIDE EFFECT: thay đổi currentStep.
 * =====================================================================
 */
function nextStep() {
  if (currentStep.value < steps.length)
    currentStep.value++
}

/**
 * =====================================================================
 * CHỨC NĂNG: Quay lại bước trước đó trong Wizard
 * =====================================================================
 * INPUT : không có.
 * OUTPUT: giảm currentStep đi 1 nếu lớn hơn 1.
 * SIDE EFFECT: thay đổi currentStep.
 * =====================================================================
 */
function prevStep() {
  if (currentStep.value > 1)
    currentStep.value--
}

/**
 * =====================================================================
 * CHỨC NĂNG: Nhảy trực tiếp tới một bước bất kỳ trên thanh bên trái
 * =====================================================================
 * INPUT : stepId - số thứ tự bước (1 -> 5).
 * OUTPUT: gán currentStep = stepId.
 * SIDE EFFECT: thay đổi currentStep.
 * =====================================================================
 */
function goToStep(stepId) {
  currentStep.value = stepId
}

/**
 * =====================================================================
 * CHỨC NĂNG: Đóng dialog Tạo Chiến Dịch Mới
 * =====================================================================
 * INPUT : không có.
 * OUTPUT: emit update:modelValue = false.
 * SIDE EFFECT: đóng VDialog; việc reset state đợi tới @after-leave.
 * =====================================================================
 */
function close() {
  emit('update:modelValue', false)
}

/**
 * =====================================================================
 * CHỨC NĂNG: Dọn trạng thái tạm sau khi hiệu ứng đóng dialog hoàn tất
 * =====================================================================
 * INPUT : sự kiện @after-leave của VDialog.
 * OUTPUT: đưa currentStep về 1 và reset bộ lọc tìm kiếm.
 * SIDE EFFECT: tránh giật giao diện trong lúc đóng dialog.
 * =====================================================================
 */
function onAfterLeave() {
  currentStep.value = 1
  toneSearchQuery.value = ''
  selectedPillarFilter.value = 'all'
  styleSearchQuery.value = ''
  selectedStyleCategory.value = 'Tất cả danh mục'
}

/**
 * =====================================================================
 * CHỨC NĂNG: Lưu hoặc Kích hoạt chiến dịch ở bước cuối
 * =====================================================================
 * INPUT : mode - 'draft' (Lưu nháp) hoặc 'active' (Lưu & Kích hoạt).
 * OUTPUT: emit 'created' kèm dữ liệu chiến dịch và đóng dialog.
 * SIDE EFFECT: phát sự kiện lên CampaignTab và đóng dialog.
 * =====================================================================
 */
const submitting = ref(false)

/**
 * =====================================================================
 * CHỨC NĂNG: Lưu hoặc Kích hoạt chiến dịch ở bước cuối
 * =====================================================================
 * INPUT : mode - 'draft' (Lưu nháp) hoặc 'active' (Lưu & Kích hoạt).
 * OUTPUT: gọi POST /marketing/campaigns, emit 'created' và đóng dialog.
 * SIDE EFFECT: tạo campaign qua API; khi lỗi giữ dialog mở để thử lại.
 * =====================================================================
 */
async function submitCampaign(mode = 'active') {
  if (submitting.value)
    return
  submitting.value = true

  const isActivating = mode === 'active' && form.isActive

  // Map form wizard 5 bước -> payload CampaignRequest backend.
  const [startStr, endStr] = (form.dateRange || '').split('–').map(s => s.trim())

  const toIso = s => {
    const m = s?.match(/(\d{2})\/(\d{2})\/(\d{4})/)
    
    return m ? `${m[3]}-${m[2]}-${m[1]}` : null
  }

  const apiPayload = {
    name: form.name.trim() || 'Chiến dịch mới',
    objective: form.objective,
    message: form.contentInstructions || form.description || null,
    starts_at: toIso(startStr),
    ends_at: toIso(endStr),
    timezone: 'Asia/Ho_Chi_Minh',
    status: isActivating ? 'active' : 'draft',
    budget: form.budget ? Number(String(form.budget).replace(/[^\d.]/g, '')) || null : null,
    keywords_json: [...form.keywords, ...form.hashtags],
    writing_profile_ids: form.writingProfileIds.map(id => Number(String(id).replace(/\D/g, '')) || null).filter(Boolean),
    image_profile_ids: form.imageProfileIds.map(id => Number(String(id).replace(/\D/g, '')) || null).filter(Boolean),
    subject_media_ids: form.subjectMedia.map(m => Number(m.id)).filter(n => Number.isInteger(n)),
    subject_description: form.subjectDescription || null,
    platforms_json: [...form.platforms],
    schedule_config_json: {
      mode: form.scheduleMode,
      daily_post_count: form.dailyPostCount,
      time_slots: form.scheduleMode === 'daily' ? [...form.dailyTimeSlots] : [],
      active_weekdays: form.scheduleMode === 'weekdays'
        ? form.weeklySchedule.filter(d => d.enabled).map(d => ({ key: d.key, enabled: true, slots: d.slots, pillar: d.pillar }))
        : [],
      image_count: form.imageCount,
    },
    advanced_config_json: {
      pairing_mode: form.pairingMode,
      strict_weekly_no_repeat: form.strictWeeklyNoRepeat,
      rotate_all_subject_media: form.rotateAllSubjectMedia,
      tone_cooldown_days: form.toneCooldownDays,
      style_cooldown_days: form.styleCooldownDays,
      priority_level: form.priorityLevel,
      sunday_batch_enabled: form.sundayBatchEnabled,
      sunday_batch_time: form.sundayBatchTime,
      generate_current_week_immediately: form.generateCurrentWeekImmediately,
      auto_submit_review: form.autoSubmitReview,
      auto_publish_after_approve: form.autoPublishAfterApprove,
      ab_testing: form.abTesting,
    },
  }

  try {
    const created = await postScheduleService.createCampaign(apiPayload)

    emit('created', {
      id: created?.id ?? Date.now(),
      name: created?.name ?? apiPayload.name,
      subtitle: form.description.trim() || 'Chiến dịch đăng bài tự động theo tuần',
      dateRange: form.dateRange || '—',
      duration: `${form.durationDays} ngày`,
      objective: selectedObjectiveLabel.value.split(' ')[0] || 'Product',
      fullObjective: selectedObjectiveLabel.value,
      platforms: [...form.platforms],
      posts: estimatedTotalPosts.value,
      progress: 0,
      status: isActivating ? 'Đang chạy' : 'Nháp',
      image: form.subjectMedia[0]?.url || 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=200',
    })
    close()
  } catch {
    // Lỗi validation/backend giữ dialog mở; admin có thể sửa và lưu lại.
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <VDialog
    :model-value="props.modelValue"
    max-width="1400"
    persistent
    scrollable
    @update:model-value="!$event && close()"
    @after-leave="onAfterLeave"
  >
    <AppDialogLayout
      title="Tạo Chiến Dịch Mới"
      subtitle="Thiết lập chiến dịch marketing trung tâm (Campaign-Driven) cho lịch đăng bài tự động hàng tuần"
      :body-scroll="false"
      @close="close"
    >
      <!-- ================= BODY: 2 CỘT WIZARD ================= -->
      <div class="wizard-layout d-flex h-100">
        <!-- CỘT TRÁI: DANH SÁCH 5 BƯỚC -->
        <aside class="step-sidebar pa-4 border-e flex-shrink-0 d-flex flex-column justify-space-between">
          <div class="d-flex flex-column gap-2">
            <div
              v-for="step in steps"
              :key="step.id"
              class="step-item d-flex align-center pa-3 rounded-lg cursor-pointer"
              :class="{
                'step-active': currentStep === step.id,
                'step-completed': currentStep > step.id,
              }"
              @click="goToStep(step.id)"
            >
              <VAvatar
                size="32"
                rounded="lg"
                :color="currentStep === step.id ? 'primary' : currentStep > step.id ? 'success' : 'secondary'"
                :variant="currentStep === step.id ? 'flat' : 'tonal'"
                class="me-3 flex-shrink-0"
              >
                <VIcon
                  :icon="currentStep > step.id ? 'tabler-check' : step.icon"
                  size="18"
                />
              </VAvatar>

              <div class="flex-grow-1 min-inline-size-0 overflow-hidden">
                <div
                  class="text-body-2 font-weight-medium text-truncate"
                  :class="currentStep === step.id ? 'text-primary font-weight-bold' : 'text-high-emphasis'"
                >
                  {{ step.title }}
                </div>
                <div class="text-caption text-medium-emphasis text-truncate">
                  {{ step.subtitle }}
                </div>
              </div>
            </div>
          </div>

          <!-- Tóm tắt nhanh tiến độ cấu hình Pool ở đáy sidebar -->
          <div class="border rounded-lg pa-3 mt-4 bg-var-theme-background">
            <div class="text-caption font-weight-bold text-high-emphasis mb-2">
              Nguyên liệu chiến dịch
            </div>
            <div class="d-flex flex-column gap-1 text-caption text-medium-emphasis">
              <div class="d-flex justify-space-between">
                <span>Từ khóa:</span>
                <span class="font-weight-medium text-high-emphasis">{{ form.keywords.length }} từ khóa</span>
              </div>
              <div class="d-flex justify-space-between">
                <span>Pool Văn phong:</span>
                <span class="font-weight-medium text-primary">{{ form.writingProfileIds.length }} mẫu</span>
              </div>
              <div class="d-flex justify-space-between">
                <span>Ảnh chủ thể thật:</span>
                <span class="font-weight-medium text-high-emphasis">{{ form.subjectMedia.length }}/6 ảnh</span>
              </div>
              <div class="d-flex justify-space-between">
                <span>Pool Visual Styles:</span>
                <span class="font-weight-medium text-primary">{{ form.imageProfileIds.length }} style</span>
              </div>
              <div class="d-flex justify-space-between">
                <span>Sản lượng dự kiến:</span>
                <span class="font-weight-bold text-success">{{ estimatedTotalPosts }} bài ({{ estimatedTotalImages }} ảnh)</span>
              </div>
            </div>
          </div>
        </aside>

        <!-- CỘT PHẢI: NỘI DUNG TỪNG BƯỚC (CHỈ VÙNG NÀY CUỘN) -->
        <div class="step-content pa-6 flex-grow-1 overflow-y-auto">
          <!-- ========================================================= -->
          <!-- BƯỚC 1: THÔNG TIN CHUNG                                   -->
          <!-- ========================================================= -->
          <div v-if="currentStep === 1">
            <div class="text-subtitle-1 font-weight-medium text-high-emphasis mb-1">
              Thông tin chung
            </div>
            <div class="text-body-2 text-medium-emphasis mb-4">
              Thiết lập tên, mục tiêu, thời hạn hiệu lực và phạm vi ngân sách cho chiến dịch đăng bài.
            </div>

            <!-- Tên + Trạng thái -->
            <VRow
              dense
              class="mb-4"
            >
              <VCol
                cols="12"
                sm="8"
              >
                <AppTextField
                  v-model="form.name"
                  label="Tên chiến dịch"
                  placeholder="Nhập tên chiến dịch (VD: BST Đồ uống mùa thu)"
                  required
                />
              </VCol>
              <VCol
                cols="12"
                sm="4"
                class="d-flex flex-column justify-center"
              >
                <div class="d-flex align-center mt-sm-4">
                  <VSwitch
                    v-model="form.isActive"
                    color="primary"
                    density="compact"
                    hide-details
                    inset
                    class="me-2"
                  />
                  <div>
                    <div class="text-body-1 font-weight-medium text-high-emphasis">
                      Kích hoạt chiến dịch
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      AI chỉ tự động lên bài khi chiến dịch đang chạy
                    </div>
                  </div>
                </div>
              </VCol>
            </VRow>

            <!-- Mục tiêu -->
            <div class="mb-4">
              <div class="text-body-1 font-weight-medium text-high-emphasis mb-2">
                Mục tiêu chiến dịch <span class="text-error">*</span>
              </div>
              <div class="objective-grid">
                <div
                  v-for="obj in objectives"
                  :key="obj.id"
                  class="objective-card pa-3 rounded-lg border text-center cursor-pointer d-flex flex-column align-center justify-center position-relative"
                  :class="{ 'objective-active': form.objective === obj.id }"
                  @click="form.objective = obj.id"
                >
                  <VIcon
                    v-if="form.objective === obj.id"
                    icon="tabler-check"
                    size="14"
                    color="white"
                    class="objective-check-badge"
                  />
                  <VIcon
                    :icon="obj.icon"
                    size="22"
                    :color="form.objective === obj.id ? 'primary' : 'secondary'"
                    class="mb-2"
                  />
                  <div
                    class="text-body-2 font-weight-medium"
                    :class="form.objective === obj.id ? 'text-primary font-weight-bold' : 'text-medium-emphasis'"
                  >
                    {{ obj.label }}
                  </div>
                </div>
              </div>
            </div>

            <!-- Mô tả -->
            <div class="mb-4">
              <AppTextarea
                v-model="form.description"
                label="Mô tả & Mục đích chiến dịch"
                placeholder="Mô tả ngắn gọn mục tiêu, nhóm khách hàng hướng tới và bối cảnh chiến dịch..."
                rows="3"
                maxlength="300"
                counter
                no-resize
              />
            </div>

            <!-- Thời gian + Số ngày + Ngân sách -->
            <VRow
              dense
              class="mb-4"
            >
              <VCol
                cols="12"
                sm="5"
              >
                <AppTextField
                  v-model="form.dateRange"
                  label="Thời gian chạy (starts_at – ends_at)"
                  placeholder="DD/MM/YYYY – DD/MM/YYYY"
                  prepend-inner-icon="tabler-calendar"
                  required
                />
              </VCol>
              <VCol
                cols="12"
                sm="3"
              >
                <AppTextField
                  v-model.number="form.durationDays"
                  type="number"
                  min="1"
                  max="90"
                  label="Thời lượng (ngày)"
                  prepend-inner-icon="tabler-clock"
                />
              </VCol>
              <VCol
                cols="12"
                sm="4"
              >
                <AppTextField
                  v-model="form.budget"
                  label="Ngân sách dự kiến (tùy chọn)"
                  placeholder="0"
                  prepend-inner-icon="tabler-currency-dong"
                />
              </VCol>
            </VRow>

            <!-- Gắn nhãn -->
            <div>
              <AppCombobox
                v-model="form.tags"
                label="Gắn nhãn phân loại (Tags)"
                :items="['Sản phẩm mới', 'Mùa thu', 'Đồ uống', 'Khuyến mãi', 'Hot trend', 'Cà phê']"
                multiple
                chips
                closable-chips
              />
            </div>
          </div>

          <!-- ========================================================= -->
          <!-- BƯỚC 2: NỘI DUNG & VĂN PHONG                              -->
          <!-- ========================================================= -->
          <div v-else-if="currentStep === 2">
            <div class="text-subtitle-1 font-weight-medium text-high-emphasis mb-1">
              Nội dung & Pool Văn phong AI
            </div>
            <div class="text-body-2 text-medium-emphasis mb-4">
              Quy định bộ từ khóa trọng tâm xuyên suốt chiến dịch và chọn Pool 6–10 Văn phong AI để hệ thống tự động xoay vòng không lặp lại.
            </div>

            <!-- Khối 1: Từ khóa & Thông điệp chiến dịch -->
            <div class="border rounded-lg pa-4 mb-5">
              <div class="text-body-1 font-weight-medium text-high-emphasis mb-3">
                1. Bộ từ khóa & Thông điệp cốt lõi của Chiến dịch
              </div>

              <VRow dense>
                <VCol
                  cols="12"
                  md="7"
                  class="mb-3"
                >
                  <AppCombobox
                    v-model="form.keywords"
                    label="Bộ từ khóa trọng tâm (Campaign Keywords)"
                    placeholder="Nhập từ khóa và nhấn Enter..."
                    :items="[
                      'Đồ uống mùa thu',
                      'Pumpkin Spice Latte',
                      'Trà Ô Long Nướng Kem Phô Mai',
                      'Cà phê rang mộc',
                      'Không gian ấm cúng',
                      'Ưu đãi tháng 10',
                      'Combo hẹn hò',
                    ]"
                    multiple
                    chips
                    closable-chips
                  />
                  <div class="text-caption text-medium-emphasis mt-1">
                    WeeklyContentKeyPlannerService sẽ rải đều các từ khóa này theo 3 giai đoạn: Khơi gợi (25%) → Thuyết phục (50%) → Chốt đơn (25%).
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  md="5"
                  class="mb-3"
                >
                  <AppCombobox
                    v-model="form.hashtags"
                    label="Hashtag xuyên suốt chiến dịch"
                    placeholder="Nhập hashtag..."
                    :items="['#CaPheMoc', '#BSTDoUongMuaThu', '#AutumnVibes', '#CoffeeLover', '#MenuMoi']"
                    multiple
                    chips
                    closable-chips
                  />
                </VCol>

                <VCol cols="12">
                  <AppTextarea
                    v-model="form.contentInstructions"
                    label="Thông điệp cốt lõi & Hướng dẫn viết bài cho AI"
                    placeholder="Điểm nổi bật của sản phẩm (USP), thông tin ưu đãi, cách xưng hô hoặc lưu ý cần tránh..."
                    rows="2"
                    maxlength="500"
                    counter
                    no-resize
                  />
                </VCol>
              </VRow>
            </div>

            <!-- Khối 2: Lưới chọn Pool Văn phong AI -->
            <div>
              <div class="d-flex justify-space-between align-center flex-wrap gap-2 mb-3">
                <div class="d-flex align-center flex-wrap gap-2">
                  <span class="text-body-1 font-weight-medium text-high-emphasis">
                    2. Chọn Pool Văn phong AI cho Chiến dịch
                  </span>
                  <VChip
                    size="small"
                    :color="form.writingProfileIds.length >= 6 ? 'primary' : 'warning'"
                    variant="tonal"
                    class="font-weight-medium"
                  >
                    Đã chọn {{ form.writingProfileIds.length }}/{{ writingProfiles.length }} mẫu (Khuyến nghị 6–10 mẫu)
                  </VChip>
                </div>

                <div class="d-flex align-center gap-2">
                  <VBtn
                    variant="tonal"
                    color="primary"
                    size="x-small"
                    prepend-icon="tabler-sparkles"
                    class="text-none"
                    @click="selectRecommendedTones"
                  >
                    Chọn gợi ý (8 mẫu)
                  </VBtn>
                  <VBtn
                    variant="text"
                    color="secondary"
                    size="x-small"
                    class="text-none"
                    @click="clearWritingProfiles"
                  >
                    Bỏ chọn
                  </VBtn>
                </div>
              </div>

              <!-- Thanh lọc trụ cột & tìm kiếm văn phong -->
              <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-3">
                <div class="d-flex flex-wrap gap-1">
                  <VBtn
                    v-for="pf in pillarFilters"
                    :key="pf.value"
                    size="x-small"
                    :variant="selectedPillarFilter === pf.value ? 'flat' : 'tonal'"
                    :color="selectedPillarFilter === pf.value ? 'primary' : 'secondary'"
                    class="text-none"
                    @click="selectedPillarFilter = pf.value"
                  >
                    {{ pf.title }}
                  </VBtn>
                </div>

                <AppTextField
                  v-model="toneSearchQuery"
                  placeholder="Tìm văn phong..."
                  prepend-inner-icon="tabler-search"
                  density="compact"
                  hide-details
                  style="min-inline-size: 200px;"
                />
              </div>

              <!-- Lưới thẻ Văn phong AI -->
              <VRow dense>
                <VCol
                  v-for="tone in filteredWritingProfiles"
                  :key="tone.id"
                  cols="12"
                  sm="6"
                  md="4"
                  lg="3"
                >
                  <div
                    class="pool-card pa-3 rounded-lg border h-100 cursor-pointer d-flex flex-column justify-space-between"
                    :class="{ 'pool-card-selected': form.writingProfileIds.includes(tone.id) }"
                    @click="toggleWritingProfile(tone.id)"
                  >
                    <div>
                      <div class="d-flex justify-space-between align-start mb-2">
                        <VAvatar
                          :color="tone.bg"
                          size="36"
                          rounded="lg"
                        >
                          <span class="text-body-1">{{ tone.emoji }}</span>
                        </VAvatar>

                        <VIcon
                          size="20"
                          :color="form.writingProfileIds.includes(tone.id) ? 'primary' : 'secondary'"
                          :icon="form.writingProfileIds.includes(tone.id) ? 'tabler-circle-check-filled' : 'tabler-circle'"
                        />
                      </div>

                      <div class="text-body-2 font-weight-bold text-high-emphasis mb-1">
                        {{ tone.name }}
                      </div>

                      <div class="text-caption text-medium-emphasis mb-2 line-clamp-2">
                        {{ tone.shortDesc }}
                      </div>
                    </div>

                    <div class="d-flex align-center justify-space-between flex-wrap gap-1 pt-2 border-t">
                      <VChip
                        size="x-small"
                        color="primary"
                        variant="tonal"
                      >
                        {{ tone.pillarLabel }}
                      </VChip>

                      <span class="text-caption text-medium-emphasis">
                        {{ tone.tags[0] }}
                      </span>
                    </div>
                  </div>
                </VCol>
              </VRow>
            </div>
          </div>

          <!-- ========================================================= -->
          <!-- BƯỚC 3: PHONG CÁCH HÌNH ẢNH & ẢNH THAM CHIẾU CHỦ THỂ      -->
          <!-- ========================================================= -->
          <div v-else-if="currentStep === 3">
            <div class="text-subtitle-1 font-weight-medium text-high-emphasis mb-1">
              Ảnh tham chiếu chủ thể thật & Pool Visual Styles
            </div>
            <div class="text-body-2 text-medium-emphasis mb-4">
              Kết hợp Ảnh tham chiếu kép (Dual Reference Images): Khóa cứng nhận diện sản phẩm thật qua 4–6 ảnh gốc và biến hóa ánh sáng/bối cảnh với Pool Visual Styles đa góc chụp.
            </div>

            <!-- Khối 1: 4–6 Ảnh tham chiếu chủ thể thật + Mô tả nhận diện -->
            <div class="border rounded-lg pa-4 mb-5">
              <div class="d-flex justify-space-between align-center flex-wrap gap-2 mb-2">
                <div class="d-flex align-center flex-wrap gap-2">
                  <span class="text-body-1 font-weight-medium text-high-emphasis">
                    1. Ảnh tham chiếu chủ thể thật (Subject Reference)
                  </span>
                  <VChip
                    size="small"
                    :color="form.subjectMedia.length >= 4 ? 'success' : 'warning'"
                    variant="tonal"
                    class="font-weight-medium"
                  >
                    Đã chọn {{ form.subjectMedia.length }}/6 ảnh (Khuyến nghị 4–6 ảnh thật)
                  </VChip>
                </div>

                <div class="d-flex align-center gap-2">
                  <VBtn
                    variant="tonal"
                    color="primary"
                    size="small"
                    prepend-icon="tabler-photo-plus"
                    class="text-none"
                    :disabled="form.subjectMedia.length >= 6"
                    @click="isMediaPickerOpen = true"
                  >
                    Chọn từ Thư viện Media
                  </VBtn>
                  <VBtn
                    variant="outlined"
                    color="secondary"
                    size="small"
                    prepend-icon="tabler-plus"
                    class="text-none"
                    :disabled="form.subjectMedia.length >= 6"
                    @click="addSampleSubjectImage"
                  >
                    Thêm ảnh mẫu
                  </VBtn>
                </div>
              </div>

              <div class="text-body-2 text-medium-emphasis mb-3">
                AI sẽ gửi kèm 1–3 ảnh chụp thật từ danh sách này trong mỗi lần vẽ ảnh để giữ đúng hình dáng ly/chai/bao bì, logo và màu sắc thật của sản phẩm.
              </div>

              <!-- Lưới 6 ô ảnh chủ thể thật -->
              <div class="subject-media-grid mb-4">
                <div
                  v-for="item in form.subjectMedia"
                  :key="item.id"
                  class="subject-media-item border rounded-lg overflow-hidden position-relative"
                >
                  <VImg
                    :src="item.url"
                    height="104"
                    cover
                    class="bg-grey-lighten-3"
                  />
                  <VBtn
                    icon="tabler-x"
                    size="20"
                    color="error"
                    variant="flat"
                    class="subject-remove-btn"
                    aria-label="Xóa ảnh tham chiếu"
                    @click="removeSubjectImage(item.id)"
                  />
                  <div class="pa-2">
                    <div class="text-caption font-weight-bold text-high-emphasis text-truncate">
                      {{ item.title }}
                    </div>
                    <div class="text-caption text-medium-emphasis text-truncate">
                      {{ item.angleLabel }}
                    </div>
                  </div>
                </div>

                <!-- Ô thêm ảnh khi chưa đủ 6 ảnh -->
                <div
                  v-if="form.subjectMedia.length < 6"
                  class="subject-add-box rounded-lg border-dashed d-flex flex-column align-center justify-center pa-3 cursor-pointer text-center"
                  @click="isMediaPickerOpen = true"
                >
                  <VIcon
                    icon="tabler-camera-plus"
                    color="primary"
                    size="24"
                    class="mb-1"
                  />
                  <span class="text-caption font-weight-medium text-primary">
                    + Thêm ảnh thật ({{ 6 - form.subjectMedia.length }} ô trống)
                  </span>
                </div>
              </div>

              <!-- Mô tả nhận diện chủ thể thật -->
              <AppTextarea
                v-model="form.subjectDescription"
                label="Mô tả nhận diện chủ thể thật (ProductIdentityLayer — khóa cứng đặc điểm)"
                placeholder="Mô tả chi tiết hình dáng, chất liệu bao bì, logo, màu sắc đặc trưng của sản phẩm để AI giữ nguyên qua các góc chụp..."
                rows="2"
                maxlength="300"
                counter
                no-resize
              />
            </div>

            <!-- Khối 2: Số lượng ảnh/bài & Phân bổ 3–5 góc máy -->
            <div class="border rounded-lg pa-4 mb-5">
              <div class="d-flex justify-space-between align-center flex-wrap gap-3 mb-3">
                <div>
                  <div class="text-body-1 font-weight-medium text-high-emphasis">
                    2. Số lượng ảnh mỗi bài & Công thức Đa góc chụp (Shot Recipes)
                  </div>
                  <div class="text-body-2 text-medium-emphasis">
                    Mỗi bài đăng sẽ tự động sinh trọn bộ album từ 3 đến 5 bức ảnh ở các góc máy khác nhau trên cùng chủ thể thật.
                  </div>
                </div>

                <div class="segmented-mode-group border rounded-lg pa-1 d-inline-flex flex-wrap gap-1 bg-var-theme-background">
                  <VBtn
                    v-for="cnt in [3, 4, 5]"
                    :key="cnt"
                    size="small"
                    :variant="form.imageCount === cnt ? 'flat' : 'text'"
                    :color="form.imageCount === cnt ? 'primary' : 'secondary'"
                    class="text-none font-weight-medium rounded"
                    @click="form.imageCount = cnt"
                  >
                    {{ cnt }} ảnh/bài {{ cnt === 4 ? '(Chuẩn)' : '' }}
                  </VBtn>
                </div>
              </div>

              <!-- Hiển thị trực quan 3–5 góc máy -->
              <VRow dense>
                <VCol
                  v-for="shot in activeShotPresets"
                  :key="shot.slot"
                  cols="12"
                  sm="6"
                  md="4"
                  lg=""
                >
                  <div class="pa-3 rounded-lg border h-100 bg-var-theme-background">
                    <div class="d-flex align-center gap-2 mb-1">
                      <VAvatar
                        size="24"
                        color="primary"
                        variant="tonal"
                        rounded
                      >
                        <VIcon
                          :icon="shot.icon"
                          size="14"
                        />
                      </VAvatar>
                      <span class="text-caption font-weight-bold text-primary">
                        Ảnh {{ shot.slot }}
                      </span>
                    </div>
                    <div class="text-body-2 font-weight-medium text-high-emphasis mb-1">
                      {{ shot.name }}
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      {{ shot.desc }}
                    </div>
                  </div>
                </VCol>
              </VRow>
            </div>

            <!-- Khối 3: Lưới chọn Pool Visual Styles -->
            <div>
              <div class="d-flex justify-space-between align-center flex-wrap gap-2 mb-3">
                <div class="d-flex align-center flex-wrap gap-2">
                  <span class="text-body-1 font-weight-medium text-high-emphasis">
                    3. Chọn Pool Phong cách hình ảnh (Visual Styles)
                  </span>
                  <VChip
                    size="small"
                    :color="form.imageProfileIds.length >= 6 ? 'primary' : 'warning'"
                    variant="tonal"
                    class="font-weight-medium"
                  >
                    Đã chọn {{ form.imageProfileIds.length }}/{{ visualStyles.length }} style (Khuyến nghị 6–10 mẫu)
                  </VChip>
                </div>

                <div class="d-flex align-center flex-wrap gap-2">
                  <AppTextField
                    v-model="styleSearchQuery"
                    placeholder="Tìm kiếm style..."
                    prepend-inner-icon="tabler-search"
                    density="compact"
                    hide-details
                    style="min-inline-size: 180px;"
                  />
                  <AppSelect
                    v-model="selectedStyleCategory"
                    :items="['Tất cả danh mục', 'Sản phẩm', 'Đồ ăn', 'Hiện đại', 'Cổ điển', 'Nghệ thuật', 'Nhân vật']"
                    density="compact"
                    hide-details
                    style="min-inline-size: 150px;"
                  />
                  <VBtn
                    variant="tonal"
                    color="primary"
                    size="x-small"
                    prepend-icon="tabler-sparkles"
                    class="text-none"
                    @click="selectRecommendedStyles"
                  >
                    Chọn gợi ý
                  </VBtn>
                  <VBtn
                    variant="text"
                    color="secondary"
                    size="x-small"
                    class="text-none"
                    @click="clearVisualStyles"
                  >
                    Bỏ chọn
                  </VBtn>
                </div>
              </div>

              <VRow dense>
                <VCol
                  v-for="style in filteredVisualStyles"
                  :key="style.id"
                  cols="12"
                  sm="6"
                  md="3"
                >
                  <div
                    class="pool-card pa-3 rounded-lg border h-100 d-flex flex-column justify-space-between cursor-pointer"
                    :class="{ 'pool-card-selected': form.imageProfileIds.includes(style.id) }"
                    @click="toggleVisualStyle(style.id)"
                  >
                    <div>
                      <div class="position-relative rounded overflow-hidden mb-2">
                        <VImg
                          :src="style.image"
                          height="88"
                          cover
                          class="bg-grey-lighten-3"
                        />
                        <VAvatar
                          v-if="form.imageProfileIds.includes(style.id)"
                          color="primary"
                          size="22"
                          class="style-check-badge"
                        >
                          <VIcon
                            icon="tabler-check"
                            color="white"
                            size="14"
                          />
                        </VAvatar>
                      </div>

                      <div class="text-body-2 font-weight-bold text-high-emphasis text-truncate mb-1">
                        {{ style.name }}
                      </div>
                      <div class="text-caption text-medium-emphasis line-clamp-2 mb-2">
                        {{ style.shortDesc }}
                      </div>
                    </div>

                    <div class="d-flex flex-wrap gap-1 pt-2 border-t">
                      <VChip
                        v-for="tag in style.tags"
                        :key="tag"
                        size="x-small"
                        color="primary"
                        variant="tonal"
                      >
                        {{ tag }}
                      </VChip>
                    </div>
                  </div>
                </VCol>
              </VRow>
            </div>
          </div>

          <!-- ========================================================= -->
          <!-- BƯỚC 4: NỀN TẢNG & LỊCH ĐĂNG                              -->
          <!-- ========================================================= -->
          <div v-else-if="currentStep === 4">
            <div class="text-subtitle-1 font-weight-medium text-high-emphasis mb-1">
              Nền tảng & Lịch đăng theo Mạch nội dung
            </div>
            <div class="text-body-2 text-medium-emphasis mb-4">
              Chọn các kênh mạng xã hội áp dụng, quy định tần suất các Thứ đăng bài trong tuần và khung giờ vàng tự động xuất bản.
            </div>

            <!-- Khối 1: Chọn nền tảng -->
            <div class="mb-5">
              <div class="text-body-1 font-weight-medium text-high-emphasis mb-2">
                1. Nền tảng đăng bài áp dụng
              </div>
              <VRow dense>
                <VCol
                  v-for="pf in platformOptions"
                  :key="pf.id"
                  cols="12"
                  sm="6"
                  md="3"
                >
                  <div
                    class="pool-card pa-3 rounded-lg border h-100 cursor-pointer d-flex flex-column justify-space-between"
                    :class="{ 'pool-card-selected': form.platforms.includes(pf.id) }"
                    @click="togglePlatform(pf.id)"
                  >
                    <div>
                      <div class="d-flex justify-space-between align-center mb-2">
                        <VAvatar
                          :color="pf.color"
                          size="34"
                          rounded="lg"
                        >
                          <VIcon
                            :icon="pf.icon"
                            color="white"
                            size="18"
                          />
                        </VAvatar>

                        <VIcon
                          size="20"
                          :color="form.platforms.includes(pf.id) ? 'primary' : 'secondary'"
                          :icon="form.platforms.includes(pf.id) ? 'tabler-circle-check-filled' : 'tabler-circle'"
                        />
                      </div>

                      <div class="text-body-2 font-weight-bold text-high-emphasis mb-1">
                        {{ pf.name }}
                      </div>
                      <div class="text-caption text-medium-emphasis mb-2">
                        {{ pf.desc }}
                      </div>
                    </div>

                    <div class="pt-2 border-t d-flex align-center justify-space-between">
                      <span class="text-caption text-medium-emphasis">Giới hạn an toàn:</span>
                      <VChip
                        size="x-small"
                        color="secondary"
                        variant="tonal"
                      >
                        {{ pf.safetyCap }}
                      </VChip>
                    </div>
                  </div>
                </VCol>
              </VRow>
            </div>

            <!-- Khối 2: Chế độ phân bổ lịch đăng & Khung giờ vàng -->
            <div class="border rounded-lg pa-4 mb-5">
              <div class="d-flex justify-space-between align-center flex-wrap gap-3 mb-4">
                <div>
                  <div class="text-body-1 font-weight-medium text-high-emphasis">
                    2. Tần suất & Khung giờ vàng đăng bài
                  </div>
                  <div class="text-body-2 text-medium-emphasis">
                    Chọn lịch đăng theo nhịp ngày trong tuần hoặc cố định số bài mỗi ngày.
                  </div>
                </div>

                <div class="segmented-mode-group border rounded-lg pa-1 d-inline-flex flex-wrap gap-1 bg-var-theme-background">
                  <VBtn
                    size="small"
                    :variant="form.scheduleMode === 'weekdays' ? 'flat' : 'text'"
                    :color="form.scheduleMode === 'weekdays' ? 'primary' : 'secondary'"
                    class="text-none font-weight-medium rounded"
                    prepend-icon="tabler-calendar-week"
                    @click="form.scheduleMode = 'weekdays'"
                  >
                    Theo các Thứ trong tuần (Chuẩn)
                  </VBtn>
                  <VBtn
                    size="small"
                    :variant="form.scheduleMode === 'daily' ? 'flat' : 'text'"
                    :color="form.scheduleMode === 'daily' ? 'primary' : 'secondary'"
                    class="text-none font-weight-medium rounded"
                    prepend-icon="tabler-calendar-event"
                    @click="form.scheduleMode = 'daily'"
                  >
                    Số bài/ngày cố định
                  </VBtn>
                </div>
              </div>

              <!-- Chế độ 1: Theo các Thứ trong tuần -->
              <div
                v-if="form.scheduleMode === 'weekdays'"
                class="d-flex flex-column gap-2"
              >
                <div
                  v-for="day in form.weeklySchedule"
                  :key="day.key"
                  class="d-flex align-center justify-space-between flex-wrap gap-2 pa-2 rounded border"
                  :class="{ 'weekday-row-active': day.enabled }"
                >
                  <div class="d-flex align-center gap-2 flex-wrap">
                    <div
                      class="d-flex align-center"
                      style="min-inline-size: 105px;"
                    >
                      <VCheckboxBtn
                        v-model="day.enabled"
                        color="primary"
                        density="compact"
                        class="me-1"
                      />
                      <span
                        class="text-body-2 font-weight-bold"
                        :class="day.enabled ? 'text-high-emphasis' : 'text-medium-emphasis'"
                      >
                        {{ day.name }}
                      </span>
                    </div>

                    <VChip
                      size="x-small"
                      :color="day.enabled ? day.pillarColor : 'secondary'"
                      variant="tonal"
                      class="font-weight-medium"
                    >
                      {{ day.pillarLabel }}
                    </VChip>

                    <span class="text-caption text-medium-emphasis d-none d-md-inline">
                      — {{ day.angleHint }}
                    </span>
                  </div>

                  <div class="d-flex align-center flex-wrap gap-1">
                    <VChip
                      v-for="(slot, sIdx) in day.slots"
                      :key="sIdx"
                      size="small"
                      :color="day.enabled ? 'primary' : 'secondary'"
                      variant="tonal"
                      closable
                      class="font-weight-medium"
                      @click:close="removeWeekdaySlot(day, sIdx)"
                    >
                      <VIcon
                        icon="tabler-clock"
                        size="13"
                        class="me-1"
                      />
                      {{ slot }}
                    </VChip>

                    <VBtn
                      icon="tabler-plus"
                      size="24"
                      variant="tonal"
                      color="secondary"
                      class="rounded"
                      aria-label="Thêm khung giờ"
                      @click="addWeekdaySlot(day)"
                    />
                  </div>
                </div>
              </div>

              <!-- Chế độ 2: Số bài cố định mỗi ngày -->
              <div
                v-else
                class="pa-3 rounded border"
              >
                <VRow
                  dense
                  align="center"
                >
                  <VCol
                    cols="12"
                    md="5"
                  >
                    <div class="text-body-2 font-weight-medium text-high-emphasis mb-1">
                      Số lượng bài đăng mỗi ngày
                    </div>
                    <div class="d-flex align-center gap-3">
                      <div class="d-inline-flex align-center border rounded pa-1">
                        <VBtn
                          icon="tabler-minus"
                          variant="text"
                          size="x-small"
                          color="secondary"
                          :disabled="form.dailyPostCount <= 1"
                          @click="form.dailyPostCount > 1 && form.dailyPostCount--"
                        />
                        <span class="px-4 text-body-1 font-weight-bold text-high-emphasis">
                          {{ form.dailyPostCount }}
                        </span>
                        <VBtn
                          icon="tabler-plus"
                          variant="text"
                          size="x-small"
                          color="secondary"
                          :disabled="form.dailyPostCount >= 5"
                          @click="form.dailyPostCount < 5 && form.dailyPostCount++"
                        />
                      </div>
                      <span class="text-body-2 text-medium-emphasis">bài / ngày (tất cả các ngày trong chiến dịch)</span>
                    </div>
                  </VCol>

                  <VCol
                    cols="12"
                    md="7"
                  >
                    <div class="text-body-2 font-weight-medium text-high-emphasis mb-1">
                      Khung giờ vàng mặc định mỗi ngày
                    </div>
                    <div class="d-flex align-center flex-wrap gap-2">
                      <VChip
                        v-for="(slot, idx) in form.dailyTimeSlots"
                        :key="idx"
                        size="small"
                        color="primary"
                        variant="tonal"
                        closable
                        class="font-weight-medium"
                        @click:close="removeDailySlot(idx)"
                      >
                        <VIcon
                          icon="tabler-clock"
                          size="14"
                          class="me-1"
                        />
                        {{ slot }}
                      </VChip>
                      <VBtn
                        variant="outlined"
                        color="secondary"
                        size="small"
                        prepend-icon="tabler-plus"
                        class="text-none border-dashed"
                        @click="addDailySlot"
                      >
                        Thêm khung giờ
                      </VBtn>
                    </div>
                  </VCol>
                </VRow>
              </div>
            </div>

            <!-- Khối 3: Tóm tắt tự động & Phân bổ Mạch nội dung 3 giai đoạn (Story Arc) -->
            <div class="border rounded-lg pa-4 bg-var-theme-background">
              <div class="d-flex align-center gap-2 mb-2">
                <VIcon
                  icon="tabler-calendar-stats"
                  color="primary"
                  size="20"
                />
                <span class="text-body-1 font-weight-bold text-high-emphasis">
                  Tóm tắt Lịch trình & Phân bổ Mạch nội dung Chiến dịch (Story Arc)
                </span>
              </div>

              <div class="text-body-2 text-high-emphasis mb-3">
                Chiến dịch kéo dài <strong>{{ form.durationDays }} ngày</strong> ({{ form.dateRange }}) ×
                <strong>{{ activeDaysPerWeek }} ngày đăng/tuần</strong> =
                Dự kiến AI sẽ sản xuất tổng cộng <strong>{{ estimatedTotalPosts }} bài viết</strong>
                (kèm <strong>{{ estimatedTotalImages }} bức ảnh</strong> ở {{ form.imageCount }} góc máy/bài) vào mỗi <strong>07:00 sáng Chủ Nhật</strong>.
              </div>

              <VRow dense>
                <VCol
                  cols="12"
                  md="4"
                >
                  <div class="pa-3 rounded border bg-surface h-100">
                    <div class="d-flex justify-space-between align-center mb-1">
                      <span class="text-caption font-weight-bold text-info">Giai đoạn 1 (25% đầu)</span>
                      <VChip
                        size="x-small"
                        color="info"
                        variant="tonal"
                      >
                        Khơi gợi & Nhận diện
                      </VChip>
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      Ưu tiên góc tiếp cận <strong>Inspire</strong> & <strong>Story</strong>: Tạo sự tò mò, cảm hứng mùa mới và giới thiệu câu chuyện mở màn.
                    </div>
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  md="4"
                >
                  <div class="pa-3 rounded border bg-surface h-100">
                    <div class="d-flex justify-space-between align-center mb-1">
                      <span class="text-caption font-weight-bold text-primary">Giai đoạn 2 (50% giữa)</span>
                      <VChip
                        size="x-small"
                        color="primary"
                        variant="tonal"
                      >
                        Giá trị cốt lõi
                      </VChip>
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      Đan xen <strong>Knowledge</strong>, <strong>Story</strong> & <strong>Sales</strong>: Đi sâu vào nguyên liệu, chất lượng thật và trải nghiệm khách hàng.
                    </div>
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  md="4"
                >
                  <div class="pa-3 rounded border bg-surface h-100">
                    <div class="d-flex justify-space-between align-center mb-1">
                      <span class="text-caption font-weight-bold text-success">Giai đoạn 3 (25% cuối)</span>
                      <VChip
                        size="x-small"
                        color="success"
                        variant="tonal"
                      >
                        Thúc đẩy chốt đơn
                      </VChip>
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      Tập trung mạnh vào <strong>Sales</strong> & <strong>Engagement</strong>: Tung ưu đãi giới hạn, minigame tương tác và kêu gọi hành động.
                    </div>
                  </div>
                </VCol>
              </VRow>
            </div>
          </div>

          <!-- ========================================================= -->
          <!-- BƯỚC 5: NÂNG CAO & TỔNG KIỂM TRA                          -->
          <!-- ========================================================= -->
          <div v-else-if="currentStep === 5">
            <div class="text-subtitle-1 font-weight-medium text-high-emphasis mb-1">
              Quy tắc Xáo trộn Pool, Chống lặp & Duyệt bài tự động
            </div>
            <div class="text-body-2 text-medium-emphasis mb-4">
              Cấu hình thuật toán ghép cặp 4 tầng trong Pool của Chiến dịch và cơ chế cuốn chiếu soạn bài sáng Chủ Nhật.
            </div>

            <!-- Khối 1: Chế độ xáo trộn & ghép cặp Pool -->
            <div class="mb-5">
              <div class="text-body-1 font-weight-medium text-high-emphasis mb-2">
                1. Chế độ Xáo trộn & Ghép cặp trong Pool Chiến dịch (SocialSchedulePairingService)
              </div>

              <VRow dense>
                <VCol
                  v-for="mode in pairingModes"
                  :key="mode.id"
                  cols="12"
                  md="4"
                >
                  <div
                    class="pool-card pa-3 rounded-lg border h-100 cursor-pointer d-flex flex-column justify-space-between"
                    :class="{ 'pool-card-selected': form.pairingMode === mode.id }"
                    @click="form.pairingMode = mode.id"
                  >
                    <div>
                      <div class="d-flex justify-space-between align-center mb-2">
                        <VAvatar
                          size="34"
                          color="primary"
                          variant="tonal"
                          rounded="lg"
                        >
                          <VIcon
                            :icon="mode.icon"
                            size="18"
                          />
                        </VAvatar>

                        <VChip
                          size="x-small"
                          :color="form.pairingMode === mode.id ? 'primary' : 'secondary'"
                          variant="tonal"
                        >
                          {{ mode.badge }}
                        </VChip>
                      </div>

                      <div class="text-body-2 font-weight-bold text-high-emphasis mb-1">
                        {{ mode.title }}
                      </div>
                      <div class="text-caption text-medium-emphasis">
                        {{ mode.desc }}
                      </div>
                    </div>
                  </div>
                </VCol>
              </VRow>
            </div>

            <!-- Khối 2: Quy tắc chống lặp & Cooldown + Tự động hóa Chủ Nhật -->
            <VRow
              dense
              class="mb-4"
            >
              <!-- Cột trái: Quy tắc chống lặp -->
              <VCol
                cols="12"
                md="6"
              >
                <div class="border rounded-lg pa-4 h-100">
                  <div class="text-body-1 font-weight-medium text-high-emphasis mb-3">
                    2. Quy tắc chống lặp & Cooldown trong Chiến dịch
                  </div>

                  <div class="d-flex align-center justify-space-between mb-3">
                    <div class="pe-2">
                      <div class="text-body-2 font-weight-medium text-high-emphasis">
                        Cấm trùng Văn phong & Style ảnh trong cùng 1 tuần
                      </div>
                      <div class="text-caption text-medium-emphasis">
                        Đảm bảo mỗi ngày đăng trong tuần có một giọng văn và màu sắc thị giác riêng
                      </div>
                    </div>
                    <VSwitch
                      v-model="form.strictWeeklyNoRepeat"
                      color="primary"
                      density="compact"
                      hide-details
                      inset
                    />
                  </div>

                  <div class="d-flex align-center justify-space-between mb-4">
                    <div class="pe-2">
                      <div class="text-body-2 font-weight-medium text-high-emphasis">
                        Xoay vòng đều toàn bộ Ảnh tham chiếu chủ thể
                      </div>
                      <div class="text-caption text-medium-emphasis">
                        Dùng hết 4–6 ảnh tham chiếu thật trước khi lặp lại ảnh cũ
                      </div>
                    </div>
                    <VSwitch
                      v-model="form.rotateAllSubjectMedia"
                      color="primary"
                      density="compact"
                      hide-details
                      inset
                    />
                  </div>

                  <VRow dense>
                    <VCol
                      cols="12"
                      sm="6"
                    >
                      <AppSelect
                        v-model="form.toneCooldownDays"
                        label="Cooldown Văn phong AI"
                        :items="[
                          { title: 'Cách tối thiểu 3 ngày', value: 3 },
                          { title: 'Cách tối thiểu 5 ngày', value: 5 },
                          { title: 'Cách tối thiểu 7 ngày', value: 7 },
                        ]"
                      />
                    </VCol>
                    <VCol
                      cols="12"
                      sm="6"
                    >
                      <AppSelect
                        v-model="form.styleCooldownDays"
                        label="Cooldown Visual Style"
                        :items="[
                          { title: 'Cách tối thiểu 3 ngày', value: 3 },
                          { title: 'Cách tối thiểu 5 ngày', value: 5 },
                          { title: 'Cách tối thiểu 7 ngày', value: 7 },
                        ]"
                      />
                    </VCol>
                    <VCol
                      cols="12"
                      class="mt-2"
                    >
                      <AppSelect
                        v-model="form.priorityLevel"
                        label="Độ ưu tiên khi trùng ngày với chiến dịch khác"
                        :items="[
                          { title: 'Cao — Ưu tiên giữ khung giờ vàng chính', value: 'high' },
                          { title: 'Bình thường — Đan xen cân bằng', value: 'normal' },
                          { title: 'Thấp — Lấp vào khung giờ trống', value: 'low' },
                        ]"
                      />
                    </VCol>
                  </VRow>
                </div>
              </VCol>

              <!-- Cột phải: Cơ chế Cuốn chiếu Sáng Chủ Nhật & Duyệt bài -->
              <VCol
                cols="12"
                md="6"
              >
                <div class="border rounded-lg pa-4 h-100">
                  <div class="text-body-1 font-weight-medium text-high-emphasis mb-3">
                    3. Cơ chế Cuốn chiếu Sáng Chủ Nhật & Quy trình Duyệt
                  </div>

                  <div class="d-flex align-center justify-space-between mb-3">
                    <div class="pe-2">
                      <div class="text-body-2 font-weight-medium text-high-emphasis">
                        Tự động chạy Batch vào 07:00 sáng Chủ Nhật hàng tuần
                      </div>
                      <div class="text-caption text-medium-emphasis">
                        AI tự động soạn sẵn trọn bộ Caption + 3–5 ảnh cho cả tuần tới
                      </div>
                    </div>
                    <VSwitch
                      v-model="form.sundayBatchEnabled"
                      color="primary"
                      density="compact"
                      hide-details
                      inset
                    />
                  </div>

                  <div class="d-flex align-center justify-space-between mb-3">
                    <div class="pe-2">
                      <div class="text-body-2 font-weight-medium text-high-emphasis">
                        Tạo ngay các bài cho những ngày còn lại của tuần hiện tại
                      </div>
                      <div class="text-caption text-medium-emphasis">
                        Khởi chạy ngay các slot bài trong tuần đầu tiên khi kích hoạt chiến dịch
                      </div>
                    </div>
                    <VSwitch
                      v-model="form.generateCurrentWeekImmediately"
                      color="primary"
                      density="compact"
                      hide-details
                      inset
                    />
                  </div>

                  <div class="d-flex align-center justify-space-between mb-3">
                    <div class="pe-2">
                      <div class="text-body-2 font-weight-medium text-high-emphasis">
                        Tự động chuyển sang "Chờ duyệt" sau khi AI tạo xong
                      </div>
                      <div class="text-caption text-medium-emphasis">
                        Đưa toàn bộ bài tuần tới vào tab Lịch đăng để Admin duyệt 1 lượt
                      </div>
                    </div>
                    <VSwitch
                      v-model="form.autoSubmitReview"
                      color="primary"
                      density="compact"
                      hide-details
                      inset
                    />
                  </div>

                  <div class="d-flex align-center justify-space-between mb-3">
                    <div class="pe-2">
                      <div class="text-body-2 font-weight-medium text-high-emphasis">
                        Tự động đăng đúng giờ hẹn sau khi Admin duyệt
                      </div>
                      <div class="text-caption text-medium-emphasis">
                        Cronjob tự động đẩy bài đã Approved lên Fanpage từ Thứ 2 → Chủ Nhật
                      </div>
                    </div>
                    <VSwitch
                      v-model="form.autoPublishAfterApprove"
                      color="primary"
                      density="compact"
                      hide-details
                      inset
                    />
                  </div>

                  <div class="d-flex align-center justify-space-between">
                    <div class="pe-2">
                      <div class="text-body-2 font-weight-medium text-high-emphasis">
                        Tạo 2 phương án Caption (A/B Testing)
                      </div>
                      <div class="text-caption text-medium-emphasis">
                        Cho phép Admin chọn giữa 2 phiên bản nội dung khi duyệt bài
                      </div>
                    </div>
                    <VSwitch
                      v-model="form.abTesting"
                      color="primary"
                      density="compact"
                      hide-details
                      inset
                    />
                  </div>
                </div>
              </VCol>
            </VRow>

            <!-- Khối 3: Bảng tổng kiểm tra 4 Trụ cột trước khi Kích hoạt -->
            <div class="border rounded-lg pa-4 bg-var-theme-background">
              <div class="text-body-1 font-weight-bold text-high-emphasis mb-2">
                Tổng kiểm tra 4 Mảnh ghép của Chiến dịch "{{ form.name }}"
              </div>
              <VRow dense>
                <VCol
                  cols="12"
                  sm="6"
                  md="3"
                >
                  <div class="pa-3 rounded border bg-surface h-100">
                    <div class="text-caption text-medium-emphasis mb-1">
                      1. Mục tiêu & Từ khóa
                    </div>
                    <div class="text-body-2 font-weight-bold text-high-emphasis">
                      {{ selectedObjectiveLabel }}
                    </div>
                    <div class="text-caption text-primary mt-1">
                      {{ form.keywords.length }} từ khóa • {{ form.hashtags.length }} hashtags
                    </div>
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  sm="6"
                  md="3"
                >
                  <div class="pa-3 rounded border bg-surface h-100">
                    <div class="text-caption text-medium-emphasis mb-1">
                      2. Pool Văn phong AI
                    </div>
                    <div class="text-body-2 font-weight-bold text-high-emphasis">
                      {{ form.writingProfileIds.length }} mẫu giọng văn
                    </div>
                    <div class="text-caption text-medium-emphasis mt-1">
                      Cooldown lặp lại: {{ form.toneCooldownDays }} ngày
                    </div>
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  sm="6"
                  md="3"
                >
                  <div class="pa-3 rounded border bg-surface h-100">
                    <div class="text-caption text-medium-emphasis mb-1">
                      3. Ảnh thật & Visual Styles
                    </div>
                    <div class="text-body-2 font-weight-bold text-high-emphasis">
                      {{ form.subjectMedia.length }} ảnh thật × {{ form.imageProfileIds.length }} style
                    </div>
                    <div class="text-caption text-medium-emphasis mt-1">
                      Đa góc chụp: {{ form.imageCount }} ảnh / bài viết
                    </div>
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  sm="6"
                  md="3"
                >
                  <div class="pa-3 rounded border bg-surface h-100">
                    <div class="text-caption text-medium-emphasis mb-1">
                      4. Lịch đăng & Sản lượng
                    </div>
                    <div class="text-body-2 font-weight-bold text-success">
                      {{ estimatedTotalPosts }} bài • {{ estimatedTotalImages }} ảnh AI
                    </div>
                    <div class="text-caption text-medium-emphasis mt-1">
                      {{ form.durationDays }} ngày • Batch CN {{ form.sundayBatchTime }}
                    </div>
                  </div>
                </VCol>
              </VRow>
            </div>
          </div>
        </div>
      </div>

      <!-- ================= FOOTER: ĐIỀU HƯỚNG & LƯU CHIẾN DỊCH ================= -->
      <template #footer>
        <div class="d-flex justify-space-between align-center flex-wrap gap-3 w-100 pa-4">
          <div class="d-flex align-center gap-2">
            <VBtn
              variant="outlined"
              color="secondary"
              class="text-none"
              @click="close"
            >
              Hủy
            </VBtn>
            <span class="text-caption text-medium-emphasis d-none d-sm-inline ms-2">
              Bước {{ currentStep }} / {{ steps.length }} — {{ steps[currentStep - 1]?.title }}
            </span>
          </div>

          <div class="d-flex align-center flex-wrap gap-2">
            <VBtn
              v-if="currentStep > 1"
              variant="outlined"
              color="secondary"
              prepend-icon="tabler-arrow-left"
              class="text-none"
              @click="prevStep"
            >
              Quay lại
            </VBtn>

            <VBtn
              v-if="currentStep < steps.length"
              color="primary"
              append-icon="tabler-arrow-right"
              class="text-none"
              @click="nextStep"
            >
              Tiếp theo
            </VBtn>

            <template v-else>
              <VBtn
                variant="tonal"
                color="secondary"
                prepend-icon="tabler-device-floppy"
                class="text-none"
                :loading="submitting"
                :disabled="submitting"
                @click="submitCampaign('draft')"
              >
                Lưu nháp
              </VBtn>
              <VBtn
                color="primary"
                prepend-icon="tabler-rocket"
                class="text-none"
                :loading="submitting"
                :disabled="submitting"
                @click="submitCampaign('active')"
              >
                {{ form.isActive ? 'Lưu & Kích hoạt Chiến dịch' : 'Lưu Chiến dịch' }}
              </VBtn>
            </template>
          </div>
        </div>
      </template>
    </AppDialogLayout>
  </VDialog>

  <!-- Dialog chọn ảnh tham chiếu chủ thể thật từ Thư viện Media -->
  <MediaLibraryDialog
    v-model:open="isMediaPickerOpen"
    kind="image"
    multiple
    @select="handleMediaSelect"
  />
</template>

<style scoped>
/* Khung 2 cột của Wizard trong AppDialogLayout */
.wizard-layout {
  min-block-size: 0;
  inline-size: 100%;
}

/* Sidebar bước bên trái */
.step-sidebar {
  inline-size: 290px;
  min-inline-size: 290px;
}

.step-item {
  inline-size: 100%;
  min-inline-size: 0;
  border: 1px solid transparent;
  transition: background-color 0.15s ease, border-color 0.15s ease;
}

.step-item:hover {
  background-color: rgba(var(--v-theme-primary), 0.04);
}

.step-active {
  background-color: rgba(var(--v-theme-primary), 0.08) !important;
  border-color: rgba(var(--v-theme-primary), 0.25) !important;
}

.min-inline-size-0 {
  min-inline-size: 0 !important;
}

.step-content {
  min-block-size: 0;
}

/* Lưới 6 mục tiêu ở Bước 1 */
.objective-grid {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 8px;
}

.objective-card {
  min-block-size: 85px;
  transition: all 0.2s ease;
}

.objective-card:hover {
  border-color: rgb(var(--v-theme-primary)) !important;
  background-color: rgba(var(--v-theme-primary), 0.04);
}

.objective-active {
  border: 1.5px solid rgb(var(--v-theme-primary)) !important;
  background-color: rgba(var(--v-theme-primary), 0.08) !important;
  box-shadow: 0 4px 12px rgba(var(--v-theme-primary), 0.12);
}

.objective-check-badge {
  position: absolute;
  inset-block-start: 6px;
  inset-inline-end: 6px;
  background-color: rgb(var(--v-theme-primary));
  border-radius: 50%;
  inline-size: 18px;
  block-size: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
}

/* Thẻ chọn trong Pool (Văn phong, Visual Styles, Nền tảng, Ghép cặp) */
.pool-card {
  transition: border-color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
}

.pool-card:hover {
  border-color: rgb(var(--v-theme-primary)) !important;
  background-color: rgba(var(--v-theme-primary), 0.04);
}

.pool-card-selected {
  border: 1.5px solid rgb(var(--v-theme-primary)) !important;
  background-color: rgba(var(--v-theme-primary), 0.06) !important;
  box-shadow: 0 4px 14px rgba(var(--v-theme-primary), 0.12);
}

.style-check-badge {
  position: absolute;
  inset-block-start: 6px;
  inset-inline-end: 6px;
  z-index: 2;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
}

/* Lưới 6 ô ảnh tham chiếu chủ thể ở Bước 3 */
.subject-media-grid {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 10px;
}

.subject-remove-btn {
  position: absolute;
  inset-block-start: 6px;
  inset-inline-end: 6px;
  z-index: 2;
}

.subject-add-box {
  min-block-size: 152px;
  border: 1.5px dashed rgba(var(--v-theme-primary), 0.45);
  background-color: rgba(var(--v-theme-primary), 0.03);
  transition: border-color 0.15s ease, background-color 0.15s ease;
}

.subject-add-box:hover {
  border-color: rgb(var(--v-theme-primary));
  background-color: rgba(var(--v-theme-primary), 0.08);
}

.border-dashed {
  border-style: dashed !important;
}

.weekday-row-active {
  border-color: rgba(var(--v-theme-primary), 0.35) !important;
  background-color: rgba(var(--v-theme-primary), 0.03);
}

.bg-var-theme-background {
  background-color: rgba(var(--v-theme-on-surface), 0.02);
}

.segmented-mode-group {
  border-color: rgba(var(--v-border-color), var(--v-border-opacity)) !important;
  background-color: rgba(var(--v-theme-on-surface), 0.04);
}

.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

@media (max-width: 959.98px) {
  .wizard-layout {
    flex-direction: column;
    overflow-y: auto;
  }

  .step-sidebar {
    inline-size: 100%;
    border-inline-end: 0 !important;
    border-block-end: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
  }

  .step-content {
    overflow-y: visible !important;
  }

  .objective-grid {
    grid-template-columns: repeat(3, 1fr);
  }

  .subject-media-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

@media (max-width: 599.98px) {
  .objective-grid {
    grid-template-columns: repeat(2, 1fr);
  }

  .subject-media-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>
