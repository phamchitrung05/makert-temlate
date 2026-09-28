import { describe, expect, it } from 'vitest'
import { defineComponent } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import ResourceForm from '@/views/apps/ecommerce/resource/ResourceForm.vue'
import { passthroughStubs } from './testStubs'

const VFormStub = defineComponent({
  emits: ['submit'],
  setup(_, { emit, expose }) {
    const validate = () => Promise.resolve({ valid: true })
    const onSubmit = event => emit('submit', event)

    expose({ validate })

    return { onSubmit }
  },
  template: '<form @submit="onSubmit"><slot /></form>',
})

const VBtnStub = {
  inheritAttrs: false,
  props: { disabled: Boolean },
  emits: ['click'],
  template: '<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
}

const formPassthroughs = passthroughStubs([
  'VAlert',
  'VProgressLinear',
  'AppTextField',
  'VCol',
  'AppSelect',
  'AppTextarea',
  'VRow',
  'VCardText',
  'VCard',
  'VCheckbox',
])

describe('ResourceForm media state', () => {
  it('keeps cover and preview selections when the page reports an API error', async () => {
    const cover = { id: 11, title: 'Cover', kind: 'image' }
    const preview = [{ id: 12, title: 'Preview', kind: 'image' }]

    const wrapper = mount(ResourceForm, {
      props: {
        isEdit: true,
        error: '',
        resource: {
          title: 'Existing resource',
          media: { cover, preview },
        },
      },
      global: {
        stubs: {
          ...formPassthroughs,
          VForm: VFormStub,
          VBtn: VBtnStub,
          MediaAssetField: true,
        },
        config: {
          globalProperties: {
            requiredValidator: () => true,
          },
        },
      },
    })

    await wrapper.setProps({ error: 'Không thể lưu resource.' })
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const submission = wrapper.emitted('submit')?.[0]?.[0]

    expect(submission.action).toBe('draft')
    expect(submission.payload.cover).toEqual(cover)
    expect(submission.payload.preview).toEqual(preview)
    expect(wrapper.text()).toContain('Không thể lưu resource.')
  })
})
