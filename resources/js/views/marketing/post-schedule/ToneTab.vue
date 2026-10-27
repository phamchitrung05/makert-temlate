<!--
  =====================================================================
  CHỨC NĂNG FILE: Tab "Văn phong AI" của trang Lịch Đăng Bài (post-schedule).
  =====================================================================

  Panel con render bởi index.vue trong VWindowItem value="tone".
  Cho phép chọn kiểu văn phong viết, chỉnh sửa chi tiết và xem ví dụ mẫu.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - selectTone(tone): chọn văn phong và đổ dữ liệu vào form.
  - selectExample(ex): chọn ví dụ để xem trước trong khung bài đăng.
  - copyText(text): sao chép nội dung ví dụ vào clipboard.
  - resetFormForNew(): xóa form để thêm văn phong mới.
  - updateTone(): cập nhật văn phong (tạm console.log, chưa có API).

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : không có props; state giữ trong component.
  - OUTPUT: UI chọn/chỉnh văn phong; chưa emit/persist vì chưa có API.
  =====================================================================
-->
<script setup>
import { onMounted, reactive, ref } from 'vue'
import { postScheduleService } from '@/services/postSchedule'

const selectedToneId = ref('humorous')
const selectedPlatform = ref('facebook')
const selectedExampleId = ref(1)

/**
 * =====================================================================
 * CHỨC NĂNG: Nạp kho văn phong thật từ API /ai/writing-profiles/options.
 * =====================================================================
 * INPUT : không có; gọi khi mounted.
 * OUTPUT: nếu API có profile, ghép vào toneList (giữ card UI hiện có).
 * SIDE EFFECT: gọi GET options; không ghi DB. Khi rỗng/lỗi giữ dữ liệu mẫu.
 * =====================================================================
 */
const loadProfiles = async () => {
  try {
    const res = await postScheduleService.writingProfiles()
    const items = res?.items ?? res ?? []
    if (! Array.isArray(items) || items.length === 0)
      return

    const mapped = items.map(p => ({
      id: `profile-${p.id}`,
      name: p.name,
      emoji: '🎤',
      bg: '#eef2ff',
      shortDesc: p.description || 'Văn phong AI đã duyệt',
      isDefault: res?.default_writing_profile_id === p.id,
      fullDesc: p.description || '',
      characteristics: [],
      instructions: '',
    }))

    toneList.value = [...toneList.value, ...mapped]
  } catch {
    // Giữ dữ liệu mẫu khi API chưa sẵn sàng.
  }
}

