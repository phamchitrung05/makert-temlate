<!--
  Trang Settings chỉ compose tab/panel và thao tác chung.
  Input: quyền admin, query tab, API; output: cấu hình thật theo nhóm.
  Methods: selectTab/saveActive/requestDiscard; giữ draft khi đổi tab, cảnh báo khi rời trang.
-->
<script setup>
import { computed, onMounted, onBeforeUnmount, reactive, shallowRef, watch } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
import { useAdminAuthStore } from '@/stores/adminAuth'
import { useSettings } from '@/composables/useSettings'
import { useSettingsLocales } from '@/composables/useSettingsLocales'
import { useAiContentSettings } from '@/composables/useAiContentSettings'
import { settingsTabs, settingsFields } from '@/config/settingsTabs'
import { getAlertColor } from '@/config/alertColors'
import SettingsNavigation from '@/views/settings/SettingsNavigation.vue'
import SettingsFieldsPanel from '@/views/settings/SettingsFieldsPanel.vue'
import SettingsLanguagesPanel from '@/views/settings/SettingsLanguagesPanel.vue'
import SettingsMailTest from '@/views/settings/SettingsMailTest.vue'
import SettingsOperationsPanel from '@/views/settings/SettingsOperationsPanel.vue'
import AiContentSettingsPanel from '@/views/settings/ai/AiContentSettingsPanel.vue'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'

const route = useRoute()
const router = useRouter()
const auth = useAdminAuthStore()
const canView = computed(() => auth.permissions.some(permission => ['settings.view', 'settings.manage'].includes(permission)))
const canManageAi = computed(() => auth.permissions.includes('ai_settings.manage'))
const visibleTabs = computed(() => settingsTabs.filter(tab => tab.id === 'ai-content' ? canManageAi.value : canView.value))
const activeTab = computed(() => visibleTabs.value.find(tab => tab.id === route.query.tab) ?? visibleTabs.value[0])
const isAi = computed(() => activeTab.value?.id === 'ai-content')
const group = computed(() => activeTab.value?.group)

const {
  sections, drafts, options, loading, loaded, error, canManage, saving, fieldErrors,
  notices, conflicts, dirtyGroups, operations, mailTesting, mailNotice,
  load, updateField, reset, save, reloadGroup, loadOperation, testMail,
} = useSettings()

const ai = reactive(useAiContentSettings())
const localeSettings = useSettingsLocales()
const activeDirty = computed(() => isAi.value ? ai.dirty : dirtyGroups.value.includes(group.value))
const allDirty = computed(() => dirtyGroups.value.length > 0 || ai.dirty)
const dirtyTabs = computed(() => settingsTabs.filter(tab => tab.id === 'ai-content' ? ai.dirty : dirtyGroups.value.includes(tab.group)).map(tab => tab.id))
const busy = computed(() => Boolean(saving.value) || ai.saving || mailTesting.value)
const activeConflict = computed(() => isAi.value ? ai.conflict : conflicts.value[group.value])

const saveEnabled = computed(() => activeDirty.value && !busy.value && !activeConflict.value
  && (isAi.value ? ai.canSave : canManage.value && !loading.value))

const fields = computed(() => settingsFields(group.value, options.value))
const showConfirmation = shallowRef(false)
const confirmation = shallowRef(null)

/** Đổi tab qua URL để reload giữ nhóm đang xem; draft vẫn ở composable. */
function selectTab(id) {
  router.replace({ query: { ...route.query, tab: id } })
}

/** Lưu đúng nhóm hiện tại; locale menu refresh sau khi lưu ngôn ngữ. */
async function saveActive() {
  if (!saveEnabled.value) return
  if (isAi.value) {
    await ai.saveSettings()

    return
  }
  const saved = await save(group.value)
  if (saved && group.value === 'languages')
    localeSettings.apply({ languages: sections.value.languages.values, locales: options.value.locales })
}

/** Dialog chỉ dùng khi cần bỏ draft do người dùng yêu cầu; nội dung giữ qua afterLeave. */
function requestDiscard(action) {
  if (busy.value) return
  confirmation.value = { action }
  showConfirmation.value = true
}

/** Đóng dialog và giải quyết guard/reset; không chạy callback khi hủy. */
function finishConfirmation(accepted) {
  const action = confirmation.value?.action

  showConfirmation.value = false
  action?.(accepted)
}

/** Hoàn tác tab hoặc nạp bản mới khi xung đột; các tab khác không bị ảnh hưởng. */
function discardActive() {
  const selectedGroup = group.value
  const selectedAi = isAi.value
  const conflicted = activeConflict.value

  requestDiscard(async accepted => {
    if (!accepted) return
    if (selectedAi) {
      if (conflicted) await ai.loadSettings()
      else ai.resetSettings()
    }
    else if (conflicted) await reloadGroup(selectedGroup)
    else reset(selectedGroup)
  })
}

