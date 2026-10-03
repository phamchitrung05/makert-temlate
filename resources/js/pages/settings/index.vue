<!--
  =====================================================================
  CHỨC NĂNG FILE: Trang Settings quản lý các nhóm cấu hình hệ thống.
  CÁC HÀM/METHOD TRONG FILE: Chuyển tab và phát thao tác lưu cấu hình.
  INPUT/OUTPUT CỦA CLASS (tổng thể): route settings -> panel cấu hình theo tab.
  =====================================================================
-->
<script setup>
import { reactive, ref, shallowRef, watch } from 'vue'
import { useAiContentSettings } from '@/composables/useAiContentSettings'
import AiContentSettingsPanel from '@/views/settings/ai/AiContentSettingsPanel.vue'

const activeTab = shallowRef('general')

// Danh mục 10 tab theo đúng Panel Setting
const settingTabs = [
  { id: 'general', title: 'Tổng quan', icon: 'tabler-settings' },
  { id: 'ai-content', title: 'AI & Content', icon: 'tabler-sparkles' },
  { id: 'media', title: 'Media', icon: 'tabler-photo' },
  { id: 'seo', title: 'SEO', icon: 'tabler-search' },
  { id: 'email', title: 'Email', icon: 'tabler-mail' },
  { id: 'cron', title: 'Cron Jobs', icon: 'tabler-clock' },
  { id: 'webhooks', title: 'Webhooks', icon: 'tabler-webhook' },
  { id: 'languages', title: 'Languages', icon: 'tabler-language' },
  { id: 'security', title: 'Security', icon: 'tabler-shield-check' },
  { id: 'system-info', title: 'System Info', icon: 'tabler-info-circle' },
]

// Tab 1: Tổng quan
const general = reactive({
  siteName: 'Vuexy AI Content',
  siteUrl: 'https://yourdomain.com',
  siteDescription: 'Nền tảng tạo và quản lý nội dung bằng AI, giúp bạn tiết kiệm thời gian và tăng hiệu quả...',
  contactEmail: 'admin@yourdomain.com',
  timezone: '(UTC+07:00) Bangkok, Hanoi, Jakarta',
  defaultLanguage: 'Tiếng Việt',
  dateFormat: 'DD/MM/YYYY (30/09/2026)',
  timeFormat: '24 giờ (14:30)',
})

// Tab 2: AI & Content đọc/lưu cấu hình chung từ server.
const {
  form: aiForm,
  loading: aiLoading,
  loaded: aiLoaded,
  error: aiError,
  saving: aiSaving,
  notice: aiNotice,
  catalogNotice: aiCatalogNotice,
  imageCatalogNotice: aiImageCatalogNotice,
  fieldErrors: aiFieldErrors,
  providerOptions: aiProviderOptions,
  modelOptions: aiModelOptions,
  imageModelOptions: aiImageModelOptions,
  canSave: aiCanSave,
  loadSettings: loadAiSettings,
  updateField: updateAiField,
  saveSettings: saveAiSettings,
} = useAiContentSettings()

watch(activeTab, tab => {
  if (tab === 'ai-content' && !aiLoaded.value && !aiLoading.value)
    loadAiSettings()
})

// Tab 3: Media
const mediaSettings = reactive({
  driver: 'Local Storage',
  maxFileSize: '50 MB',
  allowedExtensions: ['JPG', 'PNG', 'WEBP', 'MP4', 'PDF', 'ZIP'],
  autoWebp: true,
  preserveOriginal: false,
})

// Tab 4: SEO
const seoSettings = reactive({
  titleFormat: '%title% - %sitename%',
  defaultDescription: 'Nền tảng quản lý nội dung đa kênh tự động bằng công nghệ AI.',
  gaId: 'G-774921901',
  gscCode: 'google-site-verification=abc123xyz',
  robotsTxt: 'User-agent: *\nAllow: /\nDisallow: /admin/\nSitemap: https://yourdomain.com/sitemap.xml',
})

// Tab 5: Email
const emailSettings = reactive({
  smtpHost: 'smtp.mailgun.org',
  smtpPort: '587',
  smtpUsername: 'postmaster@yourdomain.com',
  smtpPassword: '••••••••••••',
  senderName: 'Vuexy Notifications',
  senderEmail: 'no-reply@yourdomain.com',
})

