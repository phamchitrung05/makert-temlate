/**
 * =====================================================================
 * CHỨC NĂNG FILE: Xác nhận bỏ bản sửa cho hai dialog tạo/sửa văn phong.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: useAiPromptDialogGuard(), requestClose(), requestReload(),
 * acceptDiscard(), cancelDiscard(), finishDiscard().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): getter state/emit -> thao tác đóng/tải lại
 * và state xác nhận độc lập; giữ nội dung xác nhận đến hết hiệu ứng đóng.
 * =====================================================================
 */
import { ref } from 'vue'

export function useAiPromptDialogGuard(getState, emit) {
  const discardOpen = ref(false)
  const discardAction = ref('')
  const discardUncertain = ref(false)

  // =====================================================================
  // Input: yêu cầu đóng. Output: xác nhận bản chưa lưu hoặc emit close.
  // =====================================================================
  function requestClose() {
    const state = getState()

    if (!state.isOpen || state.saving) return
    if (state.dirty || state.uncertain) {
      discardAction.value = 'close'
      discardUncertain.value = state.uncertain
      discardOpen.value = true
    }
    else emit('close')
  }

  // =====================================================================
  // Input: yêu cầu tải phiên bản mới. Output: xác nhận bỏ bản sửa trước GET.
  // =====================================================================
  function requestReload() {
    const state = getState()

    if (!state.isOpen || state.saving || state.loading || state.uncertain) return
    if (state.dirty) {
      discardAction.value = 'load'
      discardUncertain.value = false
      discardOpen.value = true
    }
    else emit('load')
  }

  // =====================================================================
  // Input: đồng ý bỏ bản sửa. Output: đóng xác nhận, emit thao tác đã chọn.
  // =====================================================================
  function acceptDiscard() {
    if (getState().saving) return
    discardOpen.value = false
    emit(discardAction.value)
  }

  // =====================================================================
  // Input: hủy xác nhận. Output: giữ nguyên form và đóng dialog phụ.
  // =====================================================================
  function cancelDiscard() { discardOpen.value = false }

  // =====================================================================
  // Input: after-leave. Output: dọn nội dung xác nhận khi đã đóng xong.
  // =====================================================================
  function finishDiscard() {
    if (discardOpen.value) return
    discardAction.value = ''
    discardUncertain.value = false
  }

  return { discardOpen, discardAction, discardUncertain, requestClose, requestReload, acceptDiscard, cancelDiscard, finishDiscard }
}
