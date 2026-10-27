<!--
  =====================================================================
  CHỨC NĂNG FILE: Tab "Nền tảng" của trang Lịch Đăng Bài (post-schedule).
  =====================================================================

  Panel con render bởi index.vue trong VWindowItem value="platforms".
  Thiết lập trần an toàn số bài đăng mỗi ngày và số ảnh/bài cho từng nền tảng,
  đồng bộ vào group settings marketing_schedule.platform_quotas_json.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - loadQuotas(): nạp quota từ settings khi mounted.
  - persistQuotas(): lưu quota khi người dùng đổi stepper.
  - incrementQuota(pf)/decrementQuota(pf): đổi quota nền tảng.
  - incrementImages(pf)/decrementImages(pf): đổi số ảnh tối đa/bài.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : không có props; đọc/ghi settings marketing_schedule.
  - OUTPUT: UI trần nền tảng; ghi platform_quotas_json.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Keys follow the marketing_schedule settings contract. */
import { onMounted, ref, watch } from 'vue'
import { postScheduleService } from '@/services/postSchedule'

// Trạng thái đồng bộ settings.
const loading = ref(false)
const saving = ref(false)
const loaded = ref(false)

// Trần an toàn số bài/ngày và ảnh/bài theo từng nền tảng.
const socialPlatforms = ref([
  { name: 'Facebook', icon: 'tabler-brand-facebook', color: 'white', bg: '#1877F2', quota: 1, imageLimit: 5 },
  { name: 'Instagram', icon: 'tabler-brand-instagram', color: 'white', bg: '#E4405F', quota: 1, imageLimit: 5 },
  { name: 'TikTok', icon: 'tabler-brand-tiktok', color: 'white', bg: '#111111', quota: 1, imageLimit: 3 },
  { name: 'YouTube', icon: 'tabler-brand-youtube', color: 'white', bg: '#FF0000', quota: 1, imageLimit: 1 },
])

const incrementQuota = pf => {
  pf.quota += 1
}

const decrementQuota = pf => {
  if (pf.quota > 0)
    pf.quota -= 1
}

const incrementImages = pf => {
  pf.imageLimit += 1
}

const decrementImages = pf => {
  if (pf.imageLimit > 1)
    pf.imageLimit -= 1
}

/**
 * =====================================================================
 * CHỨC NĂNG: Nạp trần nền tảng từ settings.platform_quotas_json.
 * =====================================================================
 * INPUT : không có; gọi khi mounted.
 * OUTPUT: gán quota/imageLimit cho từng nền tảng đã lưu.
 * SIDE EFFECT: gọi API GET settings; không ghi DB.
 * =====================================================================
 */
const loadQuotas = async () => {
  loading.value = true
  try {
    const section = await postScheduleService.settings()
    const stored = (section?.values ?? section ?? {}).platform_quotas_json
    if (Array.isArray(stored)) {
      for (const row of stored) {
        const pf = socialPlatforms.value.find(p => p.name === row.name)
        if (pf) {
          pf.quota = row.quota ?? pf.quota
          pf.imageLimit = row.image_limit ?? pf.imageLimit
        }
      }
    }
    loaded.value = true
  } catch {
    // Giữ mặc định khi chưa có settings.
  } finally {
    loading.value = false
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Lưu trần nền tảng về settings.
 * =====================================================================
 * INPUT : không có; đọc state hiện tại.
 * OUTPUT: gọi PUT settings; không trả giá trị.
 * SIDE EFFECT: ghi platform_quotas_json.
 * =====================================================================
 */
const persistQuotas = async () => {
  if (! loaded.value || saving.value)
    return
  saving.value = true
  try {
    await postScheduleService.updateSettings({
      platform_quotas_json: socialPlatforms.value.map(p => ({
        name: p.name,
        quota: p.quota,
        image_limit: p.imageLimit,
      })),
    })
  } catch {
    // Giữ UI; cho phép thử lại.
  } finally {
    saving.value = false
  }
}

onMounted(loadQuotas)
watch(socialPlatforms, persistQuotas, { deep: true })
</script>

<template>
  <div>
    <div class="text-body-1 font-weight-medium text-high-emphasis mb-1">
      Giới hạn đăng mỗi ngày (mỗi nền tảng)
    </div>
    <div class="text-body-2 text-medium-emphasis mb-4">
      Trần an toàn bảo vệ tài khoản khỏi bị đánh dấu spam; áp dụng cho mọi chiến dịch
    </div>

    <div
      v-if="loading"
      class="d-flex justify-center py-6"
    >
      <VProgressCircular
        indeterminate
        size="32"
        color="primary"
      />
    </div>

    <div
      v-else
      class="d-flex flex-column gap-3"
    >
      <div
        v-for="pf in socialPlatforms"
        :key="pf.name"
        class="d-flex align-center justify-space-between border rounded pa-3"
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
              :color="pf.color"
              size="16"
            />
          </VAvatar>
          <span class="text-body-1 font-weight-medium text-high-emphasis">
            {{ pf.name }}
          </span>
        </div>

        <div class="d-flex align-center gap-6">
          <!-- Số bài/ngày -->
          <div class="d-flex align-center gap-2">
            <div class="d-inline-flex align-center border rounded pa-1">
              <VBtn
                icon="tabler-minus"
                variant="text"
                size="20"
                color="secondary"
                :disabled="pf.quota <= 0"
                @click="decrementQuota(pf)"
              />
              <span class="px-3 text-body-2 font-weight-bold text-high-emphasis">
                {{ pf.quota }}
              </span>
              <VBtn
                icon="tabler-plus"
                variant="text"
                size="20"
                color="secondary"
                @click="incrementQuota(pf)"
              />
            </div>
            <span class="text-body-2 text-medium-emphasis">
              bài/ngày
            </span>
          </div>

          <!-- Số ảnh/bài -->
          <div class="d-flex align-center gap-2">
            <div class="d-inline-flex align-center border rounded pa-1">
              <VBtn
                icon="tabler-minus"
                variant="text"
                size="20"
                color="secondary"
                :disabled="pf.imageLimit <= 1"
                @click="decrementImages(pf)"
              />
              <span class="px-3 text-body-2 font-weight-bold text-high-emphasis">
                {{ pf.imageLimit }}
              </span>
              <VBtn
                icon="tabler-plus"
                variant="text"
                size="20"
                color="secondary"
                @click="incrementImages(pf)"
              />
            </div>
            <span class="text-body-2 text-medium-emphasis">
              ảnh/bài
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
