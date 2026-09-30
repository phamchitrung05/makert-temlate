/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử Slug qua AppTextField/Vuetify thật, không giả lập blur.
 * CÁC HÀM/METHOD TRONG FILE: test blur Title -> API -> ô Slug.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): thao tác DOM và API mock -> giá trị field.
 * =====================================================================
 */
import { expect, it, vi } from 'vitest'
import { computed, useAttrs, useId } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createVuetify } from 'vuetify'
import { VTextField, VLabel } from 'vuetify/components' // eslint-disable-line no-restricted-imports
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'
import PostForm from '@/views/apps/blog/post/PostForm.vue'
import { passthroughStubs } from './testStubs'

const api = vi.hoisted(() => vi.fn())

vi.mock('@/utils/api', () => ({ $api: api }))

it('fills the real Slug input after leaving the real Title input', async () => {
  vi.stubGlobal('computed', computed)
  vi.stubGlobal('useAttrs', useAttrs)
  vi.stubGlobal('useId', useId)
  api.mockResolvedValue({ success: true, data: { slug: 'kiem-tra-slug-1', 'model_type': 'post' } })

  const wrapper = mount(PostForm, {
    attachTo: document.body,
    global: {
      plugins: [createPinia(), createVuetify()],
      components: { AppTextField, VTextField, VLabel },
      mocks: { requiredValidator: value => Boolean(value) },
      stubs: {
        ...passthroughStubs(['VForm', 'VRow', 'VCol', 'VCard', 'VCardText', 'VCardItem', 'VAlert', 'VBtnToggle']),
        VBtn: true, VIcon: true, IconBtn: true, VTooltip: true, VProgressLinear: true, MoreBtn: true, VChip: true, VSwitch: true,
        PostSeoTabs: true, PostMediaPanel: true, PostSettingsSidebar: true, PostEditor: true, AppTextarea: true,
      },
    },
  })

  try {
    const title = wrapper.findAllComponents(AppTextField).find(field => field.find('label').text() === 'Post Title')
    const slug = wrapper.findAllComponents(AppTextField).find(field => field.find('label').text() === 'Slug')

    expect(slug.get('.v-field').classes()).toContain('v-field--active')
    expect(slug.get('.v-text-field__prefix').text()).toBe(`${window.location.origin}/`)

    const input = title.get('input')

    input.element.focus()
    await input.setValue('Kiểm tra slug')
    expect(api).not.toHaveBeenCalled()
    input.element.blur()
    await flushPromises()
    expect(api).toHaveBeenCalledTimes(1)
    expect(slug.get('input').element.value).toBe('kiem-tra-slug-1')
  }
  finally { wrapper.unmount() }
})
