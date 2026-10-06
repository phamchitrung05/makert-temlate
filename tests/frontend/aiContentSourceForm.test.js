/* eslint-disable camelcase -- Catalog fixtures follow the Laravel API contract. */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AiContentSourceForm from '@/views/ai/content/AiContentSourceForm.vue'
import { createAiContentSource } from '@/utils/aiContentInput'

const Passthrough = { template: '<div><slot /></div>' }

const Select = {
  props: {
    modelValue: [Array, String, Number], items: Array, label: String,
    disabled: Boolean, multiple: Boolean,
  },
  emits: ['update:modelValue'],
  template: `<label>{{ label }}<select :aria-label="label" :disabled="disabled" :multiple="multiple" :value="modelValue"
    @change="$emit('update:modelValue', multiple ? Array.from($event.target.selectedOptions).map(option => option.value) : $event.target.value)">
    <option v-for="item in items" :key="item.value" :value="item.value" :disabled="item.props?.disabled">{{ item.title }}</option>
  </select></label>`,
}

const TextField = { props: ['modelValue', 'label'], template: '<label>{{ label }}<input :aria-label="label" :value="modelValue"></label>' }

function renderForm() {
  return mount(AiContentSourceForm, {
    props: {
      modelValue: createAiContentSource({ outputs: ['title', 'content'] }),
      catalog: {
        loading: false, error: '', providerOptions: [], modelOptions: [],
        outputOptions: [
          { value: 'title', title: 'Tiêu đề từ cấu hình' },
          { value: 'content', title: 'Nội dung từ cấu hình' },
          { value: 'taxonomy', title: 'Danh mục & tags từ cấu hình' },
          { value: 'thumbnail', title: 'Thumbnail từ nguồn URL', props: { disabled: true } },
        ],
      },
    },
    global: { stubs: {
      AiWritingPreferences: true, AiManualTaxonomyFields: true, AiSourcePreview: true,
      VTabs: Passthrough, VTab: Passthrough, VWindow: Passthrough, VWindowItem: Passthrough,
      VExpansionPanels: Passthrough, VExpansionPanel: Passthrough, VExpansionPanelTitle: Passthrough,
      VExpansionPanelText: Passthrough, VAlert: Passthrough, VRow: Passthrough, VCol: Passthrough,
      VBtn: Passthrough, VIcon: true, VFileInput: true, AppTextarea: true,
      AppSelect: Select, AppTextField: TextField,
    } },
  })
}

describe('Ai Content output tags', () => {
  it('exposes AI mode and a separate image selector without changing text model selection', async () => {
    const wrapper = renderForm()

    await wrapper.get('select[aria-label="Cách tạo ảnh đại diện"]').setValue('generate')

    const value = wrapper.emitted('update:modelValue').at(-1)[0]

    expect(value.thumbnailMode).toBe('generate')
    await wrapper.setProps({ modelValue: value, catalog: { ...wrapper.props('catalog'), imageModelOptions: [{ title: 'Images · Fixture', value: 91 }] } })
    expect(wrapper.get('select[aria-label="Model tạo ảnh"]').text()).toContain('Images · Fixture')
    expect(wrapper.get('select[aria-label="Model"]').element.value).toBe('')
    wrapper.unmount()
  })
  it('uses config labels in one multiple select and emits the exact selected groups', async () => {
    const wrapper = renderForm()
    const select = wrapper.get('select[aria-label="AI sẽ tạo"]')

    expect(select.element.multiple).toBe(true)
    expect(select.findAll('option').map(option => option.text())).toEqual([
      'Tiêu đề từ cấu hình', 'Nội dung từ cấu hình', 'Danh mục & tags từ cấu hình', 'Thumbnail từ nguồn URL',
    ])
    expect(select.get('option[value="thumbnail"]').element.disabled).toBe(true)
    await select.setValue(['content', 'taxonomy'])
    expect(wrapper.emitted('update:modelValue').at(-1)[0].outputs).toEqual(['content', 'taxonomy'])
    wrapper.unmount()
  })

  it('shows a manual title field when the title tag is removed', async () => {
    const wrapper = renderForm()

    expect(wrapper.find('input[aria-label="Tiêu đề tài nguyên"]').exists()).toBe(false)
    await wrapper.get('select[aria-label="AI sẽ tạo"]').setValue(['content'])
    await wrapper.setProps({ modelValue: wrapper.emitted('update:modelValue').at(-1)[0] })
    expect(wrapper.find('input[aria-label="Tiêu đề tài nguyên"]').exists()).toBe(true)
    await wrapper.setProps({ modelValue: { ...wrapper.props('modelValue'), outputs: ['title', 'content'] } })
    expect(wrapper.find('input[aria-label="Tiêu đề tài nguyên"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
