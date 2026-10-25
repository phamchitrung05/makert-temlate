<!--
  =====================================================================
  CHỨC NĂNG FILE: Tab "Văn phong AI" của trang Lịch Đăng Bài (post-schedule).
  =====================================================================

  Panel con render bởi index.vue trong VWindowItem value="tone".
  Cho phép chọn kiểu văn phong viết và xem ví dụ mẫu của từng phong cách.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - refreshExample(): đổi câu ví dụ văn phong AI (mẫu).

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : không có props; state giữ trong component.
  - OUTPUT: UI chọn văn phong; chưa emit/persist vì chưa có API.
  =====================================================================
-->
<script setup>
import { ref } from 'vue'

const activeTone = ref('humorous')

const writingTones = [
  { id: 'professional', title: 'Chuyên nghiệp', desc: 'Trang trọng, rõ ràng, đáng tin cậy', icon: 'tabler-briefcase', color: 'primary' },
  { id: 'friendly', title: 'Thân thiện', desc: 'Gần gũi, dễ hiểu, phù hợp đại chúng', icon: 'tabler-heart', color: 'warning' },
  { id: 'humorous', title: 'Hài hước', desc: 'Vui nhộn, dí dỏm, thu hút tương tác', icon: 'tabler-mood-smile', color: 'primary' },
  { id: 'inspiring', title: 'Truyền cảm hứng', desc: 'Tích cực, truyền động lực', icon: 'tabler-sparkles', color: 'warning' },
  { id: 'sales', title: 'Bán hàng', desc: 'Tập trung vào lợi ích, kêu gọi hành động', icon: 'tabler-speakerphone', color: 'success' },
  { id: 'educational', title: 'Chia sẻ kiến thức', desc: 'Giáo dục, cung cấp thông tin hữu ích', icon: 'tabler-book', color: 'info' },
]

const exampleText = ref(
  'Lướt trúng bản mẫu thiệp cưới ghi ngày 25.12.2099 mà giật mình, chuẩn bị cho ngày trọng đại trước cả mấy chục năm thế này thì quả là chu đáo luôn 😂',
)

/**
 * =====================================================================
 * CHỨC NĂNG: Đổi câu ví dụ văn phong AI
 * =====================================================================
 * INPUT: không có.
 * OUTPUT: gán nội dung mới cho exampleText.
 * SIDE EFFECT: chỉ đổi reactive ref; chưa gọi API sinh ví dụ.
 * =====================================================================
 */
const refreshExample = () => {
  exampleText.value =
    'Sáng thứ Hai đầu tuần, chiếc giường bỗng trở thành nơi quyến rũ nhất hành tinh! Làm ngay một ly cà phê đậm đà để kéo mood lên nào cả nhà ơi 😂'
}
</script>

<template>
  <div>
    <!-- Kiểu văn phong cho AI -->
    <div class="text-body-1 font-weight-medium text-high-emphasis mb-1">
      Kiểu văn phong cho AI
    </div>
    <div class="text-caption text-medium-emphasis mb-3">
      Chọn phong cách viết phù hợp với thương hiệu của bạn
    </div>

    <VRow
      dense
      class="mb-4"
    >
      <VCol
        v-for="tone in writingTones"
        :key="tone.id"
        cols="12"
        sm="4"
      >
        <VLabel
          class="tone-card custom-input rounded cursor-pointer w-100 pa-2-5"
          :class="{ active: activeTone === tone.id }"
        >
          <VIcon
            v-if="activeTone === tone.id"
            icon="tabler-circle-check-filled"
            color="primary"
            size="14"
            class="tone-card__radio"
          />
          <VAvatar
            :color="tone.color"
            variant="tonal"
            size="26"
            rounded
            class="mb-1"
          >
            <VIcon
              :icon="tone.icon"
              :color="tone.color"
              size="15"
            />
          </VAvatar>
          <div class="text-body-2 font-weight-medium text-high-emphasis">
            {{ tone.title }}
          </div>
          <div class="text-caption text-medium-emphasis">
            {{ tone.desc }}
          </div>
          <VCheckbox
            :id="`tone-${tone.id}`"
            :model-value="activeTone === tone.id"
            class="tone-card__check"
            @update:model-value="activeTone = tone.id"
          />
        </VLabel>
      </VCol>
    </VRow>

    <!-- Ví dụ văn phong -->
    <div>
      <div class="text-body-2 font-weight-medium text-high-emphasis mb-2">
        Ví dụ văn phong
      </div>
      <div class="pa-3 rounded border d-flex align-center justify-space-between gap-2 example-box">
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ exampleText }}
        </p>
        <VBtn
          variant="outlined"
          color="secondary"
          size="x-small"
          prepend-icon="tabler-refresh"
          class="flex-shrink-0"
          @click="refreshExample"
        >
          Thử tạo lại
        </VBtn>
      </div>
    </div>
  </div>
</template>

<style lang="scss" scoped>
.pa-2-5 { padding: 0.625rem; }

/* Thẻ chọn văn phong AI */
.tone-card {
  position: relative;
}
.tone-card__radio {
  position: absolute;
  inset-block-start: 0.5rem;
  inset-inline-end: 0.5rem;
}
.tone-card__check {
  position: absolute;
  inset-block-start: 0;
  inset-inline-end: 0;
  visibility: hidden;
}

/* Hộp ví dụ văn phong */
.example-box {
  background-color: rgba(var(--v-theme-on-surface), var(--v-hover-opacity));
}
</style>
