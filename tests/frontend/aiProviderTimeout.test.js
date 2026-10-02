/* eslint-disable camelcase -- Form giữ DTO Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử thời gian chờ trong dialog provider AI.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: renderDialog(), test default/edit/validation.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): metadata/config và thao tác -> payload số.
 * SIDE EFFECT: Mount Vue với UI stubs; không HTTP hoặc ghi secret thật.
 * =====================================================================
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AiProviderConnectionDialog from '@/views/settings/ai/AiProviderConnectionDialog.vue'

/** Input: props dialog. Output: wrapper có input/button HTML để kiểm tra validation và save. */
function renderDialog(props = {}) {
  const passthrough = { template: '<div><slot /></div>' }

  return mount(AiProviderConnectionDialog, {
    props: { modelValue: true, presets: [{ key: 'openai-compatible', request_timeout: 240 }], ...props },
    global: { stubs: {
      VDialog: passthrough, VCard: passthrough, VCardTitle: passthrough,
      VCardText: passthrough, VCardActions: passthrough, VSelect: true, VSwitch: true,
      VTextField: {
        props: ['modelValue', 'label', 'type'], emits: ['update:modelValue'],
        template: '<input :value="modelValue" :type="type" :aria-label="label" @input="$emit(\'update:modelValue\', $event.target.value)">',
      },
      VBtn: {
        props: ['disabled'], emits: ['click'],
        template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
      },
    } },
  })
}

describe('AI provider timeout form', () => {
  it('uses the configured default and emits a numeric timeout', async () => {
    const wrapper = renderDialog()
    const field = wrapper.get('[aria-label="Thời gian chờ (giây)"]')

    expect(field.element.value).toBe('240')
    await field.setValue('600')
    await wrapper.findAll('button')[1].trigger('click')
    expect(wrapper.emitted('save')[0][0].request_timeout).toBe(600)
    expect(wrapper.emitted('save')[0][0]).not.toHaveProperty('api_key')
    wrapper.unmount()
  })

  it('preserves a saved timeout when editing and resets it from metadata when reopened', async () => {
    const provider = { name: 'Gateway', driver: 'openai-compatible', request_timeout: 300, has_api_key: true }
    const wrapper = renderDialog({ provider })
    const field = wrapper.get('[aria-label="Thời gian chờ (giây)"]')

    expect(field.element.value).toBe('300')
    await field.setValue('180')
    await wrapper.setProps({ modelValue: false })
    await wrapper.setProps({ modelValue: true })
    expect(field.element.value).toBe('300')
    wrapper.unmount()
  })

  it('blocks empty, fractional or out-of-range timeout and duplicate save while saving', async () => {
    const wrapper = renderDialog()
    const field = wrapper.get('[aria-label="Thời gian chờ (giây)"]')
    const save = wrapper.findAll('button')[1]

    for (const invalid of ['', '4', '601', '5.5']) {
      await field.setValue(invalid)
      expect(save.element.disabled).toBe(true)
      await save.trigger('click')
    }
    expect(wrapper.emitted('save')).toBeUndefined()
    await field.setValue('5')
    expect(save.element.disabled).toBe(false)
    await wrapper.setProps({ saving: true })
    expect(save.element.disabled).toBe(true)
    wrapper.unmount()
  })
})
