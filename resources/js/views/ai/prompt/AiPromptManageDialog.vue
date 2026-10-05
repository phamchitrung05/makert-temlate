<!--
  =====================================================================
  CHỨC NĂNG FILE: Form CRUD văn phong từ List và xác nhận bỏ bản chưa lưu.
  =====================================================================
  CÁC HÀM/METHOD TRONG FILE: editing (computed), requestClose(), requestReload(), acceptDiscard().
  INPUT/OUTPUT CỦA CLASS (tổng thể): management state -> emit save/confirm/load/close
  và form mới; dùng lại form duyệt, không cần analysis để tạo mẫu thủ công.
  =====================================================================
-->
<script setup>
import { computed, ref } from 'vue'
import AiPromptProfileForm from './AiPromptProfileForm.vue'

const props = defineProps({ state: { type: Object, required: true } })
const emit = defineEmits(['save', 'confirm', 'load', 'close', 'updateForm'])
const discard = ref('')
const editing = computed(() => ['edit', 'create'].includes(props.state.action?.kind))

/**
 * =====================================================================
 * Input: đóng dialog. Output: xác nhận khi form dirty/POST chưa rõ; không bỏ thầm.
 * =====================================================================
 */
function requestClose() {
  if (props.state.saving) return
  if (editing.value && (props.state.dirty || props.state.uncertain)) discard.value = 'close'
  else emit('close')
}

/**
 * =====================================================================
 * Input: tải version mới. Output: xác nhận bỏ bản sửa trước GET server.
 * =====================================================================
 */
function requestReload() {
  if (editing.value && props.state.dirty) discard.value = 'load'
  else emit('load')
}

/**
 * =====================================================================
 * Input: người dùng xác nhận. Output: close/load đã chọn, xóa dialog phụ.
 * =====================================================================
 */
function acceptDiscard() { emit(discard.value); discard.value = '' }
</script>

<template>
  <VDialog
    :model-value="Boolean(props.state.action)"
    max-width="1000"
    scrollable
    :persistent="props.state.saving"
    @update:model-value="!$event && requestClose()"
  >
    <DialogCloseBtn
      :disabled="props.state.saving"
      aria-label="Đóng quản lý văn phong"
      @click="requestClose"
    />
    <VCard :title="props.state.action?.kind === 'create' ? 'Tạo văn phong thủ công' : props.state.action?.kind === 'delete' ? 'Xóa văn phong' : props.state.action?.kind === 'toggle' ? 'Bật/tắt văn phong' : 'Chỉnh sửa văn phong'">
      <VCardText>
        <VProgressLinear
          v-if="props.state.loading"
          indeterminate
          class="mb-4"
        />
        <VAlert
          v-if="props.state.error"
          type="error"
          variant="tonal"
          class="mb-4"
        >
          {{ props.state.error }}<VBtn
            v-if="!props.state.uncertain"
            variant="text"
            :disabled="props.state.saving || props.state.loading"
            @click="requestReload"
          >
            Tải phiên bản mới
          </VBtn>
        </VAlert>
        <AiPromptProfileForm
          v-if="editing && !props.state.loading"
          :model-value="props.state.form"
          :busy="props.state.uncertain"
          :saving="props.state.saving"
          :can-save="props.state.canSave"
          :saved-profile="props.state.profile"
          :default-profile-id="props.state.defaultId"
          :default-error="props.state.defaultError"
          :conflict="props.state.conflict"
          :errors="props.state.errors"
          @update:model-value="emit('updateForm', $event)"
          @save="emit('save')"
          @reload-profile="requestReload"
        />
        <template v-else-if="props.state.action && !props.state.loading && props.state.profile">
          <p class="font-weight-medium">
            {{ props.state.profile.name }} · v{{ props.state.profile.version }}
          </p>
          <p v-if="props.state.action.kind === 'delete'">
            Xóa mẫu khỏi danh sách lựa chọn. Các bài đã tạo vẫn giữ snapshot văn phong cũ.
          </p>
          <p v-else>
            {{ props.state.profile.is_enabled ? 'Tắt mẫu để không chọn cho bài mới. Nếu đây là mẫu mặc định, backend sẽ gỡ mặc định.' : 'Bật mẫu để người dùng có thể chọn khi tạo bài mới.' }}
          </p>
          <div class="d-flex justify-end">
            <VBtn
              :disabled="props.state.saving || props.state.conflict"
              :loading="props.state.saving"
              :color="props.state.action.kind === 'delete' ? 'error' : 'primary'"
              @click="emit('confirm')"
            >
              {{ props.state.action.kind === 'delete' ? 'Xóa văn phong' : props.state.profile.is_enabled ? 'Tắt văn phong' : 'Bật văn phong' }}
            </VBtn>
          </div>
        </template>
      </VCardText>
    </VCard>
  </VDialog>
  <VDialog
    :model-value="Boolean(discard)"
    max-width="440"
    @update:model-value="!$event && (discard = '')"
  >
    <VCard title="Bỏ bản đang sửa?">
      <VCardText>{{ props.state.uncertain ? 'Lần tạo mẫu chưa rõ kết quả. Đóng để kiểm tra List; không gửi lại ngay vì có thể đã tạo thành công.' : 'Các thay đổi chưa lưu sẽ bị bỏ. Bạn có thể hủy để sao chép trước.' }}</VCardText>
      <VCardActions>
        <VSpacer /><VBtn
          variant="tonal"
          @click="discard = ''"
        >
          Hủy
        </VBtn><VBtn
          color="warning"
          @click="acceptDiscard"
        >
          {{ discard === 'load' ? 'Tải bản mới' : 'Đóng' }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
