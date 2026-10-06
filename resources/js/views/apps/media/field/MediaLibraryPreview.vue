<!--
  =====================================================================
  CHỨC NĂNG FILE: Xem trước file và metadata đã lưu trong Media Library.
  =====================================================================
  Dùng token màu của project; chỉ trình bày dữ liệu thực từ asset.
  CÁC HÀM/METHOD TRONG FILE:
  - formatBytes()/formatDate(): định dạng kích thước và ngày tải lên.
  - file/previewUrl/fileUrl/information: computed metadata an toàn từ props.
  - copyFileUrl(): sao chép URL đã được API cấp vào clipboard.
  - watcher asset.id: reset feedback khi đổi file đang xem.
  INPUT/OUTPUT CỦA CLASS (tổng thể):
  - INPUT : asset đang xem và trạng thái được chọn từ dialog cha.
  - OUTPUT: preview và thông tin; không sửa hoặc lưu asset.
  - SIDE EFFECT: ghi clipboard khi người dùng nhấn sao chép.
  =====================================================================
-->
<script setup>
import { computed, shallowRef, watch } from 'vue'

const props = defineProps({
  asset: { type: Object, default: null },
  selected: { type: Boolean, default: false },
})

const copied = shallowRef(false)
const file = computed(() => props.asset?.file ?? {})
const previewUrl = computed(() => file.value.preview_url || file.value.url || null)
const fileUrl = computed(() => file.value.url || file.value.preview_url || '')
const kindLabels = { image: 'Ảnh', video: 'Video', document: 'Tài liệu', archive: 'File nén' }
const statusLabels = { pending: 'Đang chờ', processing: 'Đang xử lý', clean: 'An toàn', ready: 'Sẵn sàng', rejected: 'Bị từ chối', error: 'Lỗi', failed: 'Thất bại' }

/** Input: số byte. Output: kích thước dễ đọc hoặc dấu gạch khi thiếu dữ liệu. */
const formatBytes = bytes => {
  const size = Number(bytes)
  if (!Number.isFinite(size) || size <= 0) return '—'
  const units = ['B', 'KB', 'MB', 'GB']
  const index = Math.min(Math.floor(Math.log(size) / Math.log(1024)), units.length - 1)

  return `${(size / 1024 ** index).toFixed(index === 0 ? 0 : 1)} ${units[index]}`
}