// Danh sách 6 văn phong
const toneList = ref([
  {
    id: 'humorous',
    name: 'Hài hước',
    emoji: '😂',
    bg: '#fff8e1',
    shortDesc: 'Vui nhộn, dí dỏm, thu hút tương tác',
    isDefault: true,
    fullDesc: 'Vui nhộn, dí dỏm, thu hút tương tác cao, phù hợp với nội dung đời sống, sản phẩm, xu hướng.',
    characteristics: ['Dí dỏm', 'Gần gũi', 'Ngắn gọn', 'Bắt trend', 'Dễ viral'],
    instructions: `- Viết theo phong cách hài hước, tự nhiên, như đang trò chuyện\n- Sử dụng từ ngữ đời thường, gần gũi với giới trẻ\n- Có thể dùng emoji phù hợp\n- Ưu tiên câu ngắn, punchline ở cuối\n- Không dùng từ ngữ nhạy cảm`,
  },
  {
    id: 'professional',
    name: 'Chuyên nghiệp',
    emoji: '🎓',
    bg: '#eef2ff',
    shortDesc: 'Trang trọng, rõ ràng, đáng tin cậy',
    isDefault: false,
    fullDesc: 'Giọng văn chuẩn mực, mạch lạc, sử dụng thuật ngữ chính xác và tạo dựng uy tín thương hiệu.',
    characteristics: ['Lịch sự', 'Chuyên sâu', 'Ngắn gọn'],
    instructions: `- Sử dụng ngôn từ trang trọng, lịch sự\n- Trình bày luận điểm rõ ràng, có số liệu dẫn chứng\n- Hạn chế dùng từ lóng hoặc quá nhiều emoji`,
  },
  {
    id: 'friendly',
    name: 'Thân thiện',
    emoji: '❤️',
    bg: '#ffeef0',
    shortDesc: 'Gần gũi, dễ hiểu, phù hợp đại chúng',
    isDefault: false,
    fullDesc: 'Tạo cảm giác ấm áp, cởi mở và gần gũi như một người bạn đang chia sẻ câu chuyện hàng ngày.',
    characteristics: ['Gần gũi', 'Ngắn gọn'],
    instructions: `- Xưng hô thân mật, nhẹ nhàng\n- Khơi gợi sự đồng cảm từ người đọc`,
  },
  {
    id: 'inspiring',
    name: 'Truyền cảm hứng',
    emoji: '🔥',
    bg: '#fff2e8',
    shortDesc: 'Tích cực, truyền động lực',
    isDefault: false,
    fullDesc: 'Lan tỏa năng lượng tích cực, khích lệ tinh thần và thúc đẩy người đọc hành động.',
    characteristics: ['Dễ viral', 'Gần gũi'],
    instructions: `- Dùng từ ngữ mạnh mẽ, giàu cảm xúc tích cực\n- Kể câu chuyện vượt qua thử thách`,
  },
  {
    id: 'sales',
    name: 'Bán hàng',
    emoji: '🛍️',
    bg: '#fce4ec',
    shortDesc: 'Tập trung vào lợi ích, kêu gọi hành động',
    isDefault: false,
    fullDesc: 'Nhấn mạnh điểm nổi bật của sản phẩm, ưu đãi hấp dẫn và thôi thúc chốt đơn nhanh chóng.',
    characteristics: ['Ngắn gọn', 'Bắt trend'],
    instructions: `- Nêu bật lợi ích cốt lõi ngay dòng đầu\n- Luôn kèm lời kêu gọi hành động (CTA) rõ ràng ở cuối bài`,
  },
  {
    id: 'educational',
    name: 'Chia sẻ kiến thức',
    emoji: '📖',
    bg: '#e0f2fe',
    shortDesc: 'Giáo dục, cung cấp thông tin hữu ích',
    isDefault: false,
    fullDesc: 'Cung cấp mẹo vặt, hướng dẫn chi tiết từng bước giúp người đọc giải quyết vấn đề.',
    characteristics: ['Chuyên sâu', 'Lịch sự'],
    instructions: `- Chia nhỏ nội dung thành các bước hoặc gạch đầu dòng\n- Giải thích khái niệm dễ hiểu`,
  },
])

// Dữ liệu Form chỉnh sửa
const form = reactive({
  name: toneList.value[0].name,
  description: toneList.value[0].fullDesc,
  characteristics: [...toneList.value[0].characteristics],
  aiInstructions: toneList.value[0].instructions,
  isDefault: toneList.value[0].isDefault,
})

/**
 * =====================================================================
 * CHỨC NĂNG: Chọn một văn phong và đổ dữ liệu vào form chi tiết
 * =====================================================================
 * INPUT : tone - object văn phong được click.
 * OUTPUT: cập nhật selectedToneId và các trường form.
 * SIDE EFFECT: thay đổi state form.
 * =====================================================================
 */
const selectTone = tone => {
  selectedToneId.value = tone.id
  form.name = tone.name
  form.description = tone.fullDesc
  form.characteristics = [...tone.characteristics]
  form.aiInstructions = tone.instructions
  form.isDefault = tone.isDefault
}

// Danh sách nền tảng xem trước
const previewPlatforms = [
  { id: 'facebook', name: 'Facebook', icon: 'tabler-brand-facebook', color: '#1877F2' },
  { id: 'instagram', name: 'Instagram', icon: 'tabler-brand-instagram', color: '#E4405F' },
  { id: 'tiktok', name: 'TikTok', icon: 'tabler-brand-tiktok', color: '#111111' },
  { id: 'youtube', name: 'YouTube', icon: 'tabler-brand-youtube', color: '#FF0000' },
]

