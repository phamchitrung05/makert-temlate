<!--
  =====================================================================
  CHỨC NĂNG FILE: Card "Tùy chọn" của form tạo bài AI — gom Brief, phân loại,
  cấu hình AI và hình ảnh vào các tab cho gọn.
  =====================================================================

  Render bên trong VCard thứ hai của AiContentCreateForm: VTabs điều hướng
  + VWindow chứa field của từng nhóm. Tab ẩn/hiện theo targetType và outputs:
  Post có tab Phân loại + Hình ảnh thumbnail; Social có tab Hình ảnh gallery.
  Slot mặc định nằm cuối VCardText cho footer (alert, tiến trình, nút tạo).

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - isSocial/generatesTitle/showTaxonomyTab/showImageTab: quyết định tab hiển thị.
  - visibleTabs: danh sách tab hợp lệ theo target và outputs hiện tại.
  - optionTab + watcher visibleTabs: reset về tab đầu khi tab đang mở bị ẩn.
  - update()/updateProvider(): phát bản sao source; bỏ model khi đổi provider.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : v-model source, catalog và disabled từ CreateForm.
  - OUTPUT: update:modelValue source và emit reloadCatalog; không gọi API.
  =====================================================================
-->
<script setup>
import { computed, ref, watch } from 'vue'
import AiWritingPreferences from '@/views/ai/shared/AiWritingPreferences.vue'
import AiManualTaxonomyFields from '@/views/ai/shared/AiManualTaxonomyFields.vue'
import AiThumbnailOptions from '@/views/ai/shared/AiThumbnailOptions.vue'
import AiImageProfileSelect from '@/views/ai/shared/AiImageProfileSelect.vue'