/** Input: ISO timestamp. Output: ngày vi-VN; dữ liệu không hợp lệ trả dấu gạch. */
const formatDate = value => {
  if (!value || Number.isNaN(new Date(value).getTime())) return '—'

  return new Intl.DateTimeFormat('vi-VN', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

const information = computed(() => [
  { label: 'Loại file', value: kindLabels[props.asset?.kind] || props.asset?.kind || '—' },
  { label: 'Định dạng', value: file.value.mime_type || '—' },
  { label: 'Dung lượng', value: formatBytes(file.value.size) },
  { label: 'Kích thước', value: file.value.width && file.value.height ? `${file.value.width} × ${file.value.height}` : '—' },
  { label: 'Hiển thị', value: props.asset?.visibility === 'public' ? 'Công khai' : props.asset?.visibility === 'private' ? 'Riêng tư' : '—' },
  { label: 'Ngày tải lên', value: formatDate(props.asset?.created_at) },
  ...(!file.value.scan_status || ['pending', 'ready', 'clean'].includes(file.value.scan_status) ? []
    : [{ label: 'Kiểm tra file', value: statusLabels[file.value.scan_status] || file.value.scan_status }]),
  ...(!file.value.conversion_status || ['pending', 'ready'].includes(file.value.conversion_status) ? []
    : [{ label: 'Xử lý file', value: statusLabels[file.value.conversion_status] || file.value.conversion_status }]),
])

/** Input: đổi asset. Output: reset thông báo sao chép, không mutate props. */
watch(() => props.asset?.id, () => { copied.value = false })

/** Input: URL đã cấp. Output: ghi clipboard và feedback khi browser cho phép. */
const copyFileUrl = async () => {
  if (!fileUrl.value || !navigator.clipboard?.writeText) return
  try {
    await navigator.clipboard.writeText(fileUrl.value)
    copied.value = true
  }
  catch {
    copied.value = false
  }
}
</script>

<template>
  <div
    v-if="!props.asset"
    class="media-preview-empty"
  >
    <VAvatar
      size="64"
      color="secondary"
      variant="tonal"
      rounded="lg"
      class="mb-4"
    >
      <VIcon
        icon="tabler-photo"
        size="30"
      />
    </VAvatar>
    <h3 class="text-subtitle-1 mb-2">
      Xem trước media
    </h3>
    <p class="text-body-2 text-medium-emphasis mb-0">
      Chọn một file trong thư viện để xem hình ảnh và thông tin chi tiết.
    </p>
  </div>
  <div v-else>
    <div class="d-flex align-center justify-space-between gap-2 mb-4">
      <h3 class="text-subtitle-2">
        Thông tin file
      </h3>
      <VChip
        v-if="props.selected"
        color="primary"
        size="small"
        prepend-icon="tabler-check"
      >
        Đã chọn
      </VChip>
    </div>
    <div class="media-preview-image mb-4">
      <VImg
        v-if="previewUrl && props.asset.kind === 'image'"
        :src="previewUrl"
        :alt="props.asset.alt_text || props.asset.title"
        height="180"
        contain
      />
      <VIcon
        v-else
        :icon="props.asset.kind === 'video' ? 'tabler-video' : props.asset.kind === 'archive' ? 'tabler-file-zip' : 'tabler-file-text'"
        size="48"
        color="secondary"
      />
    </div>
    <div class="text-subtitle-1 media-preview-title mb-1">
      {{ props.asset.title }}
    </div>
    <div class="text-caption text-medium-emphasis media-preview-title mb-5">
      {{ file.original_name || file.file_name }}
    </div>
    <dl class="media-preview-information mb-5">
      <div
        v-for="item in information"
        :key="item.label"
        class="media-preview-information-row"
      >
        <dt class="text-body-2 text-medium-emphasis">
          {{ item.label }}
        </dt>
        <dd class="text-body-2">
          {{ item.value }}
        </dd>
      </div>
    </dl>
    <div
      v-if="props.asset.alt_text"
      class="mb-4"
    >
      <div class="text-body-2 text-medium-emphasis mb-1">
        Văn bản thay thế
      </div>
      <p class="text-body-2 media-preview-title mb-0">
        {{ props.asset.alt_text }}
      </p>
    </div>
    <div
      v-if="props.asset.caption"
      class="mb-4"
    >
      <div class="text-body-2 text-medium-emphasis mb-1">
        Chú thích
      </div>
      <p class="text-body-2 media-preview-title mb-0">
        {{ props.asset.caption }}
      </p>
    </div>
    <div
      v-if="props.asset.description"
      class="mb-4"
    >
      <div class="text-body-2 text-medium-emphasis mb-1">
        Mô tả
      </div>
      <p class="text-body-2 media-preview-title mb-0">
        {{ props.asset.description }}
      </p>
    </div>
    <AppTextField
      v-if="fileUrl"
      :model-value="fileUrl"
      label="Liên kết file"
      readonly
      hide-details
    >
      <template #append-inner>
        <VBtn
          :icon="copied ? 'tabler-check' : 'tabler-copy'"
          variant="text"
          size="x-small"
          :aria-label="copied ? 'Đã sao chép liên kết' : 'Sao chép liên kết file'"
          @click="copyFileUrl"
        />
      </template>
    </AppTextField>
  </div>
</template>

<style scoped>
.media-preview-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  block-size: 100%;
  min-block-size: 320px;
  text-align: center;
}

.media-preview-image {
  display: flex;
  overflow: hidden;
  align-items: center;
  justify-content: center;
  border: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
  background: rgb(var(--v-theme-background));
  block-size: 180px;
}

.media-preview-image :deep(.v-img) {
  inline-size: 100%;
}

.media-preview-title {
  overflow-wrap: anywhere;
}

.media-preview-information-row {
  display: grid;
  border-block-end: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
  gap: 12px;
  grid-template-columns: 92px minmax(0, 1fr);
  padding-block: 10px;
}

.media-preview-information-row dd {
  margin: 0;
  overflow-wrap: anywhere;
  text-align: end;
}
</style>