// Danh sách 4 ví dụ mẫu
const otherExamples = ref([
  {
    id: 1,
    title: 'Ví dụ 1',
    emoji: '😂',
    snippet: 'Lướt trúng bản mẫu thiệp cưới ghi ngày 25.12.2099 mà giật mình...',
    fullText: `Lướt trúng bản mẫu thiệp cưới ghi ngày 25.12.2099 mà giật mình,\nchuẩn bị cho ngày trọng đại trước cả mấy chục năm thế này thì quả là chu đáo luôn 😂\n\nCòn mình thì... sáng nay mới nhớ uống cà phê thôi ☕\nCà phê sáng – Năng lượng tích cực, cho ngày dài bớt "lú"! 💚`,
  },
  {
    id: 2,
    title: 'Ví dụ 2',
    emoji: '😅',
    snippet: 'Cà phê sáng không chỉ giúp tỉnh táo mà còn giúp bạn...',
    fullText: `Cà phê sáng không chỉ giúp tỉnh táo mà còn giúp bạn nhận ra mình đã trễ làm 15 phút 😅\n\nNhưng không sao, làm ngụm cà phê thơm lừng lấy lại bình tĩnh rồi tính tiếp!\nCà phê sáng – Năng lượng tích cực mỗi ngày! ☕✨`,
  },
  {
    id: 3,
    title: 'Ví dụ 3',
    emoji: '😜',
    snippet: 'Hôm nay trời đẹp, cà phê ngon, tâm trạng cũng phải đẹp theo chứ nhỉ? ☀️✨',
    fullText: `Hôm nay trời đẹp, cà phê ngon, tâm trạng cũng phải đẹp theo chứ nhỉ? ☀️✨\n\nAi đang buồn ngủ thì ghé ngay Cà Phê Mộc làm một ly tỉnh cả người nha!`,
  },
  {
    id: 4,
    title: 'Ví dụ 4',
    emoji: '😄',
    snippet: 'Làm một ly cà phê, nạp đầy năng lượng để chiến tiếp ngày dài nào! 💪',
    fullText: `Làm một ly cà phê, nạp đầy năng lượng để chiến tiếp ngày dài nào! 💪\n\nDeadline có dí sát nút cũng phải uống xong ly cà phê sữa đá này đã rồi tính tiếp! ☕🔥`,
  },
])

const currentPreviewText = ref(otherExamples.value[0].fullText)

/**
 * =====================================================================
 * CHỨC NĂNG: Chọn ví dụ khác để xem trước trong khung bài đăng
 * =====================================================================
 * INPUT : ex - object ví dụ được click.
 * OUTPUT: cập nhật selectedExampleId và currentPreviewText.
 * SIDE EFFECT: thay đổi nội dung preview.
 * =====================================================================
 */
const selectExample = ex => {
  selectedExampleId.value = ex.id
  currentPreviewText.value = ex.fullText
}

/**
 * =====================================================================
 * CHỨC NĂNG: Sao chép nội dung ví dụ vào clipboard
 * =====================================================================
 * INPUT : text - nội dung cần sao chép.
 * OUTPUT: ghi vào clipboard.
 * SIDE EFFECT: navigator.clipboard.writeText.
 * =====================================================================
 */
const copyText = text => {
  navigator.clipboard.writeText(text)
}

/**
 * =====================================================================
 * CHỨC NĂNG: Xóa form để bắt đầu tạo văn phong mới
 * =====================================================================
 * INPUT : không có.
 * OUTPUT: reset các trường form về rỗng.
 * SIDE EFFECT: thay đổi state form.
 * =====================================================================
 */
const resetFormForNew = () => {
  form.name = ''
  form.description = ''
  form.characteristics = []
  form.aiInstructions = ''
  form.isDefault = false
}

/**
 * =====================================================================
 * CHỨC NĂNG: Cập nhật văn phong (tạm console.log, chưa có API)
 * =====================================================================
 * INPUT : state form hiện tại.
 * OUTPUT: console.log payload.
 * SIDE EFFECT: không có ngoài console.log.
 * =====================================================================
 */
const updateTone = () => {
  console.log('Cập nhật văn phong:', form)
}

onMounted(loadProfiles)
</script>