// Tab 6: Cron Jobs
const cronJobs = ref([
  { id: 1, name: 'Tự động sao lưu dữ liệu', schedule: '0 2 * * * (2:00 AM)', lastRun: 'Hôm nay lúc 02:00', status: 'Active' },
  { id: 2, name: 'Đồng bộ chỉ số bài viết', schedule: '*/30 * * * * (30 phút)', lastRun: '15 phút trước', status: 'Active' },
  { id: 3, name: 'Dọn dẹp thư mục tạm Media', schedule: '0 0 * * 0 (Hàng tuần)', lastRun: '3 ngày trước', status: 'Inactive' },
])

// Tab 7: Webhooks
const webhooksList = ref([
  { id: 1, name: 'Thông báo Slack Bot', url: 'https://hooks.slack.com/services/T00/B00/XXXX', events: ['post.published', 'ai.generated'], active: true },
  { id: 2, name: 'Zapier Lead Integration', url: 'https://hooks.zapier.com/hooks/catch/123456/', events: ['user.registered'], active: false },
])

// Tab 8: Languages
const languageList = ref([
  { name: 'Tiếng Việt', code: 'vi-VN', isDefault: true, enabled: true },
  { name: 'English', code: 'en-US', isDefault: false, enabled: true },
  { name: '日本語 (Japanese)', code: 'ja-JP', isDefault: false, enabled: false },
])

// Tab 9: Security
const securitySettings = reactive({
  twoFactor: true,
  blockFailedAttempts: true,
  sessionLifetime: '60 phút',
  recaptchaKey: '6Lc_xxxxxxxxxxxxxxxxxxxxxxxxx',
})

// Tab 10: System Info
const systemSpecs = [
  { label: 'Hệ điều hành Server', value: 'Ubuntu 22.04 LTS' },
  { label: 'Phiên bản PHP', value: '8.3.10' },
  { label: 'Phiên bản Vue.js', value: 'v3.4.21' },
  { label: 'Vuetify UI', value: 'v3.6.0' },
  { label: 'Cơ sở dữ liệu', value: 'MySQL 8.0.35' },
  { label: 'Giới hạn bộ nhớ PHP', value: '512 MB' },
]

const saveSettings = () => {
  if (activeTab.value === 'ai-content')
    return saveAiSettings()
}
</script>

