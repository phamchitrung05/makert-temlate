/**
 * Preference locale dùng chung menu và Settings. Input: registry API; output: locale thật.
 * load/apply refresh danh sách ngôn ngữ, không tự bật bản dịch còn thiếu.
 */
import { shallowRef } from 'vue'
import { settingsService } from '@/services/settings'
import { getI18n, hasLanguagePreference } from '@/plugins/i18n'

const languages = shallowRef(null)
let pending = null

export function useSettingsLocales() {
  /** Input: DTO languages/locales. Output: menu chỉ locale enabled và default có bản dịch. */
  function apply(data) {
    const settings = data.languages
    const firstLoad = languages.value === null

    languages.value = (data.locales ?? []).filter(locale => settings.enabled_locales.includes(locale.value))
      .map(locale => ({ label: locale.title, i18nLang: locale.value, isRTL: locale.rtl }))

    const i18n = getI18n()

    if ((!hasLanguagePreference && firstLoad) || !languages.value.some(locale => locale.i18nLang === i18n.global.locale.value))
      i18n.global.locale.value = settings.default_locale
  }

  /** Load registry an toàn; không chặn trang nếu preference API lỗi. */
  async function load() {
    if (pending) return pending
    pending = settingsService.preferences().then(apply).catch(() => {}).finally(() => { pending = null })

    return pending
  }

  return { languages, apply, load }
}