/** Browser reload/close phải báo khi còn thay đổi; không ghi form tự động. */
function beforeUnload(event) {
  if (!allDirty.value) return
  event.preventDefault()
  event.returnValue = ''
}

watch(activeTab, tab => {
  if (!tab) return
  if (tab.id === 'ai-content' && !ai.loaded && !ai.loading) ai.loadSettings()
  if (!tab.group && tab.id !== 'ai-content' && !operations.value[tab.id]?.data) loadOperation(tab.id)
}, { immediate: true })

onMounted(() => {
  if (canView.value) load()
  window.addEventListener('beforeunload', beforeUnload)
})

onBeforeRouteLeave(() => {
  if (busy.value) return false
  if (!allDirty.value) return true

  return new Promise(resolve => requestDiscard(resolve))
})

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', beforeUnload)
  confirmation.value?.action(false)
})
</script>

<template>
  <div class="settings-page">
    <div class="mb-6">
      <h1 class="text-h4 mb-1">
        Cài đặt
      </h1>
      <p class="text-body-1 text-medium-emphasis mb-0">
        Quản lý cấu hình dùng chung của hệ thống theo từng nhóm.
      </p>
    </div>
    <VAlert
      v-if="!visibleTabs.length"
      :color="getAlertColor('warning')"
      variant="tonal"
    >
      Bạn chưa có quyền xem cài đặt hệ thống.
    </VAlert>
    <VRow v-else>
      <VCol
        cols="12"
        md="4"
        lg="3"
      >
        <SettingsNavigation
          :tabs="visibleTabs"
          :active="activeTab.id"
          :dirty="dirtyTabs"
          @select="selectTab"
        />
      </VCol>
      <VCol
        cols="12"
        md="8"
        lg="9"
      >
        <VCard border>
          <VCardText class="pa-5 pa-sm-6">
            <div class="d-flex flex-wrap align-start justify-space-between gap-3 mb-6">
              <div class="settings-heading">
                <div class="d-flex align-center gap-2 mb-2">
                  <VIcon
                    :icon="activeTab.icon"
                    color="primary"
                    size="24"
                  />
                  <h2 class="text-h5">
                    {{ activeTab.title }}
                  </h2>
                  <VChip
                    v-if="activeDirty"
                    color="warning"
                    size="small"
                  >
                    Chưa lưu
                  </VChip>
                  <VChip
                    v-else-if="!group && !isAi"
                    color="secondary"
                    size="small"
                  >
                    Chỉ đọc
                  </VChip>
                </div>
                <p class="text-body-2 text-medium-emphasis mb-0">
                  {{ activeTab.description }}
                </p>
              </div>
              <VBtn
                v-if="!group && !isAi"
                variant="tonal"
                prepend-icon="tabler-refresh"
                :loading="operations[activeTab.id]?.loading"
                @click="loadOperation(activeTab.id)"
              >
                Làm mới
              </VBtn>
            </div>

            <AiContentSettingsPanel
              v-if="isAi"
              hide-heading
              :form="ai.form"
              :provider-options="ai.providerOptions"
              :model-options="ai.modelOptions"
              :image-model-options="ai.imageModelOptions"
              :writing-profile-options="ai.writingProfileOptions"
              :loading="ai.loading"
              :loaded="ai.loaded"
              :saving="ai.saving"
              :error="ai.error"
              :catalog-notice="ai.catalogNotice"
              :image-catalog-notice="ai.imageCatalogNotice"
              :field-errors="ai.fieldErrors"
              :notice="ai.notice"
              @change-field="ai.updateField"
              @retry="ai.loadSettings"
            />
            <template v-else-if="group">
              <div
                v-if="loading"
                class="d-flex align-center gap-3 py-8"
                role="status"
                aria-live="polite"
              >
                <VProgressCircular
                  indeterminate
                  color="primary"
                  size="24"
                />
                <span>Đang tải cài đặt...</span>
              </div>
              <VAlert
                v-else-if="error"
                :color="getAlertColor('error')"
                variant="tonal"
              >
                {{ error }}
                <VBtn
                  class="mt-3 d-block"
                  variant="outlined"
                  size="small"
                  @click="load"
                >
                  Thử lại
                </VBtn>
              </VAlert>
              <template v-else-if="loaded && drafts[group]">
                <VAlert
                  v-if="!canManage"
                  :color="getAlertColor('info')"
                  variant="tonal"
                  class="mb-5"
                >
                  Bạn có quyền xem. Cần quyền quản lý cài đặt để thay đổi các giá trị.
                </VAlert>
                <VAlert
                  v-if="notices[group]"
                  :color="getAlertColor(notices[group].type)"
                  variant="tonal"
                  class="mb-5"
                  role="status"
                >
                  {{ notices[group].message }}
                </VAlert>
                <SettingsLanguagesPanel
                  v-if="group === 'languages'"
                  :form="drafts[group]"
                  :locales="options.locales"
                  :errors="fieldErrors[group]"
                  :disabled="!canManage || busy"
                  @change-field="(key, value) => updateField(group, key, value)"
                />
                <SettingsFieldsPanel
                  v-else
                  :fields="fields"
                  :form="drafts[group]"
                  :errors="fieldErrors[group]"
                  :disabled="!canManage || busy"
                  @change-field="(key, value) => updateField(group, key, value)"
                />
                <VAlert
                  v-if="group === 'site'"
                  :color="getAlertColor('info')"
                  variant="tonal"
                  class="mt-5"
                >
                  Logo và favicon hiện được quản lý bằng tài nguyên của giao diện. Chưa có cấu hình upload thương hiệu từ Settings.
                </VAlert>
                <VAlert
                  v-if="group === 'media'"
                  :color="getAlertColor('info')"
                  variant="tonal"
                  class="mt-5"
                >
                  Public: {{ options.media_disks.public }} · Private: {{ options.media_disks.private }}. Hệ thống giữ ảnh gốc. Giới hạn thực tế còn phụ thuộc cấu hình upload của PHP và máy chủ.
                </VAlert>
                <VAlert
                  v-if="group === 'seo'"
                  :color="getAlertColor('info')"
                  variant="tonal"
                  class="mt-5"
                >
                  Google Analytics và Search Console chưa được tích hợp vào website public.
                </VAlert>
                <VAlert
                  v-if="group === 'security'"
                  :color="getAlertColor('info')"
                  variant="tonal"
                  class="mt-5"
                >
                  Quản trị dùng token Bearer. Xác thực hai bước và CAPTCHA chưa được tích hợp.
                </VAlert>
                <template v-if="group === 'mail'">
                  <p class="text-caption text-medium-emphasis mt-4 mb-0">
                    Mật khẩu SMTP: {{ drafts.mail.password_configured ? 'Đã cấu hình' : 'Chưa cấu hình' }}
                  </p>
                  <SettingsMailTest
                    :disabled="!canManage || activeDirty || busy || activeConflict"
                    :loading="mailTesting"
                    :notice="mailNotice"
                    @send="testMail"
                  />
                </template>
              </template>
            </template>
            <SettingsOperationsPanel
              v-else
              :tab="activeTab.id"
              :state="operations[activeTab.id]"
              @reload="loadOperation(activeTab.id)"
            />
          </VCardText>

          <template v-if="group || isAi">
            <VDivider />
            <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3">
              <div class="text-caption text-medium-emphasis">
                {{ activeDirty ? 'Thay đổi ở các tab được giữ đến khi lưu hoặc rời trang.' : 'Lưu riêng nhóm cài đặt đang xem.' }}
              </div>
              <div class="d-flex flex-wrap gap-2">
                <VBtn
                  variant="outlined"
                  color="secondary"
                  :disabled="(!activeDirty && !activeConflict) || busy"
                  @click="discardActive"
                >
                  {{ activeConflict ? 'Tải lại bản mới' : 'Hoàn tác' }}
                </VBtn>
                <VBtn
                  prepend-icon="tabler-device-floppy"
                  :disabled="!saveEnabled"
                  :loading="isAi ? ai.saving : saving === group"
                  @click="saveActive"
                >
                  Lưu thay đổi
                </VBtn>
              </div>
            </VCardText>
          </template>
        </VCard>
      </VCol>
    </VRow>
    <VDialog
      v-model="showConfirmation"
      max-width="460"
      scrollable
      @after-leave="confirmation = null"
      @update:model-value="value => !value && finishConfirmation(false)"
    >
      <AppDialogLayout
        title="Bỏ thay đổi chưa lưu?"
        @close="finishConfirmation(false)"
      >
        <p class="text-body-1 mb-0">
          Các thay đổi liên quan đến thao tác này sẽ bị bỏ. Bạn có thể quay lại để lưu trước.
        </p>
        <template #footer>
          <VBtn
            variant="outlined"
            color="secondary"
            @click="finishConfirmation(false)"
          >
            Giữ chỉnh sửa
          </VBtn>
          <VBtn
            color="warning"
            @click="finishConfirmation(true)"
          >
            Bỏ thay đổi
          </VBtn>
        </template>
      </AppDialogLayout>
    </VDialog>
  </div>
</template>

<style scoped lang="scss">
.settings-heading {
  flex: 1 1 240px;
  min-inline-size: 0;
}
</style>
