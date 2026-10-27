<!--
  =====================================================================
  CHỨC NĂNG FILE: Tab "Phong cách hình ảnh" của trang Lịch Đăng Bài (post-schedule).
  =====================================================================

  Panel con render bởi index.vue trong VWindowItem value="image-style".
  Lưới chọn phong cách hình ảnh AI dùng khi tạo ảnh minh họa cho bài đăng,
  và form chi tiết chỉnh sửa phong cách ở cột phải.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - filteredStyles: lọc danh sách theo tìm kiếm và danh mục.
  - selectStyle(style): chọn phong cách và đổ dữ liệu vào form chi tiết.
  - saveStyleChanges()/deleteStyle(): xử lý lưu/xóa (tạm console.log, chưa có API).

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : không có props; state giữ trong component.
  - OUTPUT: UI chọn/chỉnh phong cách ảnh; chưa emit/persist vì chưa có API.
  =====================================================================
-->
<script setup>
import { ref, reactive, computed } from 'vue'

const searchQuery = ref('')
const selectedCategory = ref('Tất cả danh mục')
const selectedStyleId = ref('photorealistic')

// Danh sách 12 phong cách hình ảnh
const styleList = ref([
  {
    id: 'photorealistic',
    name: 'Ảnh thật (Photorealistic)',
    shortDesc: 'Hình ảnh chụp thật, chân thực, màu sắc tự nhiên',
    fullDesc: 'Hình ảnh chụp thật, chân thực, màu sắc tự nhiên, phù hợp cho bài đăng sản phẩm, quán cafe, đồ ăn...',
    category: 'Sản phẩm',
    tags: ['Chân thực', 'Sản phẩm'],
    usageCount: 12,
    image: 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=500',
    keywords: ['photorealistic', 'realistic', 'natural light', 'high detail', 'product photo'],
    prompt:
      'A realistic photo of a cup of coffee on a wooden table with coffee beans, natural lighting, shallow depth of field, high detail, professional photography, warm and cozy atmosphere',
  },
  {
    id: 'cinematic',
    name: 'Cinematic',
    shortDesc: 'Phong cách điện ảnh, ánh sáng ấn tượng, màu sắc đậm chất phim',
    fullDesc: 'Phong cách điện ảnh với độ tương phản cao, ánh sáng kịch tính và màu sắc chuẩn phim nhựa.',
    category: 'Du lịch',
    tags: ['Điện ảnh', 'Du lịch'],
    usageCount: 8,
    image: 'https://images.unsplash.com/photo-1506744038136-46273834b3fb?w=500',
    keywords: ['cinematic lighting', 'film grain', 'dramatic', 'wide angle', 'color graded'],
    prompt:
      'Cinematic wide shot of majestic mountain peaks and a calm alpine lake at sunrise, dramatic golden hour lighting, anamorphic lens flare, film grain, 8k resolution.',
  },
  {
    id: '3d-illustration',
    name: '3D Illustration',
    shortDesc: 'Phong cách hoạt hình 3D, dễ thương, màu sắc tươi sáng',
    fullDesc: 'Minh họa 3D sống động với tạo hình nhân vật thân thiện, bề mặt mịn màng và ánh sáng studio.',
    category: 'Nhân vật',
    tags: ['3D', 'Nhân vật'],
    usageCount: 10,
    image: 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=500',
    keywords: ['3d render', 'pixar style', 'cute character', 'soft lighting', 'blender'],
    prompt:
      'Cute 3D rendered character portrait in Pixar style, cheerful expression, soft studio lighting, vibrant pastel colors, smooth clay texture, high detail.',
  },
  {
    id: 'cartoon',
    name: 'Cartoon',
    shortDesc: 'Hình ảnh hoạt hình vui nhộn, sinh động',
    fullDesc: 'Phong cách vẽ hoạt hình 2D rực rỡ, đường nét mềm mại và tràn đầy năng lượng tích cực.',
    category: 'Nhân vật',
    tags: ['Hoạt hình', 'Dễ thương'],
    usageCount: 15,
    image: 'https://images.unsplash.com/photo-1563089145-599997674d42?w=500',
    keywords: ['2d cartoon', 'anime style', 'vibrant', 'cute illustration'],
    prompt:
      'Vibrant 2D cartoon illustration of a happy girl outdoors with blue sky and fluffy clouds, expressive eyes, colorful palette, clean line art.',
  },
  {
    id: 'watercolor',
    name: 'Watercolor',
    shortDesc: 'Tranh màu nước nhẹ nhàng, nghệ thuật',
    fullDesc: 'Hiệu ứng loang màu nước tự nhiên trên nền giấy mỹ thuật, mang lại cảm giác thơ mộng.',
    category: 'Nghệ thuật',
    tags: ['Màu nước', 'Nghệ thuật'],
    usageCount: 6,
    image: 'https://images.unsplash.com/photo-1579783902614-a3fb3927b675?w=500',
    keywords: ['watercolor painting', 'soft brush', 'artistic', 'pastel tones'],
    prompt:
      'Delicate watercolor painting of a peaceful European street with cozy houses and trees, soft color bleeding, textured paper background, warm artistic mood.',
  },
  {
    id: 'minimal',
    name: 'Minimal',
    shortDesc: 'Phong cách tối giản, tinh tế, hiện đại',
    fullDesc: 'Bố cục gọn gàng, nhiều khoảng trắng, tập trung làm nổi bật chủ thể chính một cách tinh tế.',
    category: 'Hiện đại',
    tags: ['Tối giản', 'Hiện đại'],
    usageCount: 4,
    image: 'https://images.unsplash.com/photo-1513519245088-0e12902e5a38?w=500',
    keywords: ['minimalist', 'clean composition', 'neutral colors', 'modern'],
    prompt:
      'Minimalist interior scene featuring a modern beige armchair and a small wooden side table, clean white wall, soft natural shadows, calm aesthetic.',
  },
  {
    id: 'flat-design',
    name: 'Flat Design',
    shortDesc: 'Thiết kế phẳng 2D hiện đại',
    fullDesc: 'Hình minh họa vector phẳng, mảng màu rõ ràng, phù hợp cho bài viết công nghệ và giáo dục.',
    category: 'Hiện đại',
    tags: ['Flat', 'Vector'],
    usageCount: 7,
    image: 'https://images.unsplash.com/photo-1558655146-d09347e92766?w=500',
    keywords: ['flat illustration', 'vector art', 'clean shapes', 'modern ui'],
    prompt:
      'Modern flat vector illustration of a professional presenter explaining charts, geometric shapes, clean bold colors, corporate tech style.',
  },
  {
    id: 'vintage',
    name: 'Vintage',
    shortDesc: 'Phong cách cổ điển, hoài niệm',
    fullDesc: 'Tông màu trầm ấm mang hơi thở thập niên cũ, tạo cảm xúc hoài cổ và sâu lắng.',
    category: 'Cổ điển',
    tags: ['Cổ điển', 'Retro'],
    usageCount: 9,
    image: 'https://images.unsplash.com/photo-1509198397868-475647b2a1e5?w=500',
    keywords: ['vintage', 'retro aesthetic', 'warm sepia', 'nostalgic', 'film look'],
    prompt:
      'Vintage retro street photography with a classic mint scooter parked beside an old cafe, warm nostalgic sunlight, analog film grain, 1970s aesthetic.',
  },
  {
    id: 'cyberpunk',
    name: 'Cyberpunk',
    shortDesc: '',
    fullDesc: 'Không gian tương lai rực rỡ ánh đèn neon tím và xanh dương trong màn đêm đô thị.',
    category: 'Hiện đại',
    tags: ['Tương lai', 'Neon'],
    usageCount: 5,
    image: 'https://images.unsplash.com/photo-1515630278258-407f66498911?w=500',
    keywords: ['cyberpunk', 'neon lights', 'futuristic city', 'night rain'],
    prompt:
      'Futuristic cyberpunk alleyway at night, glowing pink and cyan neon signs, wet pavement reflections, atmospheric mist, high-tech urban vibe.',
  },
  {
    id: 'food-photography',
    name: 'Food Photography',
    shortDesc: '',
    fullDesc: 'Chụp cận cảnh món ăn sắc nét, làm nổi bật độ tươi ngon và hấp dẫn của nguyên liệu.',
    category: 'Đồ ăn',
    tags: ['Đồ ăn', 'Sản phẩm'],
    usageCount: 11,
    image: 'https://images.unsplash.com/photo-1509722747041-616f39b57569?w=500',
    keywords: ['food photography', 'appetizing', 'macro shot', 'fresh ingredients'],
    prompt:
      'Commercial food photography of a gourmet burger with melted cheese and fresh vegetables on a wooden board, warm studio backlight, mouth-watering detail.',
  },
  {
    id: 'hand-drawn-sketch',
    name: 'Hand-drawn Sketch',
    shortDesc: '',
    fullDesc: 'Nét vẽ chì và mực phác họa thủ công tỉ mỉ trên nền giấy trắng.',
    category: 'Nghệ thuật',
    tags: ['Phác họa', 'Minh họa'],
    usageCount: 6,
    image: 'https://images.unsplash.com/photo-1513364776144-60967b0f800f?w=500',
    keywords: ['pencil sketch', 'hand drawn', 'ink line art', 'architectural sketch'],
    prompt:
      'Detailed hand-drawn pencil and ink sketch of a charming countryside house surrounded by trees, cross-hatching shading, fine artistic linework.',
  },
  {
    id: 'isometric',
    name: 'Isometric',
    shortDesc: '',
    fullDesc: 'Mô hình 3D góc nhìn nghiêng Isometric độc đáo, thể hiện trọn vẹn cấu trúc không gian.',
    category: 'Hiện đại',
    tags: ['Isometric', '3D'],
    usageCount: 4,
    image: 'https://images.unsplash.com/photo-1554118811-1e0d58224f24?w=500',
    keywords: ['isometric 3d', 'diorama', 'miniature world', 'cute architecture'],
    prompt:
      'Cute 3D isometric cutaway diorama of a two-story artisan coffee shop with rooftop garden, warm interior lights, miniature furniture, clean render.',
  },
])

