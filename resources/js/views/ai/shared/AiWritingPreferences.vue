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
import { computed, onMounted, watch } from 'vue'
import { useAiWritingOptions } from '@/composables/ai/useAiWritingOptions'
import { briefFields } from '@/utils/aiArticleOptions'

const props = defineProps({ disabled: Boolean, regenerate: Boolean, active: { type: Boolean, default: true }, parentProfile: { type: Object, default: null } })
const preferences = defineModel({ type: Object, required: true })
const { items, defaultId, loading, error, load } = useAiWritingOptions()
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
  <div class="mb-4">
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
    <VExpansionPanels
      class="mt-4"
      variant="accordion"
    >
      <VExpansionPanel title="Brief cho bài viết">
        <VExpansionPanelText>
          <p class="text-body-2 text-medium-emphasis">
            Nêu độc giả, mục đích và góc viết cho bài này. Yêu cầu riêng ưu tiên hơn mẫu văn phong; AI phải bám sát thông tin nguồn.
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
        </VExpansionPanelText>
      </VExpansionPanel>
    </VExpansionPanels>
  </div>
</template>
