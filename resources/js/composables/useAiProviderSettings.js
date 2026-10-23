/**
 * =====================================================================
 * CHỨC NĂNG FILE: Wrapper tương thích — ủy quyền state AI Providers cho aiCatalog store.
 * =====================================================================
 * Giữ nguyên signature cũ (providers/presets/writingProfiles/settings/
 * modelOptions/loading/saving/error + các action) để các consumer hiện tại
 * (settings/ai-providers.vue, StyleAnalyzeDialog, VisualStyleCreateDialog)
 * không phải sửa. State thực tế giờ nằm trong Pinia store `aiCatalog` — chia
 * sẻ một catalog giữa mọi màn hình thay vì mỗi nơi fetch riêng.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : cùng signature useAiProviderSettings() cũ.
 * - OUTPUT: reactive refs trỏ vào store + action ủy quyền; load() gọi store.
 * =====================================================================
 */
import { storeToRefs } from 'pinia'
import { useAiCatalogStore } from '@/stores/aiCatalog'

export function useAiProviderSettings() {
  const store = useAiCatalogStore()

  const {
    providers,
    presets,
    writingProfiles,
    settings,
    isLoading: loading,
    isSaving: saving,
    error,
    modelOptions,
  } = storeToRefs(store)

  return {
    providers,
    presets,
    writingProfiles,
    settings,
    modelOptions,
    loading,
    saving,
    error,
    load: store.load,
    saveProvider: store.saveProvider,
    deleteProvider: store.deleteProvider,
    disableProvider: store.disableProvider,
    testProvider: store.testProvider,
    syncProvider: store.syncProvider,
    saveModel: store.saveModel,
    updateSettings: store.updateSettings,
  }
}