<template>
  <div class="ai-tone-tab-content">
    <!-- ================= 1. DANH SÁCH VĂN PHONG (6 CARDS) ================= -->
    <div class="mb-6">
      <div class="d-flex justify-space-between align-center mb-1">
        <div class="text-subtitle-1 font-weight-medium text-high-emphasis">
          Danh sách văn phong
        </div>
        <VBtn
          color="primary"
          prepend-icon="tabler-plus"
          size="small"
          @click="resetFormForNew"
        >
          Thêm văn phong
        </VBtn>
      </div>
      <div class="text-body-2 text-medium-emphasis mb-3">
        Chọn văn phong AI sẽ dùng khi tạo nội dung bài đăng
      </div>

      <VRow dense>
        <VCol
          v-for="tone in toneList"
          :key="tone.id"
          cols="12"
          sm="6"
          md="4"
          lg="2"
        >
          <div
            class="tone-selector-card pa-3 rounded-lg border h-100 cursor-pointer d-flex flex-column justify-space-between"
            :class="{ 'tone-selector-active': selectedToneId === tone.id }"
            @click="selectTone(tone)"
          >
            <div>
              <!-- Hàng trên: Icon Emoji + Radio & Menu -->
              <div class="d-flex justify-space-between align-start mb-2">
                <VAvatar
                  :color="tone.bg"
                  size="36"
                  rounded="lg"
                >
                  <span class="text-h6">{{ tone.emoji }}</span>
                </VAvatar>

                <div class="d-flex flex-column align-center">
                  <VIcon
                    size="18"
                    :color="selectedToneId === tone.id ? 'primary' : 'secondary'"
                    :icon="selectedToneId === tone.id ? 'tabler-circle-dot' : 'tabler-circle'"
                  />
                  <VIcon
                    v-if="!tone.isDefault"
                    icon="tabler-dots"
                    size="14"
                    color="secondary"
                    class="mt-1"
                  />
                </div>
              </div>

              <!-- Tên & Mô tả ngắn -->
              <div class="text-body-2 font-weight-medium text-high-emphasis mb-1">
                {{ tone.name }}
              </div>
              <div class="text-caption text-medium-emphasis tone-short-desc">
                {{ tone.shortDesc }}
              </div>
            </div>

            <!-- Huy hiệu Mặc định ở đáy card -->
            <div
              v-if="tone.isDefault"
              class="d-flex justify-space-between align-center mt-2 pt-1"
            >
              <VChip
                size="x-small"
                color="primary"
                variant="tonal"
                class="font-weight-medium"
              >
                Mặc định
              </VChip>
              <VIcon
                icon="tabler-dots"
                size="14"
                color="secondary"
              />
            </div>
          </div>
        </VCol>
      </VRow>
    </div>

    <!-- ================= 2. CHI TIẾT VĂN PHONG & VÍ DỤ NỘI DUNG ================= -->
    <VRow>
      <!-- ---------------- CỘT TRÁI: FORM CHI TIẾT VĂN PHONG ---------------- -->
      <VCol
        cols="12"
        lg="5"
      >
        <div class="border rounded pa-5 h-100 d-flex flex-column justify-space-between">
          <div>
            <div class="text-body-1 font-weight-medium text-high-emphasis mb-4">
              Chi tiết văn phong
            </div>

            <!-- Tên văn phong -->
            <AppTextField
              v-model="form.name"
              label="Tên văn phong"
              placeholder="Nhập tên văn phong"
              maxlength="50"
              counter
              class="mb-3"
            />

            <!-- Mô tả -->
            <AppTextarea
              v-model="form.description"
              label="Mô tả"
              placeholder="Mô tả ngắn về văn phong"
              rows="2"
              maxlength="200"
              counter
              no-resize
              class="mb-3"
            />

            <!-- Đặc điểm giọng văn -->
            <AppCombobox
              v-model="form.characteristics"
              label="Đặc điểm giọng văn"
              :items="['Dí dỏm', 'Gần gũi', 'Ngắn gọn', 'Bắt trend', 'Dễ viral', 'Chuyên sâu', 'Lịch sự']"
              multiple
              chips
              closable-chips
              class="mb-4"
            />

            <!-- Hướng dẫn chi tiết cho AI -->
            <AppTextarea
              v-model="form.aiInstructions"
              label="Hướng dẫn chi tiết cho AI"
              placeholder="Hướng dẫn chi tiết để AI viết đúng văn phong"
              rows="5"
              maxlength="500"
              counter
              class="mb-3"
            />

            <!-- Công tắc Đặt làm mặc định -->
            <div class="d-flex align-center mb-4">
              <VSwitch
                v-model="form.isDefault"
                density="compact"
                hide-details
                inset
                class="me-3"
              />
              <div>
                <div class="text-body-1 font-weight-medium text-high-emphasis">
                  Đặt làm mặc định
                </div>
                <div class="text-body-2 text-medium-emphasis">
                  Văn phong này sẽ được chọn mặc định khi tạo bài mới
                </div>
              </div>
            </div>
          </div>

          <!-- Cụm nút hành động dưới chân Form -->
          <div class="d-flex justify-space-between align-center border-t pt-4">
            <VBtn
              variant="tonal"
              color="error"
              prepend-icon="tabler-trash"
              size="small"
            >
              Xóa
            </VBtn>

            <div class="d-flex gap-2">
              <VBtn
                variant="tonal"
                color="secondary"
                size="small"
              >
                Hủy
              </VBtn>
              <VBtn
                color="primary"
                prepend-icon="tabler-device-floppy"
                size="small"
                @click="updateTone"
              >
                Cập nhật
              </VBtn>
            </div>
          </div>
        </div>
      </VCol>

      <!-- ---------------- CỘT PHẢI: VÍ DỤ NỘI DUNG ---------------- -->
      <VCol
        cols="12"
        lg="7"
      >
        <div class="border rounded pa-5 h-100">
          <!-- Header Ví dụ nội dung & Các nút chọn nền tảng -->
          <div class="d-flex justify-space-between align-start flex-wrap gap-3 mb-4">
            <div>
              <div class="text-subtitle-1 font-weight-medium text-high-emphasis">
                Ví dụ nội dung
              </div>
              <div class="text-body-2 text-medium-emphasis">
                Xem trước cách AI sẽ viết bài theo văn phong này
              </div>
            </div>

            <div class="d-flex align-center flex-wrap gap-2">
              <VBtn
                v-for="pf in previewPlatforms"
                :key="pf.id"
                size="small"
                :variant="selectedPlatform === pf.id ? 'tonal' : 'text'"
                :color="selectedPlatform === pf.id ? 'primary' : 'secondary'"
                class="text-none"
                @click="selectedPlatform = pf.id"
              >
                <VIcon
                  :icon="pf.icon"
                  :color="pf.color"
                  size="16"
                  class="me-1"
                />
                <span class="text-caption font-weight-medium">{{ pf.name }}</span>
              </VBtn>
            </div>
          </div>

          <!-- Nội dung 2 cột con: Bài đăng mô phỏng & Các ví dụ khác -->
          <VRow dense>
            <!-- Cột con Trái: Khung bài viết mạng xã hội -->
            <VCol
              cols="12"
              md="7"
            >
              <div class="border rounded pa-3">
                <!-- Thông tin Fanpage -->
                <div class="d-flex justify-space-between align-center mb-3">
                  <div class="d-flex align-center">
                    <VAvatar
                      size="38"
                      color="#3e2723"
                      class="me-2"
                    >
                      <VIcon
                        icon="tabler-coffee"
                        color="#ffd54f"
                        size="20"
                      />
                    </VAvatar>
                    <div>
                      <div class="text-body-1 font-weight-medium text-high-emphasis">
                        Cà Phê Mộc
                      </div>
                      <div class="text-body-2 text-medium-emphasis d-flex align-center">
                        2 giờ trước •
                        <VIcon
                          icon="tabler-world"
                          size="12"
                          class="ms-1"
                        />
                      </div>
                    </div>
                  </div>
                  <VBtn
                    icon="tabler-dots"
                    variant="text"
                    size="x-small"
                    color="secondary"
                  />
                </div>

                <!-- Nội dung văn bản bài viết -->
                <div class="text-body-2 text-high-emphasis mb-3 preview-post-text">
                  {{ currentPreviewText }}
                </div>

                <!-- Hình ảnh minh họa kèm chữ nghệ thuật -->
                <div class="rounded-lg overflow-hidden position-relative mb-3">
                  <VImg
                    src="https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=600"
                    height="210"
                    cover
                    class="bg-grey-darken-3"
                  >
                    <div class="post-image-overlay d-flex flex-column justify-center pa-5 h-100">
                      <div class="banner-script-text text-white">
                        Cà phê sáng
                      </div>
                      <div class="banner-script-subtext text-amber-lighten-4">
                        Năng lượng
                      </div>
                      <div class="banner-script-subtext text-white">
                        tích cực
                      </div>
                    </div>
                  </VImg>
                </div>

                <!-- Thanh tương tác (Likes, Bình luận, Chia sẻ) -->
                <div class="d-flex justify-space-between align-center pt-2 border-t text-body-2 text-medium-emphasis">
                  <div class="d-flex align-center">
                    <VAvatar
                      size="18"
                      color="#1877F2"
                      class="reaction-avatar-first"
                    >
                      <VIcon
                        icon="tabler-thumb-up"
                        size="11"
                        color="white"
                      />
                    </VAvatar>
                    <VAvatar
                      size="18"
                      color="#E4405F"
                      class="ms-n1"
                    >
                      <VIcon
                        icon="tabler-heart-filled"
                        size="11"
                        color="white"
                      />
                    </VAvatar>
                    <span class="ms-2">256</span>
                  </div>

                  <div class="d-flex align-center">
                    <VIcon
                      icon="tabler-message-circle"
                      size="15"
                      class="me-1"
                    />
                    <span>48 bình luận</span>
                  </div>

                  <div class="d-flex align-center">
                    <VIcon
                      icon="tabler-share"
                      size="15"
                      class="me-1"
                    />
                    <span>12 chia sẻ</span>
                  </div>
                </div>
              </div>
            </VCol>

            <!-- Cột con Phải: Danh sách Các ví dụ khác -->
            <VCol
              cols="12"
              md="5"
            >
              <div class="text-body-1 font-weight-medium text-high-emphasis mb-2">
                Các ví dụ khác
              </div>

              <div class="d-flex flex-column gap-2">
                <div
                  v-for="ex in otherExamples"
                  :key="ex.id"
                  class="example-item-card pa-3 rounded-lg border cursor-pointer"
                  :class="{ 'example-item-active': selectedExampleId === ex.id }"
                  @click="selectExample(ex)"
                >
                  <div class="d-flex justify-space-between align-center mb-1">
                    <div class="d-flex align-center">
                      <span class="me-2 text-body-1">{{ ex.emoji }}</span>
                      <span class="text-body-1 font-weight-medium text-high-emphasis">
                        {{ ex.title }}
                      </span>
                    </div>
                    <VBtn
                      icon="tabler-copy"
                      size="x-small"
                      variant="text"
                      color="secondary"
                      @click.stop="copyText(ex.fullText)"
                    />
                  </div>
                  <div class="text-body-2 text-medium-emphasis line-clamp-2">
                    {{ ex.snippet }}
                  </div>
                </div>
              </div>
            </VCol>
          </VRow>
        </div>
      </VCol>
    </VRow>
  </div>
