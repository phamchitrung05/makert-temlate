<!--
  =====================================================================
  CHỨC NĂNG FILE: Dialog xem các phiên bản AI trong cùng một content session.
  =====================================================================
  Danh sách chính chỉ hiển thị bản hiện tại; dialog này giữ khả năng đối chiếu
  từng run, model và trạng thái mà không tạo thêm dòng content.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - watcher item/finishLeave(): giữ snapshot trong transition đóng, tránh nhấp nháy.
  - close(): đóng dialog qua event của component cha.
  - openVersion(): yêu cầu cha mở một phiên bản cụ thể để xem chi tiết.
  - versionLabel(): chọn nhãn phiên bản và trạng thái bằng allowlist.
  - dateLabel(): định dạng thời điểm server theo múi giờ người dùng.
  - qualityScore(): lấy điểm chất lượng hợp lệ cho từng phiên bản.
  - visible/versions/displayItem: xác định trạng thái mở và các run trong session.
  - props item: nhận session đã gom; emit close/open/afterLeave: trả thao tác về page.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : item đã gom theo session, gồm versions/history và currentVersionId.
  - OUTPUT: dialog đọc lịch sử và emit close/open/afterLeave.
  - SIDE EFFECT: không gọi API, không mutate props, không tạo run mới.
  =====================================================================
-->
<script setup>
import { computed, shallowRef, watch } from 'vue'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'

const props = defineProps({
  item: { type: Object, default: null },
})

const emit = defineEmits(['close', 'open', 'afterLeave'])

const visible = shallowRef(false)
const displayItem = shallowRef(null)
const versions = computed(() => displayItem.value?.versions ?? [])

/**
 * =====================================================================
 * CHỨC NĂNG: Giữ dữ liệu lịch sử ổn định trong transition đóng dialog.
 * =====================================================================
 * INPUT: item session từ page, có thể chuyển về null ngay sau click đóng.
 * OUTPUT: visible tắt trước; displayItem chỉ dọn sau after-leave.
 * SIDE EFFECT: cập nhật state cục bộ, không gọi API hoặc mutate props.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
watch(() => props.item, value => {
  if (value) {
    displayItem.value = value
    visible.value = true

    return
  }

  visible.value = false
}, { immediate: true })

/**
 * =====================================================================
 * CHỨC NĂNG: Kết thúc transition đóng và dọn snapshot lịch sử.
 * =====================================================================
 * INPUT: lifecycle after-leave của VDialog.
 * OUTPUT: displayItem rỗng nếu page chưa mở lại lịch sử.
 * SIDE EFFECT: emit afterLeave để page có thể đồng bộ state; không gọi API.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
function finishLeave() {
  if (!props.item) displayItem.value = null
  emit('afterLeave')
}

/**
 * =====================================================================
 * CHỨC NĂNG: Chọn nhãn trạng thái tiếng Việt cho một phiên bản.
 * =====================================================================
 * INPUT: version record đã chuẩn hóa.
 * OUTPUT: nhãn allowlist; trạng thái lạ dùng Đang xử lý.
 * SIDE EFFECT: hàm thuần, không gọi API hoặc mutate props.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
function versionLabel(version) {
  return {
    review: 'Chờ duyệt',
    applied: 'Đã áp dụng',
    rejected: 'Đã từ chối',
    generating: 'Đang tạo',
    failed: 'Tạo thất bại',
    cancelled: 'Đã hủy',
    expired: 'Hết hạn',
  }[version.status] ?? 'Đang xử lý'
}

/**
 * =====================================================================
 * CHỨC NĂNG: Định dạng thời điểm tạo run để đối chiếu lịch sử.
 * =====================================================================
 * INPUT: timestamp ISO từ server.
 * OUTPUT: ngày giờ local tiếng Việt hoặc nhãn chưa có thời điểm.
 * SIDE EFFECT: hàm thuần.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
function dateLabel(value) {
  return value ? new Date(value).toLocaleString('vi-VN') : 'Chưa có thời điểm'
}

/**
 * =====================================================================
 * CHỨC NĂNG: Lấy điểm chất lượng để hiển thị cạnh từng phiên bản.
 * =====================================================================
 * INPUT: version record có qualityEvaluation từ workspace.
 * OUTPUT: điểm số hữu hạn hoặc null khi phiên bản chưa được chấm.
 * SIDE EFFECT: hàm thuần, không gọi API hoặc mutate props.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
function qualityScore(version) {
  const raw = version.qualityEvaluation?.score_total ?? version.quality_evaluation?.score_total

  return Number.isFinite(Number(raw)) ? Number(raw) : null
}

/**
 * =====================================================================
 * CHỨC NĂNG: Yêu cầu page mở chi tiết phiên bản đã tạo thành công.
 * =====================================================================
 * INPUT: version record từ list lịch sử.
 * OUTPUT: event open với đúng run UUID; bỏ phiên bản chưa có nội dung.
 * SIDE EFFECT: emit về component cha; không gọi API hoặc tạo candidate.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
function openVersion(version) {
  if (['failed', 'cancelled', 'expired', 'generating'].includes(version.status)) return
  emit('open', version)
}

/**
 * =====================================================================
 * CHỨC NĂNG: Chuyển yêu cầu đóng dialog về page.
 * =====================================================================
 * INPUT: thao tác đóng từ UI.
 * OUTPUT: event close; lịch sử được giữ trên server.
 * SIDE EFFECT: emit cục bộ, không xóa run.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
function close() {
  emit('close')
}
</script>

<template>
  <VDialog
    :model-value="visible"
    max-width="680"
    scrollable
    @update:model-value="value => !value && close()"
    @after-leave="finishLeave"
  >
    <AppDialogLayout
      title="Lịch sử tạo content"
      :subtitle="`${displayItem?.title || 'Content AI'} · ${versions.length} phiên bản`"
      @close="close"
    >
      <VCardText>
        <VList lines="two">
          <VListItem
            v-for="version in versions"
            :key="version.id"
            :title="`Phiên bản ${version.versionNo || version.generationNo || 1}`"
            :subtitle="`${version.title || 'Chưa có tiêu đề'} · ${version.model || 'Model mặc định'} · ${dateLabel(version.createdAt)}`"
          >
            <template #prepend>
              <VAvatar
                color="primary"
                variant="tonal"
                size="34"
              >
                <VIcon :icon="version.id === displayItem?.currentVersionId ? 'tabler-check' : 'tabler-file-text'" />
              </VAvatar>
            </template>
            <template #append>
              <div class="d-flex align-center gap-2">
                <VChip
                  v-if="qualityScore(version) !== null"
                  color="info"
                  size="x-small"
                  variant="tonal"
                  prepend-icon="tabler-star"
                >
                  Điểm {{ qualityScore(version).toFixed(1) }}/5
                </VChip>
                <VChip
                  size="small"
                  variant="tonal"
                  :color="version.status === 'failed' ? 'error' : version.id === displayItem?.currentVersionId ? 'success' : 'secondary'"
                >
                  {{ version.id === displayItem?.currentVersionId ? 'Hiện tại' : versionLabel(version) }}
                </VChip>
                <VBtn
                  v-if="['review', 'applied', 'rejected'].includes(version.status)"
                  size="small"
                  variant="text"
                  prepend-icon="tabler-eye"
                  @click="openVersion(version)"
                >
                  Xem
                </VBtn>
              </div>
            </template>
          </VListItem>
        </VList>
      </VCardText>
    </AppDialogLayout>
  </VDialog>
</template>

<style scoped>
.gap-2 { gap: 8px; }
</style>