// Form Chi tiết phong cách bên phải
const form = reactive({
  name: styleList.value[0].name,
  description: styleList.value[0].fullDesc,
  category: styleList.value[0].category,
  image: styleList.value[0].image,
  keywords: [...styleList.value[0].keywords],
  samplePrompt: styleList.value[0].prompt,
})

// Lọc danh sách theo từ khóa và danh mục
const filteredStyles = computed(() => {
  return styleList.value.filter(item => {
    const matchSearch =
      !searchQuery.value
      || item.name.toLowerCase().includes(searchQuery.value.toLowerCase())
      || item.tags.some(t => t.toLowerCase().includes(searchQuery.value.toLowerCase()))

    const matchCategory =
      selectedCategory.value === 'Tất cả danh mục'
      || item.category === selectedCategory.value
      || item.tags.includes(selectedCategory.value)

    return matchSearch && matchCategory
  })
})

/**
 * =====================================================================
 * CHỨC NĂNG: Chọn 1 phong cách để xem/sửa chi tiết
 * =====================================================================
 * INPUT : style - object phong cách được click.
 * OUTPUT: cập nhật selectedStyleId và các trường form.
 * SIDE EFFECT: thay đổi state form.
 * =====================================================================
 */
const selectStyle = style => {
  selectedStyleId.value = style.id
  form.name = style.name
  form.description = style.fullDesc
  form.category = style.category
  form.image = style.image
  form.keywords = [...style.keywords]
  form.samplePrompt = style.prompt
}

