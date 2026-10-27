<!--
  =====================================================================
  CHỨC NĂNG FILE: Tab "Nâng cao" của trang Lịch Đăng Bài (post-schedule).
  =====================================================================

  Panel con render bởi index.vue trong VWindowItem value="advanced".
  Cấu hình quy tắc AI: trọng số thuật toán ghép cặp 4 tầng, cooldown chống
  lặp văn phong/style, quy tắc khi 2 chiến dịch trùng ngày, retry khi lỗi và
  A/B testing. Đồng bộ vào settings marketing_schedule.global_pairing_rules_json.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - loadRules(): nạp quy tắc từ settings khi mounted.
  - persistRules(): lưu quy tắc khi người dùng thay đổi.
  - totalWeight: computed kiểm tra tổng trọng số 4 tầng.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : không có props; đọc/ghi settings marketing_schedule.
  - OUTPUT: UI quy tắc nâng cao; ghi global_pairing_rules_json.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Keys follow the marketing_schedule settings contract. */
import { computed, onMounted, ref, watch } from 'vue'
import { postScheduleService } from '@/services/postSchedule'

const loading = ref(false)
const saving = ref(false)
const loaded = ref(false)

// Quy tắc ghép cặp & chống lặp mặc định cho toàn hệ thống.
const rules = ref({
  pillar_match_weight: 40,
  tone_affinity_weight: 30,
  rotation_weight: 30,
  tone_cooldown_days: 5,
  style_cooldown_days: 5,
  conflict_resolution: 'priority',
  retry_attempts: 3,
  retry_backoff: 'exponential',
  ab_testing_enabled: false,
  strict_weekly_no_repeat: true,
})

const weightSum = computed(() =>
  rules.value.pillar_match_weight + rules.value.tone_affinity_weight + rules.value.rotation_weight,
)

const conflictOptions = [
  { title: 'Ưu tiên chiến dịch có độ ưu tiên cao', value: 'priority' },
  { title: 'Đan xen cân bằng khung giờ', value: 'interleave' },
  { title: 'Chiến dịch sau lấp vào giờ trống', value: 'fill_gap' },
]

const backoffOptions = [
  { title: 'Lũy tiến (30s → 2m → 5m)', value: 'exponential' },
  { title: 'Cố định mỗi 60 giây', value: 'fixed' },
]

/**
 * =====================================================================
 * CHỨC NĂNG: Nạp quy tắc ghép cặp toàn cục từ settings.
 * =====================================================================
 * INPUT : không có; gọi khi mounted.
 * OUTPUT: gán rules từ global_pairing_rules_json.
 * SIDE EFFECT: gọi GET settings; không ghi DB.
 * =====================================================================
 */