const props = defineProps({
  catalog: { type: Object, required: true },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['reloadCatalog'])
const source = defineModel({ type: Object, required: true })

const isSocial = computed(() => source.value.targetType === 'social_post')
const generatesTitle = computed(() => source.value.outputs?.includes('title') ?? false)

// Tab Phân loại theo cờ manual_taxonomy của target; tab Hình ảnh theo output ảnh.
const showTaxonomyTab = computed(() => Boolean(props.catalog.manualTaxonomy))

const showImageTab = computed(() => isSocial.value
  ? Boolean(source.value.outputs?.includes('social_gallery'))
  : Boolean(props.catalog.outputOptions?.some(option => option.value === 'thumbnail')))

const visibleTabs = computed(() => [
  { value: 'writing', title: 'Văn phong & Brief' },
  ...(showTaxonomyTab.value ? [{ value: 'taxonomy', title: 'Phân loại' }] : []),
  { value: 'ai', title: 'Tùy chọn AI' },
  ...(showImageTab.value ? [{ value: 'image', title: 'Hình ảnh' }] : []),
])

const optionTab = ref('writing')

/** Input: danh sách tab hợp lệ mới. Output: optionTab hợp lệ; reset khi tab đang mở bị ẩn. */
watch(visibleTabs, tabs => {
  if (!tabs.some(tab => tab.value === optionTab.value))
    optionTab.value = tabs[0]?.value ?? 'ai'
})

/** Input: key/value của field. Output: state mới; không mutate object của parent. */
const update = (key, value) => { source.value = { ...source.value, [key]: value } }

/** Input: provider key. Output: bỏ model của provider cũ; composable đồng bộ catalog mới. */
const updateProvider = provider => {
  source.value = { ...source.value, provider, model: '' }
}
</script>

<template>
  <VTabs
    v-model="optionTab"
    class="px-4"
    color="primary"
  >
    <VTab
      v-for="tab in visibleTabs"
      :key="tab.value"
      :value="tab.value"
    >
      {{ tab.title }}
    </VTab>
  </VTabs>

  <VDivider />

  <VCardText>
    <fieldset
      :disabled="props.disabled"
      class="ai-options-form__fields"
    >
      <VWindow
        v-model="optionTab"
        :touch="false"
      >
        <!-- ================= TAB: VĂN PHONG & BRIEF ================= -->
        <VWindowItem value="writing">
          <AiWritingPreferences
            :model-value="source.writing"
            :disabled="props.disabled"
            @update:model-value="update('writing', $event)"
          />
          <AppTextarea
            :model-value="source.instructions"
            label="Yêu cầu bổ sung cho bài này"
            placeholder="Ví dụ: Giải thích cho người mới, giữ code và số liệu nguồn."
            rows="3"
            maxlength="4000"
            :disabled="props.disabled"
            @update:model-value="update('instructions', $event)"
          />
        </VWindowItem>

        <!-- ================= TAB: PHÂN LOẠI (target có manual taxonomy) ================= -->
        <VWindowItem value="taxonomy">
          <AiManualTaxonomyFields
            :categories="source.category_ids"
            :tags="source.tag_ids"
            :disabled="props.disabled"
            @update:categories="update('category_ids', $event)"
            @update:tags="update('tag_ids', $event)"
          />
        </VWindowItem>

        <!-- ================= TAB: TÙY CHỌN AI ================= -->
        <VWindowItem value="ai">
          <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
            <span class="text-body-2 text-medium-emphasis">Provider và model từ AI Settings</span>
            <VBtn
              size="small"
              variant="text"
              prepend-icon="tabler-refresh"
              :loading="props.catalog.loading"
              :disabled="props.disabled"
              @click="emit('reloadCatalog')"
            >
              Tải lại model
            </VBtn>
          </div>
          <VAlert
            v-if="props.catalog.error"
            type="error"
            variant="tonal"
            class="mb-4"
          >
            {{ props.catalog.error }}
          </VAlert>
          <VAlert
            v-else-if="!props.catalog.loading && !props.catalog.providerOptions.length"
            type="info"
            variant="tonal"
            class="mb-4"
          >
            Chưa có provider hoạt động với API key. Thêm provider và sync model trong AI Settings.
          </VAlert>
          <VRow>
            <VCol
              cols="12"
              sm="6"
            >
              <AppSelect
                id="ai-content-provider"
                :model-value="source.provider"
                label="Provider"
                aria-label="Provider"
                :items="props.catalog.providerOptions"
                :loading="props.catalog.loading"
                :disabled="props.disabled || props.catalog.loading || !props.catalog.providerOptions.length"
                placeholder="Chọn provider"
                prepend-inner-icon="tabler-brain"
                @update:model-value="updateProvider"
              />
            </VCol>
            <VCol
              cols="12"
              sm="6"
            >
              <AppSelect
                id="ai-content-model"
                :model-value="source.model"
                label="Model"
                aria-label="Model"
                :items="props.catalog.modelOptions"
                :loading="props.catalog.loading"
                :disabled="props.disabled || props.catalog.loading || !props.catalog.modelOptions.length"
                :hint="!props.catalog.loading && source.provider && !props.catalog.modelOptions.length ? 'Provider chưa có model đã bật và khả dụng. Hãy sync trong AI Settings.' : ''"
                persistent-hint
                placeholder="Chọn model"
                @update:model-value="update('model', $event)"
              />
            </VCol>
            <VCol
              cols="12"
              sm="6"
            >
              <AppSelect
                id="ai-content-language"
                :model-value="source.language"
                :disabled="props.disabled"
                label="Ngôn ngữ đầu ra"
                aria-label="Ngôn ngữ đầu ra"
                :items="[{ title: 'Tiếng Việt', value: 'vi' }, { title: 'Tiếng Anh', value: 'en' }]"
                prepend-inner-icon="tabler-language"
                @update:model-value="update('language', $event)"
              />
            </VCol>
            <VCol
              cols="12"
              sm="6"
            >
              <AppSelect
                id="ai-content-length"
                :model-value="source.length"
                :disabled="props.disabled"
                label="Độ dài bài viết"
                aria-label="Độ dài bài viết"
                :items="props.catalog.lengthOptions ?? []"
                :hint="props.catalog.lengthOptions?.find(option => option.value === source.length)?.prompt ?? ''"
                persistent-hint
                @update:model-value="update('length', $event)"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                id="ai-content-outputs"
                :model-value="source.outputs ?? []"
                :items="props.catalog.outputOptions ?? []"
                :disabled="props.disabled || props.catalog.loading || !props.catalog.outputOptions?.length"
                label="AI sẽ tạo"
                aria-label="AI sẽ tạo"
                placeholder="Chọn các hạng mục cần tạo"
                multiple
                chips
                closable-chips
                @update:model-value="update('outputs', $event)"
              />
            </VCol>
            <VCol
              v-if="!isSocial && !generatesTitle && !props.catalog.loading"
              cols="12"
            >
              <AppTextField
                :model-value="source.title"
                :disabled="props.disabled"
                label="Tiêu đề tài nguyên"
                maxlength="255"
                placeholder="Tiêu đề AI sẽ sử dụng khi viết bài..."
                @update:model-value="update('title', $event)"
              />
            </VCol>
          </VRow>
        </VWindowItem>

        <!-- ================= TAB: HÌNH ẢNH ================= -->
        <VWindowItem value="image">
          <!-- Social: field ảnh gallery -->
          <template v-if="isSocial">
            <VRow>
              <VCol
                cols="12"
                sm="6"
              >
                <AppTextField
                  :model-value="source.imageCount"
                  type="number"
                  label="Số lượng ảnh cần tạo"
                  :min="props.catalog.imageCount?.min"
                  :max="props.catalog.imageCount?.max"
                  :disabled="props.disabled || props.catalog.loading"
                  @update:model-value="update('imageCount', $event === '' ? null : Number($event))"
                />
              </VCol>
            </VRow>
            <AiImageProfileSelect
              :model-value="source.imageProfileId"
              :disabled="props.disabled"
              @update:model-value="update('imageProfileId', $event)"
            />
            <AppSelect
              :model-value="source.imageModelId"
              :items="props.catalog.imageModelOptions ?? []"
              label="Model tạo ảnh Social"
              class="mt-4"
              :disabled="props.disabled || props.catalog.loading"
              @update:model-value="update('imageModelId', $event)"
            />
          </template>
          <!-- Post: tùy chọn thumbnail nguồn/AI -->
          <AiThumbnailOptions
            v-else
            v-model="source"
            :catalog="props.catalog"
            :disabled="props.disabled"
          />
        </VWindowItem>
      </VWindow>
    </fieldset>

    <!-- Slot footer: alert lỗi, tiến trình, nút tạo và report từ CreateForm -->
    <slot />
  </VCardText>
</template>

<style scoped>
.ai-options-form__fields {
  padding: 0;
  border: 0;
  min-inline-size: 0;
}
</style>
