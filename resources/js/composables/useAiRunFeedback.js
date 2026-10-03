import { shallowRef } from 'vue'
import { formatAiError } from '@/utils/aiErrors'

/** Feedback belongs to observed runs; loading the saved list never calls this helper. */
export function useAiRunFeedback() {
  const snackbar = shallowRef({ visible: false, message: '', type: 'error' })
  const observedStatuses = new Map()

  function showSnackbar(message, type = 'error') {
    snackbar.value = { visible: true, message, type }
  }

  function setSnackbarVisible(visible) {
    snackbar.value = { ...snackbar.value, visible }
  }

  function observeRun(run, fallback, outputOptions) {
    const id = run?.job_id ?? run?.id
    if (!id) return
    const previous = observedStatuses.get(id)

    observedStatuses.set(id, run.status)
    if (run.status === 'failed' && previous !== 'failed')
      showSnackbar(formatAiError(run, fallback, outputOptions))
  }

  return { snackbar, showSnackbar, setSnackbarVisible, observeRun }
}