<template>
  <div class="settings-page">
    <div class="d-flex flex-wrap justify-space-between gap-y-4 mb-6">
      <div>
        <h4 class="text-h4 font-weight-medium">
          Settings
        </h4>
        <div class="text-body-1">
          Tùy chỉnh cấu hình hệ thống, AI, nội dung, SEO và các thiết lập khác.
        </div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <VBtn
          prepend-icon="tabler-device-floppy"
          :disabled="activeTab !== 'ai-content' || !aiCanSave"
          :loading="activeTab === 'ai-content' && aiSaving"
          @click="saveSettings"
        >
          Lưu thay đổi
        </VBtn>
      </div>
    </div>

    <VRow>
      <!-- CỘT TRÁI: Danh sách 10 Tab cài đặt (Panel Navigation) -->
      <VCol
        cols="12"
        md="4"
        lg="3"
      >
        <VCard border>
          <VCardText class="pa-3">
            <VList
              density="comfortable"
              nav
              class="pa-0"
            >
              <VListItem
                v-for="item in settingTabs"
                :key="item.id"
                :active="activeTab === item.id"
                variant="plain"
                rounded
                class="mb-1 setting-tab-item py-3 opacity-100"
                :class="{ 'setting-tab-active': activeTab === item.id }"
                :aria-current="activeTab === item.id ? 'true' : undefined"
                @click="activeTab = item.id"
              >
                <template #prepend>
                  <VIcon
                    :icon="item.icon"
                    :color="activeTab === item.id ? 'primary' : 'grey-darken-1'"
                    size="22"
                    class="me-3"
                  />
                </template>
                <VListItemTitle
                  class="text-body-1 font-weight-medium"
                  :class="activeTab === item.id ? 'text-primary' : 'text-high-emphasis'"
                >
                  {{ item.title }}
                </VListItemTitle>
              </VListItem>
            </VList>
          </VCardText>
        </VCard>
      </VCol>

      <!-- CỘT PHẢI: Khung hiển thị nội dung từng Tab -->
      <VCol
        cols="12"
        md="8"
        lg="9"
      >
        <VCard border>
          <VCardText class="pa-6">
            <!-- ================= TAB 1: TỔNG QUAN (GENERAL) ================= -->
            <div v-if="activeTab === 'general'">
              <div class="d-flex align-center mb-1">
                <VIcon
                  color="primary"
                  size="22"
                  class="me-2"
                  icon="tabler-settings"
                />
                <h2 class="text-h5 font-weight-medium text-high-emphasis">
                  Tổng quan hệ thống
                </h2>
              </div>
              <div class="text-caption text-medium-emphasis mb-6">
                Cấu hình các thông tin cơ bản cho website của bạn.
              </div>

              <!-- 1.1 Thông tin website -->
              <div class="mb-6">
                <div class="d-flex align-center mb-4">
                  <VIcon
                    size="18"
                    color="primary"
                    class="me-2"
                    icon="tabler-world"
                  />
                  <span class="text-subtitle-2 font-weight-bold text-high-emphasis">Thông tin website</span>
                </div>

                <!-- Upload Logo & Favicon -->
                <VRow
                  dense
                  class="mb-4"
                >
                  <!-- Logo -->
                  <VCol
                    cols="12"
                    md="6"
                  >
                    <VLabel
                      id="settings-logo-label"
                      class="text-body-2 text-high-emphasis text-wrap mb-1 d-block"
                    >
                      Logo website
                    </VLabel>
                    <div
                      role="group"
                      aria-labelledby="settings-logo-label"
                      class="d-flex align-center flex-wrap gap-3"
                    >
                      <div class="logo-box border rounded px-4 py-2 d-flex align-center justify-center bg-surface-variant">
                        <VIcon
                          color="primary"
                          size="20"
                          class="me-1"
                          icon="tabler-brand-vue"
                        />
                        <span class="text-body-1 font-weight-black text-high-emphasis">Vuexy</span>
                      </div>
                      <div class="d-flex gap-2">
                        <VBtn
                          variant="outlined"
                          color="primary"
                          size="small"
                          prepend-icon="tabler-upload"
                          class="text-none"
                        >
                          Thay đổi logo
                        </VBtn>
                        <VBtn
                          variant="tonal"
                          color="error"
                          size="small"
                          prepend-icon="tabler-x"
                          class="text-none"
                        >
                          Xóa
                        </VBtn>
                      </div>
                    </div>
                    <div class="text-caption text-medium-emphasis mt-1 setting-help-text">
                      Định dạng: PNG, JPG, SVG. Kích thước khuyến nghị: 180 × 60 px
                    </div>
                  </VCol>

                  <!-- Favicon -->
                  <VCol
                    cols="12"
                    md="6"
                    class="mt-3 mt-md-0"
                  >
                    <VLabel
                      id="settings-favicon-label"
                      class="text-body-2 text-high-emphasis text-wrap mb-1 d-block"
                    >
                      Favicon
                    </VLabel>
                    <div
                      role="group"
                      aria-labelledby="settings-favicon-label"
                      class="d-flex align-center flex-wrap gap-3"
                    >
                      <div class="favicon-box border rounded pa-2 d-flex align-center justify-center bg-surface-variant">
                        <VIcon
                          color="primary"
                          size="22"
                          icon="tabler-brand-vue"
                        />
                      </div>
                      <div class="d-flex gap-2">
                        <VBtn
                          variant="outlined"
                          color="primary"
                          size="small"
                          prepend-icon="tabler-upload"
                          class="text-none"
                        >
                          Thay đổi favicon
                        </VBtn>
                        <VBtn
                          variant="tonal"
                          color="error"
                          size="small"
                          prepend-icon="tabler-x"
                          class="text-none"
                        >
                          Xóa
                        </VBtn>
                      </div>
                    </div>
                    <div class="text-caption text-medium-emphasis mt-1 setting-help-text">
                      Kích thước: 32 × 32 px. Định dạng: ICO, PNG
                    </div>
                  </VCol>
                </VRow>

                <!-- Form fields thông tin -->
                <VRow dense>
                  <VCol
                    cols="12"
                    sm="6"
                  >
                    <div class="app-text-field flex-grow-1">
                      <VLabel
                        for="settings-site-name"
                        class="text-body-2 text-wrap mb-1"
                        style="line-height: 15px;"
                      >
                        Tên website <span class="text-error">*</span>
                      </VLabel>
                      <VTextField
                        id="settings-site-name"
                        v-model="general.siteName"
                        variant="outlined"
                      />
                    </div>
                  </VCol>
                  <VCol
                    cols="12"
                    sm="6"
                  >
                    <div class="app-text-field flex-grow-1">
                      <VLabel
                        for="settings-site-url"
                        class="text-body-2 text-wrap mb-1"
                        style="line-height: 15px;"
                      >
                        URL website <span class="text-error">*</span>
                      </VLabel>
                      <VTextField
                        id="settings-site-url"
                        v-model="general.siteUrl"
                        variant="outlined"
                      />
                    </div>
                  </VCol>
                  <VCol
                    cols="12"
                    sm="6"
                  >
                    <AppTextarea
                      v-model="general.siteDescription"
                      label="Mô tả website"
                      variant="outlined"
                      rows="3"
                    />
                  </VCol>
                  <VCol
                    cols="12"
                    sm="6"
                  >
                    <div class="app-text-field flex-grow-1">
                      <VLabel
                        for="settings-contact-email"
                        class="text-body-2 text-wrap mb-1"
                        style="line-height: 15px;"
                      >
                        Email liên hệ <span class="text-error">*</span>
                      </VLabel>
                      <VTextField
                        id="settings-contact-email"
                        v-model="general.contactEmail"
                        variant="outlined"
                      />
                    </div>
                  </VCol>
                </VRow>
              </div>

              <VDivider class="my-6" />

              <!-- 1.2 Múi giờ và ngôn ngữ -->
              <div class="mb-6">
                <div class="d-flex align-center mb-4">
                  <VIcon
                    size="18"
                    color="primary"
                    class="me-2"
                    icon="tabler-world"
                  />
                  <span class="text-subtitle-2 font-weight-bold text-high-emphasis">Múi giờ và ngôn ngữ</span>
                </div>
                <VRow dense>
                  <VCol
                    cols="12"
                    sm="6"
                  >
                    <div class="app-select flex-grow-1">
                      <VLabel
                        for="settings-timezone"
                        class="text-body-2 text-wrap mb-1"
                        style="line-height: 15px;"
                      >
                        Múi giờ <span class="text-error">*</span>
                      </VLabel>
                      <VSelect
                        id="settings-timezone"
                        v-model="general.timezone"
                        :items="['(UTC+07:00) Bangkok, Hanoi, Jakarta', '(UTC+00:00) UTC', '(UTC+08:00) Singapore']"
                        variant="outlined"
                      />
                    </div>
                  </VCol>
                  <VCol
                    cols="12"
                    sm="6"
                  >
                    <div class="app-select flex-grow-1">
                      <VLabel
                        for="settings-default-language"
                        class="text-body-2 text-wrap mb-1"
                        style="line-height: 15px;"
                      >
                        Ngôn ngữ mặc định <span class="text-error">*</span>
                      </VLabel>
                      <VSelect
                        id="settings-default-language"
                        v-model="general.defaultLanguage"
                        :items="['Tiếng Việt', 'English']"
                        prepend-inner-icon="tabler-flag"
                        variant="outlined"
                      />
                    </div>
                  </VCol>
                  <VCol
                    cols="12"
                    sm="6"
                  >
                    <AppSelect
                      v-model="general.dateFormat"
                      label="Định dạng ngày"
                      :items="['DD/MM/YYYY (30/09/2026)', 'YYYY-MM-DD', 'MM/DD/YYYY']"
                      variant="outlined"
                    />
                  </VCol>
                  <VCol
                    cols="12"
                    sm="6"
                  >
                    <AppSelect
                      v-model="general.timeFormat"
                      label="Định dạng giờ"
                      :items="['24 giờ (14:30)', '12 giờ (02:30 PM)']"
                      variant="outlined"
                    />
                  </VCol>
                </VRow>
              </div>
            </div>

            <!-- ================= TAB 2: AI & CONTENT ================= -->
            <AiContentSettingsPanel
              v-else-if="activeTab === 'ai-content'"
              :form="aiForm"
              :loading="aiLoading"
              :loaded="aiLoaded"
              :error="aiError"
              :saving="aiSaving"
              :notice="aiNotice"
              :catalog-notice="aiCatalogNotice"
              :image-catalog-notice="aiImageCatalogNotice"
              :field-errors="aiFieldErrors"
              :provider-options="aiProviderOptions"
              :model-options="aiModelOptions"
              :image-model-options="aiImageModelOptions"
              @change-field="updateAiField"
              @retry="loadAiSettings"
            />
            <!-- ================= TAB 3: MEDIA ================= -->
            <div v-else-if="activeTab === 'media'">
              <div class="d-flex align-center mb-1">
                <VIcon
                  color="primary"
                  size="22"
                  class="me-2"
                  icon="tabler-photo"
                />
                <h2 class="text-h5 font-weight-medium text-high-emphasis">
                  Quản lý Media &amp; Upload
                </h2>
              </div>
              <div class="text-caption text-medium-emphasis mb-6">
                Thiết lập lưu trữ hình ảnh, video và giới hạn dung lượng tải lên.
              </div>

              <VRow dense>
                <VCol
                  cols="12"
                  sm="6"
                >
                  <AppSelect
                    v-model="mediaSettings.driver"
                    label="Bộ nhớ lưu trữ (Storage Driver)"
                    :items="['Local Storage', 'Amazon S3', 'Cloudinary', 'Google Cloud Storage']"
                    variant="outlined"
                  />
                </VCol>
                <VCol
                  cols="12"
                  sm="6"
                >
                  <AppSelect
                    v-model="mediaSettings.maxFileSize"
                    label="Dung lượng tải lên tối đa mỗi tệp"
                    :items="['10 MB', '25 MB', '50 MB', '100 MB']"
                    variant="outlined"
                  />
                </VCol>
                <VCol cols="12">
                  <AppCombobox
                    v-model="mediaSettings.allowedExtensions"
                    label="Các định dạng tệp cho phép"
                    multiple
                    chips
                    closable-chips
                    variant="outlined"
                  />
                </VCol>
              </VRow>

              <VDivider class="my-5" />
              <div class="text-subtitle-2 font-weight-bold mb-3 text-high-emphasis">
                Tối ưu hóa hình ảnh
              </div>
              <div class="d-flex flex-column gap-3">
                <VSwitch
                  v-model="mediaSettings.autoWebp"
                  label="Tự động nén và chuyển đổi sang định dạng .WebP"
                  color="primary"
                  density="compact"
                  inset
                  hide-details
                />
                <VSwitch
                  v-model="mediaSettings.preserveOriginal"
                  label="Giữ lại bản gốc sau khi nén ảnh"
                  color="primary"
                  density="compact"
                  inset
                  hide-details
                />
              </div>
            </div>

            <!-- ================= TAB 4: SEO ================= -->
            <div v-else-if="activeTab === 'seo'">
              <div class="d-flex align-center mb-1">
                <VIcon
                  color="primary"
                  size="22"
                  class="me-2"
                  icon="tabler-search"
                />
                <h2 class="text-h5 font-weight-medium text-high-emphasis">
                  Cấu hình SEO và Meta
                </h2>
              </div>
              <div class="text-caption text-medium-emphasis mb-6">
                Quản lý chỉ mục tìm kiếm Google, OpenGraph và sơ đồ trang sitemap.
              </div>

              <VRow dense>
                <VCol cols="12">
                  <AppTextField
                    v-model="seoSettings.titleFormat"
                    label="Cấu trúc tiêu đề trang (Title Format)"
                    variant="outlined"
                    placeholder="%title% - %sitename%"
                  />
                </VCol>
                <VCol cols="12">
                  <AppTextarea
                    v-model="seoSettings.defaultDescription"
                    label="Meta Description mặc định"
                    variant="outlined"
                    rows="3"
                  />
                </VCol>
                <VCol
                  cols="12"
                  sm="6"
                >
                  <AppTextField
                    v-model="seoSettings.gaId"
                    label="Mã theo dõi Google Analytics (GA4)"
                    variant="outlined"
                    placeholder="G-XXXXXXXXXX"
                  />
                </VCol>
                <VCol
                  cols="12"
                  sm="6"
                >
                  <AppTextField
                    v-model="seoSettings.gscCode"
                    label="Mã xác minh Google Search Console"
                    variant="outlined"
                    placeholder="google-site-verification=..."
                  />
                </VCol>
                <VCol cols="12">
                  <AppTextarea
                    v-model="seoSettings.robotsTxt"
                    label="Nội dung Robots.txt"
                    class="font-monospace"
                    variant="outlined"
                    rows="3"
                  />
                </VCol>
              </VRow>
            </div>

            <!-- ================= TAB 5: EMAIL ================= -->
            <div v-else-if="activeTab === 'email'">
              <div class="d-flex align-center mb-1">
                <VIcon
                  color="primary"
                  size="22"
                  class="me-2"
                  icon="tabler-mail"
                />
                <h2 class="text-h5 font-weight-medium text-high-emphasis">
                  Thiết lập gửi email
                </h2>
              </div>
              <div class="text-caption text-medium-emphasis mb-6">
                Cấu hình dịch vụ máy chủ SMTP gửi mail thông báo và liên hệ.
              </div>

              <VRow dense>
                <VCol
                  cols="12"
                  sm="8"
                >
                  <AppTextField
                    v-model="emailSettings.smtpHost"
                    label="Máy chủ SMTP Host"
                    variant="outlined"
                    placeholder="smtp.mailgun.org"
                  />
                </VCol>
                <VCol
                  cols="12"
                  sm="4"
                >
                  <AppTextField
                    v-model="emailSettings.smtpPort"
                    label="Cổng SMTP Port"
                    variant="outlined"
                    placeholder="587"
                  />
                </VCol>
                <VCol
                  cols="12"
                  sm="6"
                >
                  <AppTextField
                    v-model="emailSettings.smtpUsername"
                    label="Tên đăng nhập SMTP"
                    variant="outlined"
                  />
                </VCol>
                <VCol
                  cols="12"
                  sm="6"
                >
                  <AppTextField
                    v-model="emailSettings.smtpPassword"
                    label="Mật khẩu SMTP"
                    type="password"
                    variant="outlined"
                  />
                </VCol>
                <VCol
                  cols="12"
                  sm="6"
                >
                  <AppTextField
                    v-model="emailSettings.senderName"
                    label="Tên người gửi hiển thị"
                    variant="outlined"
                  />
                </VCol>
                <VCol
                  cols="12"
                  sm="6"
                >
                  <AppTextField
                    v-model="emailSettings.senderEmail"
                    label="Email người gửi"
                    variant="outlined"
                  />
                </VCol>
              </VRow>
              <div class="d-flex justify-end mt-2">
                <VBtn
                  variant="outlined"
                  color="primary"
                  prepend-icon="tabler-send"
                  class="text-none"
                >
                  Gửi email kiểm tra
                </VBtn>
              </div>
            </div>

            <!-- ================= TAB 6: CRON JOBS ================= -->
            <div v-else-if="activeTab === 'cron'">
              <div class="d-flex align-center mb-1">
                <VIcon
                  color="primary"
                  size="22"
                  class="me-2"
                  icon="tabler-clock"
                />
                <h2 class="text-h5 font-weight-medium text-high-emphasis">
                  Tác vụ tự động (Cron Jobs)
                </h2>
              </div>
              <div class="text-caption text-medium-emphasis mb-6">
                Xem và kích hoạt các tác vụ lập lịch định kỳ trong hệ thống.
              </div>

              <VTable
                density="comfortable"
                class="border rounded"
              >
                <thead>
                  <tr class="text-caption text-medium-emphasis">
                    <th>Tên tác vụ</th>
                    <th>Lịch trình</th>
                    <th>Lần chạy cuối</th>
                    <th>Trạng thái</th>
                    <th class="text-end">
                      Thao tác
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="job in cronJobs"
                    :key="job.id"
                  >
                    <td class="font-weight-bold text-caption text-high-emphasis">
                      {{ job.name }}
                    </td>
                    <td class="text-caption text-medium-emphasis font-monospace">
                      {{ job.schedule }}
                    </td>
                    <td class="text-caption text-medium-emphasis">
                      {{ job.lastRun }}
                    </td>
                    <td>
                      <VChip
                        size="x-small"
                        :color="job.status === 'Active' ? 'success' : 'grey'"
                        variant="tonal"
                      >
                        {{ job.status }}
                      </VChip>
                    </td>
                    <td class="text-end">
                      <VBtn
                        size="small"
                        variant="text"
                        color="primary"
                        class="text-none"
                      >
                        Chạy ngay
                      </VBtn>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>

            <!-- ================= TAB 7: WEBHOOKS ================= -->
            <div v-else-if="activeTab === 'webhooks'">
              <div class="d-flex justify-space-between align-center mb-1">
                <div class="d-flex align-center">
                  <VIcon
                    color="primary"
                    size="22"
                    class="me-2"
                    icon="tabler-webhook"
                  />
                  <h2 class="text-h5 font-weight-medium text-high-emphasis">
                    Tích hợp Webhooks
                  </h2>
                </div>
                <VBtn
                  color="primary"
                  size="small"
                  prepend-icon="tabler-plus"
                  class="text-none"
                >
                  Thêm Webhook
                </VBtn>
              </div>
              <div class="text-caption text-medium-emphasis mb-6">
                Gửi dữ liệu sự kiện thời gian thực đến dịch vụ bên ngoài (Slack, Discord, Zapier).
              </div>

              <div class="d-flex flex-column gap-3">
                <div
                  v-for="hook in webhooksList"
                  :key="hook.id"
                  class="border rounded pa-4 d-flex justify-space-between align-center"
                >
                  <div>
                    <div class="text-subtitle-2 font-weight-bold text-high-emphasis">
                      {{ hook.name }}
                    </div>
                    <div class="text-caption text-medium-emphasis font-monospace">
                      {{ hook.url }}
                    </div>
                    <div class="d-flex gap-1 mt-2">
                      <VChip
                        v-for="ev in hook.events"
                        :key="ev"
                        size="x-small"
                        variant="tonal"
                        color="primary"
                      >
                        {{ ev }}
                      </VChip>
                    </div>
                  </div>
                  <div class="d-flex align-center gap-2">
                    <VSwitch
                      v-model="hook.active"
                      color="primary"
                      density="compact"
                      hide-details
                      inset
                    />
                    <VBtn
                      icon="tabler-trash"
                      variant="text"
                      size="small"
                      color="error"
                    />
                  </div>
                </div>
              </div>
            </div>

            <!-- ================= TAB 8: LANGUAGES ================= -->
            <div v-else-if="activeTab === 'languages'">
              <div class="d-flex justify-space-between align-center mb-1">
                <div class="d-flex align-center">
                  <VIcon
                    color="primary"
                    size="22"
                    class="me-2"
                    icon="tabler-language"
                  />
                  <h2 class="text-h5 font-weight-medium text-high-emphasis">
                    Ngôn ngữ hệ thống
                  </h2>
                </div>
                <VBtn
                  color="primary"
                  size="small"
                  prepend-icon="tabler-plus"
                  class="text-none"
                >
                  Thêm ngôn ngữ
                </VBtn>
              </div>
              <div class="text-caption text-medium-emphasis mb-6">
                Quản lý các bản dịch giao diện và ngôn ngữ hỗ trợ trên website.
              </div>

              <VTable
                density="comfortable"
                class="border rounded"
              >
                <thead>
                  <tr class="text-caption text-medium-emphasis">
                    <th>Ngôn ngữ</th>
                    <th>Mã định danh</th>
                    <th>Mặc định</th>
                    <th>Kích hoạt</th>
                    <th class="text-end">
                      Hành động
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="lang in languageList"
                    :key="lang.code"
                  >
                    <td class="font-weight-bold text-caption text-high-emphasis">
                      <VIcon
                        size="16"
                        class="me-1"
                        icon="tabler-flag"
                      /> {{ lang.name }}
                    </td>
                    <td class="text-caption font-monospace">
                      {{ lang.code }}
                    </td>
                    <td>
                      <VChip
                        v-if="lang.isDefault"
                        size="x-small"
                        color="primary"
                        variant="tonal"
                      >
                        Mặc định
                      </VChip>
                    </td>
                    <td>
                      <VSwitch
                        v-model="lang.enabled"
                        color="primary"
                        density="compact"
                        hide-details
                        inset
                      />
                    </td>
                    <td class="text-end">
                      <VBtn
                        size="small"
                        variant="text"
                        color="primary"
                        class="text-none"
                      >
                        Dịch nhãn
                      </VBtn>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>

            <!-- ================= TAB 9: SECURITY ================= -->
            <div v-else-if="activeTab === 'security'">
              <div class="d-flex align-center mb-1">
                <VIcon
                  color="primary"
                  size="22"
                  class="me-2"
                  icon="tabler-shield-check"
                />
                <h2 class="text-h5 font-weight-medium text-high-emphasis">
                  Bảo mật và Quyền truy cập
                </h2>
              </div>
              <div class="text-caption text-medium-emphasis mb-6">
                Thiết lập bảo vệ tài khoản, xác thực hai lớp (2FA) và giới hạn phiên.
              </div>

              <div class="d-flex flex-column gap-4">
                <div class="d-flex justify-space-between align-center pa-3 border rounded">
                  <div>
                    <div class="text-subtitle-2 font-weight-bold text-high-emphasis">
                      Bắt buộc xác thực hai bước (2FA)
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      Yêu cầu tất cả tài khoản quản trị viên sử dụng OTP 2FA.
                    </div>
                  </div>
                  <VSwitch
                    v-model="securitySettings.twoFactor"
                    color="primary"
                    density="compact"
                    hide-details
                    inset
                  />
                </div>

                <div class="d-flex justify-space-between align-center pa-3 border rounded">
                  <div>
                    <div class="text-subtitle-2 font-weight-bold text-high-emphasis">
                      Chặn IP đăng nhập sai nhiều lần
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      Khóa tạm thời địa chỉ IP khi đăng nhập thất bại quá 5 lần.
                    </div>
                  </div>
                  <VSwitch
                    v-model="securitySettings.blockFailedAttempts"
                    color="primary"
                    density="compact"
                    hide-details
                    inset
                  />
                </div>

                <VRow
                  dense
                  class="mt-2"
                >
                  <VCol
                    cols="12"
                    sm="6"
                  >
                    <AppSelect
                      v-model="securitySettings.sessionLifetime"
                      label="Thời gian hết hạn phiên làm việc"
                      :items="['30 phút', '60 phút', '24 giờ', '7 ngày']"
                      variant="outlined"
                    />
                  </VCol>
                  <VCol
                    cols="12"
                    sm="6"
                  >
                    <AppTextField
                      v-model="securitySettings.recaptchaKey"
                      label="Khóa API Google reCAPTCHA v3"
                      variant="outlined"
                      placeholder="Site Key..."
                    />
                  </VCol>
                </VRow>
              </div>
            </div>

            <!-- ================= TAB 10: SYSTEM INFO ================= -->
            <div v-else-if="activeTab === 'system-info'">
              <div class="d-flex align-center mb-1">
                <VIcon
                  color="primary"
                  size="22"
                  class="me-2"
                  icon="tabler-info-circle"
                />
                <h2 class="text-h5 font-weight-medium text-high-emphasis">
                  Thông tin hệ thống
                </h2>
              </div>
              <div class="text-caption text-medium-emphasis mb-6">
                Chi tiết cấu hình máy chủ, phiên bản phần mềm và tài nguyên sử dụng.
              </div>

              <VRow
                dense
                class="mb-4"
              >
                <VCol
                  v-for="info in systemSpecs"
                  :key="info.label"
                  cols="12"
                  sm="6"
                  md="4"
                >
                  <div class="border rounded pa-3 bg-surface-variant">
                    <div class="text-caption text-medium-emphasis">
                      {{ info.label }}
                    </div>
                    <div class="text-body-2 font-weight-bold text-high-emphasis">
                      {{ info.value }}
                    </div>
                  </div>
                </VCol>
              </VRow>

              <div class="border rounded pa-4 bg-surface-variant">
                <div class="d-flex justify-space-between text-caption font-weight-bold mb-2">
                  <span>Dung lượng ổ đĩa sử dụng</span>
                  <span>18.4 GB / 100 GB (18.4%)</span>
                </div>
                <VProgressLinear
                  model-value="18.4"
                  color="primary"
                  height="8"
                  rounded
                />
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>



<style scoped>
.settings-page {
  min-block-size: 100%;
}

/* Item tab bên menu trái */
.setting-tab-item {
  position: relative;
  cursor: pointer;
  transition: none;
}

.setting-tab-active {
  color: rgb(var(--v-theme-primary)) !important;
}

.setting-tab-active::before {
  position: absolute;
  background: rgb(var(--v-theme-primary));
  block-size: 100%;
  content: "";
  inline-size: 4px;
  inset-block-start: 0;
  inset-inline-start: 0;
  pointer-events: none;
}

/* Khung preview logo và favicon */
.logo-box {
  block-size: 48px;
  inline-size: 140px;
}

.favicon-box {
  block-size: 48px;
  inline-size: 48px;
}

.setting-help-text {
  font-size: 0.6875rem;
}
</style>