</template>

<style lang="scss" scoped>
/* Thẻ chọn văn phong (Hàng 6 cards) */
.tone-selector-card {
  transition: border-color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
}
.tone-selector-card:hover {
  border-color: rgb(var(--v-theme-primary)) !important;
  background-color: rgba(var(--v-theme-primary), 0.04);
}
.tone-selector-active {
  border: 1.5px solid rgb(var(--v-theme-primary)) !important;
  background-color: rgba(var(--v-theme-primary), 0.06);
  box-shadow: 0 4px 14px rgba(var(--v-theme-primary), 0.12);
}

.tone-short-desc {
  line-height: 1.35;
}

/* Lớp phủ chữ trên ảnh bài viết mẫu */
.post-image-overlay {
  background: linear-gradient(90deg, rgba(25, 15, 10, 0.78) 0%, rgba(25, 15, 10, 0.25) 60%, transparent 100%);
}
.banner-script-text {
  font-family: 'Georgia', serif;
  font-style: italic;
  font-weight: bold;
  font-size: 24px;
  line-height: 1.15;
}
.banner-script-subtext {
  font-family: 'Georgia', serif;
  font-style: italic;
  font-weight: bold;
  font-size: 22px;
  line-height: 1.15;
}

.preview-post-text {
  line-height: 1.5;
  white-space: pre-line;
}

/* Thẻ các ví dụ khác */
.example-item-card {
  transition: border-color 0.15s ease, background-color 0.15s ease;
}
.example-item-card:hover {
  border-color: rgb(var(--v-theme-primary)) !important;
  background-color: rgba(var(--v-theme-primary), 0.04);
}
.example-item-active {
  border: 1.5px solid rgb(var(--v-theme-primary)) !important;
  background-color: rgba(var(--v-theme-primary), 0.06);
}

.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* Avatar reaction chồng lớp (icon like nằm trên icon tim) */
.reaction-avatar-first {
  position: relative;
  z-index: 1;
}
</style>