const saveStyleChanges = () => {
  console.log('Đã lưu thay đổi phong cách:', form)
}

const deleteStyle = () => {
  console.log('Xóa phong cách:', selectedStyleId.value)
}
</script>

<template>
  <div class="image-style-tab-content">
    <VRow>
      <!-- ================= CỘT TRÁI: DANH SÁCH PHONG CÁCH HÌNH ẢNH (12 CARDS) ================= -->
      <VCol
        cols="12"
        lg="8"
      >
        <!-- Header & Thanh công cụ tìm kiếm / lọc -->
        <div class="d-flex justify-space-between align-start flex-wrap gap-3 mb-4">
          <div>
            <div class="text-subtitle-1 font-weight-medium text-high-emphasis">
              Danh sách phong cách hình ảnh
            </div>
            <div class="text-body-2 text-medium-emphasis">
              Chọn hoặc tạo phong cách hình ảnh để AI sử dụng khi tạo ảnh cho bài đăng
            </div>
          </div>

          <!-- Tìm kiếm & Lọc danh mục -->
          <div class="d-flex align-center flex-wrap gap-2">
            <AppTextField
              v-model="searchQuery"
              placeholder="Tìm kiếm phong cách..."
              prepend-inner-icon="tabler-search"
              density="compact"
              hide-details
              style="min-inline-size: 210px;"
            />
            <AppSelect
              v-model="selectedCategory"
              :items="['Tất cả danh mục', 'Sản phẩm', 'Du lịch', 'Nhân vật', 'Nghệ thuật', 'Hiện đại', 'Đồ ăn']"
              density="compact"
              hide-details
              style="min-inline-size: 155px;"
            />
          </div>
        </div>

        <!-- Lưới 12 Card Phong cách hình ảnh (4 cột x 3 hàng) -->
        <VRow dense>
          <VCol
            v-for="style in filteredStyles"
            :key="style.id"
            cols="12"
            sm="6"
            md="3"
            class="mb-2"
          >
            <div
              class="style-grid-card pa-3 rounded-lg border h-100 d-flex flex-column justify-space-between cursor-pointer"
              :class="{ 'style-card-selected': selectedStyleId === style.id }"
              @click="selectStyle(style)"
            >
              <div>
                <!-- Ảnh Thumbnail & Icon Check khi đang chọn -->
                <div class="position-relative rounded overflow-hidden mb-2">
                  <VImg
                    :src="style.image"
                    height="96"
                    cover
                    class="bg-grey-lighten-3"
                  />

                  <!-- Dấu tích góc trên phải khi được chọn -->
                  <VAvatar
                    v-if="selectedStyleId === style.id"
                    color="primary"
                    size="20"
                    class="selected-check-badge"
                  >
                    <VIcon
                      icon="tabler-check"
                      color="white"
                      size="13"
                    />
                  </VAvatar>
                </div>

                <!-- Tên phong cách -->
                <div class="text-body-1 font-weight-medium text-high-emphasis text-truncate mb-1">
                  {{ style.name }}
                </div>

                <!-- Mô tả ngắn -->
                <div
                  v-if="style.shortDesc"
                  class="text-body-2 text-medium-emphasis line-clamp-2 mb-2"
                >
                  {{ style.shortDesc }}
                </div>

                <!-- Tags -->
                <div class="d-flex flex-wrap gap-1 mb-2">
                  <VChip
                    v-for="tag in style.tags"
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

              <!-- Footer Card: Số lượng ảnh mẫu & Menu -->
              <div class="d-flex justify-space-between align-center pt-2 border-t text-body-2 text-medium-emphasis">
                <div class="d-flex align-center">
                  <VIcon
                    icon="tabler-photo"
                    size="14"
                    class="me-1"
                  />
                  <span>{{ style.usageCount }}</span>
                </div>
                <VBtn
                  icon="tabler-dots"
                  variant="text"
                  size="x-small"
                  color="secondary"
                  @click.stop
                />
              </div>
            </div>
          </VCol>
        </VRow>
      </VCol>

      <!-- ================= CỘT PHẢI: CHI TIẾT PHONG CÁCH ================= -->
      <VCol
        cols="12"
        lg="4"
      >
        <div class="border rounded pa-4 h-100 d-flex flex-column justify-space-between">
          <div>
            <!-- Header Chi tiết & Nút Xóa -->
            <div class="d-flex justify-space-between align-center mb-3">
              <span class="text-subtitle-1 font-weight-medium text-high-emphasis">
                Chi tiết phong cách
              </span>
              <VBtn
                variant="tonal"
                color="error"
                size="x-small"
                prepend-icon="tabler-trash"
                @click="deleteStyle"
              >
                Xóa
              </VBtn>
            </div>

            <!-- Ảnh Preview lớn -->
            <VImg
              :src="form.image"
              height="165"
              cover
              rounded="lg"
              class="mb-4 border bg-grey-lighten-3"
            />

            <!-- Tên phong cách -->
            <AppTextField
              v-model="form.name"
              label="Tên phong cách"
              maxlength="100"
              counter
              class="mb-3"
            />

            <!-- Mô tả -->
            <AppTextarea
              v-model="form.description"
              label="Mô tả"
              rows="2"
              maxlength="200"
              counter
              no-resize
              class="mb-3"
            />

            <!-- Danh mục -->
            <AppSelect
              v-model="form.category"
              label="Danh mục"
              :items="['Sản phẩm', 'Du lịch', 'Nhân vật', 'Nghệ thuật', 'Hiện đại', 'Cổ điển', 'Đồ ăn', 'Minh họa']"
              class="mb-3"
            />

            <!-- Từ khóa phong cách (Style Keywords) -->
            <AppCombobox
              v-model="form.keywords"
              label="Từ khóa phong cách (Style Keywords)"
              :items="['photorealistic', 'realistic', 'natural light', 'high detail', 'product photo', 'cinematic', 'warm tone', '3d render']"
              multiple
              chips
              closable-chips
              class="mb-3"
            />

            <!-- Prompt mẫu -->
            <AppTextarea
              v-model="form.samplePrompt"
              label="Prompt mẫu"
              rows="3"
              maxlength="500"
              counter
              no-resize
              class="mb-3"
            />
          </div>

          <!-- Footer Buttons -->
          <div class="d-flex justify-space-between align-center gap-2 pt-3 border-t flex-wrap">
            <VBtn
              variant="tonal"
              color="primary"
              size="small"
              prepend-icon="tabler-wand"
              class="flex-grow-1"
            >
              Thử tạo ảnh
            </VBtn>

            <VBtn
              color="primary"
              size="small"
              prepend-icon="tabler-device-floppy"
              @click="saveStyleChanges"
            >
              Lưu
            </VBtn>
          </div>
        </div>
      </VCol>
    </VRow>
  </div>
</template>

<style lang="scss" scoped>
/* Card phong cách trong lưới */
.style-grid-card {
  transition: border-color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
}

.style-grid-card:hover {
  border-color: rgb(var(--v-theme-primary)) !important;
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(var(--v-theme-primary), 0.1) !important;
}

.style-card-selected {
  border: 1.5px solid rgb(var(--v-theme-primary)) !important;
  background-color: rgba(var(--v-theme-primary), 0.06);
  box-shadow: 0 4px 14px rgba(var(--v-theme-primary), 0.14) !important;
}

/* Badge dấu tích góc phải trên ảnh */
.selected-check-badge {
  position: absolute;
  inset-block-start: 6px;
  inset-inline-end: 6px;
  z-index: 2;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
}

/* Giới hạn 2 dòng mô tả */
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  min-block-size: 27px;
}
</style>
