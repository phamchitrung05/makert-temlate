/* eslint-disable camelcase -- DTO dùng tên field backend. */
/** Kiểm locale default/disabled; API mock, không sửa cookie hoặc trình duyệt người dùng. */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { locale, api } = vi.hoisted(() => ({ locale: { value: 'en' }, api: { preferences: vi.fn() } }))

vi.mock('@/services/settings', () => ({ settingsService: api }))
vi.mock('@/plugins/i18n', () => ({ getI18n: () => ({ global: { locale } }), hasLanguagePreference: false }))

const data = (enabled, defaultLocale) => ({
  languages: { enabled_locales: enabled, default_locale: defaultLocale },
  locales: [
    { value: 'en', title: 'English', rtl: false },
    { value: 'fr', title: 'Français', rtl: false },
    { value: 'ar', title: 'العربية', rtl: true },
  ],
})

describe('Settings locale registry runtime', () => {
  beforeEach(() => {
    vi.resetModules()
    locale.value = 'en'
  })

  it('applies saved default to a visitor without a previous language preference', async () => {
    const { useSettingsLocales } = await import('@/composables/useSettingsLocales')
    const state = useSettingsLocales()

    state.apply(data(['en', 'fr'], 'fr'))
    expect(locale.value).toBe('fr')
    expect(state.languages.value.map(item => item.i18nLang)).toEqual(['en', 'fr'])
  })

  it('keeps an enabled choice on refresh and falls back when it is disabled', async () => {
    const { useSettingsLocales } = await import('@/composables/useSettingsLocales')
    const state = useSettingsLocales()

    state.apply(data(['en', 'fr'], 'en'))
    locale.value = 'fr'
    state.apply(data(['en', 'fr'], 'en'))
    expect(locale.value).toBe('fr')
    state.apply(data(['ar'], 'ar'))
    expect(locale.value).toBe('ar')
    expect(state.languages.value).toEqual([{ label: 'العربية', i18nLang: 'ar', isRTL: true }])
  })
})
