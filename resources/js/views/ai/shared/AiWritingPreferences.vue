<!--
  =====================================================================
  CHỨC NĂNG FILE: Chọn mẫu văn phong hoặc mặc định và nhập brief cho bài riêng.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: options/defaultName/missingSelection (computed), setField(),
  setBrief(), useAiWritingOptions(), watcher active, onMounted().
  INPUT/OUTPUT CỦA CLASS (tổng thể): model/disabled/regenerate/parent -> emit
  preferences mới; GET options qua composable, không gọi model hoặc lưu profile.
  =====================================================================
-->
<script setup>
import { computed, onMounted, watch, ref } from 'vue'
import { useAiWritingOptions } from '@/composables/ai/useAiWritingOptions'
import { briefFields } from '@/utils/aiArticleOptions'

const props = defineProps({ disabled: Boolean, regenerate: Boolean, active: { type: Boolean, default: true }, parentProfile: { type: Object, default: null } })
const preferences = defineModel({ type: Object, required: true })
const { items, defaultId, loading, error, load } = useAiWritingOptions()

// Trạng thái mở/thu của phần Brief (section phẳng, không dùng accordion lồng trong card).
const briefOpen = ref(true)

// Placeholder gợi ý nội dung cho từng field của Brief.
const briefPlaceholders = {
  audience: 'Ví dụ: Chủ quán cà phê, nhân viên marketing, người mới bắt đầu...',
  'article_type': 'Ví dụ: Bài chia sẻ kiến thức, bài quảng bá sản phẩm, tin tức...',
  purpose: 'Ví dụ: Tăng nhận diện thương hiệu, kéo traffic về website, chốt đơn...',
  angle: 'Ví dụ: Kể câu chuyện thực tế, so sánh trước-sau, đặt câu hỏi gợi mở...',
  length: 'Ví dụ: 300-500 chữ, 1 đoạn ngắn, bài dài chi tiết...',
}

const defaultName = computed(() => items.value.find(item => item.id === defaultId.value)?.name ?? 'Chưa đặt mẫu mặc định')

const options = computed(() => [
  ...(props.regenerate ? [{ title: 'Giữ văn phong của bản gốc', value: 'inherit' }] : []),
  { title: `Mặc định website · ${defaultName.value}`, value: 'default' },
  ...items.value.map(item => ({ title: `${item.name} · v${item.version}`, value: item.id })),
])

const missingSelection = computed(() => !loading.value && !error.value && !['default', 'inherit'].includes(preferences.value.profile)
  && !items.value.some(item => item.id === Number(preferences.value.profile)))

/**
 * =====================================================================
 * Input: key/value UI. Output: preferences mới, không mutate model object cha.
 * =====================================================================
 */
const setField = (key, value) => { preferences.value = { ...preferences.value, [key]: value } }

/**
 * =====================================================================
 * Input: brief key/text. Output: brief mới; field trống vẫn sửa được trước gửi.
 * =====================================================================
 */
const setBrief = (key, value) => setField('brief', { ...preferences.value.brief, [key]: value })


// =====================================================================
// Input: feature được mở. Output: đọc lại options để thấy mẫu bật/tắt mới nhất.
// =====================================================================
onMounted(() => { if (props.active) void load() })
watch(() => props.active, value => { if (value) void load() })
</script>

<template>
  <div>
    <div class="d-flex align-start gap-2">
      <AppSelect
        :model-value="preferences.profile"
        :items="options"
        label="Văn phong"
        aria-label="Văn phong"
        :loading="loading"
        :disabled="props.disabled"
        class="flex-grow-1"
        @update:model-value="setField('profile', $event ?? 'default')"
      />
      <VBtn
        icon="tabler-refresh"
        variant="text"
        size="small"
        class="mt-6"
        aria-label="Tải lại văn phong"
        :disabled="props.disabled || loading"
        @click="load"
      />
    </div>
    <div
      v-if="props.regenerate && props.parentProfile?.name"
      class="text-caption mt-2"
    >
      Bản gốc dùng {{ props.parentProfile.name }} · v{{ props.parentProfile.version }}. Snapshot này được giữ dù mẫu đã sửa hoặc tắt.
    </div>
    <VAlert
      v-if="error || missingSelection"
      type="warning"
      variant="tonal"
      class="mt-3"
    >
      {{ error || 'Mẫu đã chọn bị tắt hoặc xóa. Hãy chọn mẫu khác hoặc mặc định website trước khi gửi.' }}
    </VAlert>
    <VDivider class="mt-4 mb-4" />

    <!-- Brief cho bài viết (có thể thu gọn) -->
    <div
      class="d-flex align-center justify-space-between cursor-pointer mb-1"
      role="button"
      @click="briefOpen = !briefOpen"
    >
      <span class="text-body-1 font-weight-medium text-high-emphasis">
        Brief cho bài viết
      </span>
      <VIcon
        :icon="briefOpen ? 'tabler-chevron-up' : 'tabler-chevron-down'"
        color="secondary"
        size="20"
      />
    </div>
    <div class="text-caption text-medium-emphasis mb-3">
      Nêu độc giả, mục đích và góc viết cho bài này
    </div>

    <div v-show="briefOpen">
      <p class="text-body-2 text-medium-emphasis">
        Yêu cầu riêng ưu tiên hơn mẫu văn phong; AI phải bám sát thông tin nguồn.
      </p>
      <VCheckbox
        v-if="props.regenerate"
        :model-value="preferences.overrideBrief"
        label="Thay brief của bản gốc"
        :disabled="props.disabled"
        @update:model-value="setField('overrideBrief', Boolean($event))"
      />
      <VRow v-if="!props.regenerate || preferences.overrideBrief">
        <VCol
          v-for="(label, key) in briefFields"
          :key="key"
          cols="12"
          :md="key === 'angle' ? 12 : 6"
        >
          <AppTextarea
            :model-value="preferences.brief?.[key] || ''"
            :label="label"
            :placeholder="briefPlaceholders[key]"
            rows="2"
            auto-grow
            maxlength="1000"
            :disabled="props.disabled"
            @update:model-value="setBrief(key, $event)"
          />
        </VCol>
      </VRow>
      <p
        v-else
        class="text-caption mb-0"
      >
        Giữ brief đã gửi ở lượt trước. Bật lựa chọn trên để thay brief hoặc xóa bằng một brief trống.
      </p>
    </div>
  </div>
</template>