const loadRules = async () => {
  loading.value = true
  try {
    const section = await postScheduleService.settings()
    const stored = (section?.values ?? section ?? {}).global_pairing_rules_json
    if (stored && typeof stored === 'object')
      rules.value = { ...rules.value, ...stored }
    loaded.value = true
  } catch {
    // Giữ mặc định.
  } finally {
    loading.value = false
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Lưu quy tắc ghép cặp về settings.
 * =====================================================================
 * INPUT : không có; đọc rules hiện tại.
 * OUTPUT: gọi PUT settings; không trả giá trị.
 * SIDE EFFECT: ghi global_pairing_rules_json.
 * =====================================================================
 */
const persistRules = async () => {
  if (! loaded.value || saving.value)
    return
  saving.value = true
  try {
    await postScheduleService.updateSettings({ global_pairing_rules_json: { ...rules.value } })
  } catch {
    // Giữ UI; cho phép thử lại.
  } finally {
    saving.value = false
  }
}

onMounted(loadRules)
watch(rules, persistRules, { deep: true })
</script>

<template>
  <div>
    <VRow>
      <!-- ================= CỘT 1: TRỌNG SỐ GHÉP CẶP 4 TẦNG ================= -->
      <VCol
        cols="12"
        lg="6"
      >
        <div class="border rounded pa-5 h-100">
          <div class="text-body-1 font-weight-medium text-high-emphasis">
            Thuật toán ghép cặp 4 tầng (mặc định)
          </div>
          <div class="text-body-2 text-medium-emphasis mb-4">
            Trọng số chấm điểm khi bốc Văn phong + Visual Style trong Pool chiến dịch
          </div>

          <div class="d-flex flex-column gap-4">
            <div>
              <div class="d-flex justify-space-between align-center mb-1">
                <span class="text-body-2 font-weight-medium text-high-emphasis">
                  Tầng 1 — Khớp trụ cột ngày đăng
                </span>
                <span class="text-body-2 font-weight-bold text-primary">{{ rules.pillar_match_weight }} điểm</span>
              </div>
              <VSlider
                v-model="rules.pillar_match_weight"
                :min="0"
                :max="60"
                :step="5"
                color="primary"
                hide-details
              />
              <div class="text-caption text-medium-emphasis">
                Ưu tiên văn phong & style có taxonomy khớp pillar_type của ngày
              </div>
            </div>

            <div>
              <div class="d-flex justify-space-between align-center mb-1">
                <span class="text-body-2 font-weight-medium text-high-emphasis">
                  Tầng 2 — Tương thích Giọng văn ↔ Thị giác
                </span>
                <span class="text-body-2 font-weight-bold text-primary">{{ rules.tone_affinity_weight }} điểm</span>
              </div>
              <VSlider
                v-model="rules.tone_affinity_weight"
                :min="0"
                :max="60"
                :step="5"
                color="primary"
                hide-details
              />
              <div class="text-caption text-medium-emphasis">
                So khớp rules_json văn phong với dna_json của style ảnh
              </div>
            </div>

            <div>
              <div class="d-flex justify-space-between align-center mb-1">
                <span class="text-body-2 font-weight-medium text-high-emphasis">
                  Tầng 3 — Xoay vòng chống lặp Pool
                </span>
                <span class="text-body-2 font-weight-bold text-primary">{{ rules.rotation_weight }} điểm</span>
              </div>
              <VSlider
                v-model="rules.rotation_weight"
                :min="0"
                :max="60"
                :step="5"
                color="primary"
                hide-details
              />
              <div class="text-caption text-medium-emphasis">
                Thưởng điểm cho tổ hợp chưa dùng trong tuần/chiến dịch
              </div>
            </div>

            <div
              class="d-flex align-center justify-space-between rounded pa-3 border"
              :class="weightSum === 100 ? 'bg-var-theme-background' : 'border-warning'"
            >
              <span class="text-body-2 font-weight-medium text-high-emphasis">
                Tổng trọng số 3 tầng chấm điểm
              </span>
              <VChip
                :color="weightSum === 100 ? 'success' : 'warning'"
                size="small"
                variant="tonal"
                class="font-weight-bold"
              >
                {{ weightSum }} / 100
              </VChip>
            </div>
          </div>
        </div>
      </VCol>

      <!-- ================= CỘT 2: COOLDOWN, XUNG ĐỘT & RETRY ================= -->
      <VCol
        cols="12"
        lg="6"
      >
        <div class="border rounded pa-5 h-100">
          <div class="text-body-1 font-weight-medium text-high-emphasis">
            Chống lặp, Xung đột & Tự động thử lại
          </div>
          <div class="text-body-2 text-medium-emphasis mb-4">
            Quy tắc cooldown giữa các tuần và xử lý khi nhiều chiến dịch trùng ngày
          </div>

          <VRow dense>
            <VCol
              cols="12"
              sm="6"
            >
              <AppSelect
                v-model="rules.tone_cooldown_days"
                label="Cooldown Văn phong AI (ngày)"
                :items="[1, 3, 5, 7, 14]"
              />
            </VCol>
            <VCol
              cols="12"
              sm="6"
            >
              <AppSelect
                v-model="rules.style_cooldown_days"
                label="Cooldown Visual Style (ngày)"
                :items="[1, 3, 5, 7, 14]"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="rules.conflict_resolution"
                label="Khi 2 chiến dịch trùng ngày"
                :items="conflictOptions"
              />
            </VCol>
            <VCol
              cols="12"
              sm="6"
            >
              <AppSelect
                v-model="rules.retry_attempts"
                label="Số lần thử lại khi lỗi"
                :items="[1, 2, 3, 5]"
              />
            </VCol>
            <VCol
              cols="12"
              sm="6"
            >
              <AppSelect
                v-model="rules.retry_backoff"
                label="Chiến lược backoff"
                :items="backoffOptions"
              />
            </VCol>
          </VRow>

          <VDivider class="my-4" />

          <div class="d-flex align-center justify-space-between mb-3">
            <div class="pe-2">
              <div class="text-body-2 font-weight-medium text-high-emphasis">
                Cấm trùng Văn phong & Style trong cùng 1 tuần
              </div>
              <div class="text-caption text-medium-emphasis">
                Áp dụng mặc định cho mọi chiến dịch khi không cấu hình riêng
              </div>
            </div>
            <VSwitch
              v-model="rules.strict_weekly_no_repeat"
              color="primary"
              density="compact"
              hide-details
              inset
            />
          </div>

          <div class="d-flex align-center justify-space-between">
            <div class="pe-2">
              <div class="text-body-2 font-weight-medium text-high-emphasis">
                A/B Testing caption
              </div>
              <div class="text-caption text-medium-emphasis">
                Tạo 2 phương án nội dung để Admin chọn khi duyệt
              </div>
            </div>
            <VSwitch
              v-model="rules.ab_testing_enabled"
              color="primary"
              density="compact"
              hide-details
              inset
            />
          </div>
        </div>
      </VCol>
    </VRow>
  </div>
</template>
