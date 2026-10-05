/* eslint-disable vue/one-component-per-file -- Harness dùng để kiểm contract UI công khai. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Regression khung dialog và submit từ footer ngoài form.
 * CÁC HÀM/METHOD TRONG FILE: render(), button(), afterEach() và các ca slots,
 * khóa đóng, footer mặc định, hai form độc lập và submit đúng một lần.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): Vue/Vuetify thật + thao tác DOM -> vùng
 * header/body/footer và public events; không đọc state private của component.
 * SIDE EFFECT: mount DOM local; không gọi HTTP/model hoặc sửa database.
 * =====================================================================
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h, ref, toRaw, watch } from 'vue'
import { createVuetify } from 'vuetify'
import { VBtn } from 'vuetify/components/VBtn'
import { VCard, VCardActions, VCardItem, VCardSubtitle, VCardText, VCardTitle } from 'vuetify/components/VCard'
import { VDivider } from 'vuetify/components/VDivider'
import { VForm } from 'vuetify/components/VForm'
import { VCol, VRow } from 'vuetify/components/VGrid'
import { VIcon } from 'vuetify/components/VIcon'
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import CardAddEditDialog from '@/components/dialogs/CardAddEditDialog.vue'

let wrapper

// Input: component/slots/props. Output: wrapper với layout và control Vuetify thật.
function render(component, options = {}) {
  wrapper = mount(component, {
    attachTo: document.body,
    ...options,
    global: {
      plugins: [createVuetify({ aliases: { IconBtn: VBtn }, components: { VBtn, VCard, VCardActions, VCardItem, VCardSubtitle, VCardText, VCardTitle, VDivider, VForm, VCol, VRow, VIcon } })],
      stubs: {
        VDialog: { template: '<section><slot /></section>' },
        VSwitch: true,
        AppTextField: {
          props: ['modelValue', 'label'], emits: ['update:modelValue'],
          template: '<input :value="modelValue" :aria-label="label" @input="$emit(\'update:modelValue\', $event.target.value)">',
        },
      },
    },
  })

  return wrapper
}

// Input: nhãn public. Output: nút DOM đúng trong wrapper hiện tại.
const button = label => wrapper.findAll('button').find(item => item.text() === label)

afterEach(() => { wrapper?.unmount() })

describe('App dialog layout', () => {
  it('keeps headings and actions outside long form content and preserves card attributes', () => {
    render(AppDialogLayout, {
      props: { title: 'Chỉnh sửa bài', subtitle: 'Kiểm tra trước khi lưu' },
      attrs: { id: 'editing-dialog' },
      slots: { default: '<form><textarea aria-label="Nội dung" /></form>', footer: '<button>Lưu thay đổi</button>' },
    })

    expect(wrapper.get('#editing-dialog').exists()).toBe(true)
    expect(wrapper.get('header').text()).toContain('Chỉnh sửa bài')
    expect(wrapper.get('header').text()).toContain('Kiểm tra trước khi lưu')
    expect(wrapper.get('.app-dialog-layout__body').find('textarea').exists()).toBe(true)
    expect(wrapper.get('.app-dialog-layout__body').find('header').exists()).toBe(false)
    expect(wrapper.get('.app-dialog-layout__body').find('button').exists()).toBe(false)
    expect(wrapper.get('footer').text()).toBe('Lưu thay đổi')
    expect(button('Đóng')).toBeUndefined()
  })

  it('provides a close footer and honors the caller close lock for both close controls', async () => {
    render(AppDialogLayout, { props: { title: 'Chi tiết', closeLabel: 'Đóng chi tiết', closeDisabled: true } })

    const close = wrapper.get('button[aria-label="Đóng chi tiết"]')

    expect(close.element.disabled).toBe(true)
    expect(button('Đóng').element.disabled).toBe(true)
    await close.trigger('click')
    await button('Đóng').trigger('click')
    expect(wrapper.emitted('close')).toBeUndefined()
    await wrapper.setProps({ closeDisabled: false })
    await close.trigger('click')
    await button('Đóng').trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(2)
  })

  it('supports a custom header and keeps the floating close button outside the clipped card', () => {
    render(AppDialogLayout, { slots: { header: '<h2>Chọn media</h2>', default: '<p>Danh sách media</p>' } })

    expect(wrapper.get('header').find('h2').text()).toBe('Chọn media')

    const close = wrapper.get('button[aria-label="Đóng dialog"]')

    expect(close.classes()).toContain('v-dialog-close-btn')
    expect(close.element.closest('.v-card')).toBeNull()
    expect(close.element.parentElement).toBe(wrapper.get('.app-dialog-layout').element.parentElement)
    expect(wrapper.get('header').find('button[aria-label="Đóng dialog"]').exists()).toBe(false)
    expect(wrapper.get('.app-dialog-layout__body').text()).toBe('Danh sách media')
    expect(wrapper.get('footer').text()).toBe('Đóng')
  })

  it('submits the correct form exactly once from its fixed footer and through form submission', async () => {
    vi.stubGlobal('ref', ref)
    vi.stubGlobal('watch', watch)
    vi.stubGlobal('toRaw', toRaw)

    const Harness = defineComponent({
      setup: () => () => h('main', [h(CardAddEditDialog, { isDialogVisible: true }), h(CardAddEditDialog, { isDialogVisible: true })]),
    })

    render(Harness)

    const dialogs = wrapper.findAllComponents(CardAddEditDialog)
    const forms = wrapper.findAll('form')

    expect(forms[0].attributes('id')).not.toBe(forms[1].attributes('id'))

    const save = dialogs[0].get('footer button[type="submit"]')

    expect(save.element.closest('form')).toBeNull()
    expect(save.element.form).toBe(forms[0].element)
    await dialogs[0].get('input[aria-label="Name"]').setValue('Tên thẻ thử nghiệm')
    save.element.click()
    await flushPromises()
    expect(dialogs[0].emitted('submit')).toHaveLength(1)
    expect(dialogs[0].emitted('submit')[0][0].name).toBe('Tên thẻ thử nghiệm')
    expect(dialogs[1].emitted('submit')).toBeUndefined()
    await forms[1].trigger('submit')
    await flushPromises()
    expect(dialogs[1].emitted('submit')).toHaveLength(1)
    expect(dialogs[0].emitted('submit')).toHaveLength(1)
  })
})
