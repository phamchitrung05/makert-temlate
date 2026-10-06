<!-- Chẩn đoán read-only: scheduler, capability webhook, phiên bản và dung lượng thật. -->
<script setup>
import { getAlertColor } from '@/config/alertColors'
import { computed, version as vueVersion } from 'vue'
import { version as vuetifyVersion } from 'vuetify'

const props = defineProps({
  tab: { type: String, required: true },
  state: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['reload'])
const data = computed(() => props.state.data)

const systemRows = computed(() => {
  if (!data.value?.runtime) return []
  const { runtime, database, queue } = data.value

  return [
    ['Môi trường', runtime.environment],
    ['Hệ điều hành', runtime.os],
    ['PHP / Laravel', `${runtime.php} / ${runtime.laravel}`],
    ['Vue / Vuetify', `${vueVersion} / ${vuetifyVersion}`],
    ['Cơ sở dữ liệu', `${database.driver} · ${database.status === 'connected' ? 'Kết nối được' : 'Chưa kết nối'} · ${database.version ?? 'Chưa đọc được phiên bản'}`],
    ['Giới hạn bộ nhớ PHP', runtime.memory_limit],
    ['Giới hạn upload / request PHP', `${runtime.upload_max_filesize} / ${runtime.post_max_size}`],
    ['Queue', queue.connection],
    ['Job chờ / Job lỗi', `${queue.pending ?? 'Chưa đo được'} / ${queue.failed ?? 'Chưa đo được'}`],
    ['Debug', runtime.debug ? 'Đang bật' : 'Đã tắt'],
  ]
})

const diskUsed = computed(() => {
  const disk = data.value?.disk
  if (!disk?.total_bytes || disk.free_bytes === null) return null

  return Math.round((disk.total_bytes - disk.free_bytes) / disk.total_bytes * 100)
})


/** Input: bytes. Output: GB hiển thị, không suy diễn quota của project. */
const gigabytes = bytes => (bytes / 1024 ** 3).toFixed(1)
</script>

<template>
  <div
    v-if="state.loading"
    class="d-flex align-center gap-3 py-8"
    role="status"
  >
    <VProgressCircular
      indeterminate
      color="primary"
      size="24"
    />
    <span>Đang tải thông tin...</span>
  </div>
  <VAlert
    v-else-if="state.error"
    :color="getAlertColor('error')"
    variant="tonal"
  >
    {{ state.error }}
    <VBtn
      variant="outlined"
      size="small"
      class="mt-3 d-block"
      @click="emit('reload')"
    >
      Thử lại
    </VBtn>
  </VAlert>
  <template v-else-if="data">
    <template v-if="tab === 'cron'">
      <VAlert
        :color="getAlertColor('info')"
        variant="tonal"
        class="mb-5"
      >
        Lịch này được khai báo trong project. Chưa có lịch sử chạy hoặc tín hiệu theo dõi scheduler để xác nhận lần chạy gần nhất.
      </VAlert>
      <VTable
        v-if="data.items.length"
        class="text-no-wrap"
      >
        <thead><tr><th>Tác vụ</th><th>Lịch chạy</th><th>Múi giờ</th><th>Lần chạy kế tiếp</th></tr></thead>
        <tbody>
          <tr
            v-for="item in data.items"
            :key="item.name"
          >
            <td>
              {{ item.name }}<div class="text-caption text-medium-emphasis">
                {{ item.without_overlapping ? 'Có khóa chống chạy trùng' : 'Không có khóa chống chạy trùng' }}
              </div>
            </td>
            <td><code>{{ item.expression }}</code></td>
            <td>{{ item.timezone }}</td>
            <td>{{ new Date(item.next_run_at).toLocaleString('vi-VN', { timeZone: item.timezone }) }}</td>
          </tr>
        </tbody>
      </VTable>
      <p
        v-else
        class="text-medium-emphasis"
      >
        Chưa có tác vụ định kỳ được đăng ký.
      </p>
    </template>
    <div
      v-else-if="tab === 'webhooks'"
      class="text-center py-8"
    >
      <VAvatar
        color="secondary"
        variant="tonal"
        size="64"
        class="mb-4"
      >
        <VIcon
          icon="tabler-webhook"
          size="30"
        />
      </VAvatar>
      <h3 class="text-h6 mb-2">
        Chưa hỗ trợ Webhooks
      </h3>
      <p class="text-body-1 mb-2">
        {{ data.reason }}
      </p>
      <p class="text-body-2 text-medium-emphasis mb-0">
        Tab sẽ có cấu hình khi luồng gửi, sự kiện và lịch sử giao webhook được triển khai.
      </p>
    </div>
    <template v-else-if="tab === 'system-info'">
      <VAlert
        :color="getAlertColor('info')"
        variant="tonal"
        class="mb-5"
      >
        Số lượng job không xác nhận worker đang chạy. Project chưa có tín hiệu theo dõi hoạt động worker.
      </VAlert>
      <VList
        lines="two"
        class="pa-0"
      >
        <VListItem
          v-for="[label, value] in systemRows"
          :key="label"
          :title="label"
          :subtitle="String(value)"
          class="px-0"
        />
      </VList>
      <VDivider class="my-5" />
      <h3 class="text-h6 mb-2">
        Ổ đĩa chứa dữ liệu ứng dụng
      </h3>
      <template v-if="diskUsed !== null">
        <p class="text-body-2">
          Còn {{ gigabytes(data.disk.free_bytes) }} / {{ gigabytes(data.disk.total_bytes) }} GB · Đã dùng {{ diskUsed }}%
        </p>
        <VProgressLinear
          :model-value="diskUsed"
          color="primary"
          rounded
          height="8"
        />
        <p class="text-caption text-medium-emphasis mt-2">
          Dung lượng của toàn ổ đĩa, không phải dung lượng riêng của project.
        </p>
      </template>
      <p v-else>
        Không đọc được dung lượng ổ đĩa.
      </p>
      <p class="text-caption text-medium-emphasis mt-4 mb-0">
        Cập nhật lúc {{ new Date(data.checked_at).toLocaleString('vi-VN') }}
      </p>
    </template>
  </template>
</template>
