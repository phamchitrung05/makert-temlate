/* eslint-disable camelcase -- Fixture theo contract ResourceVersionResource của Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm form version đọc đúng media/package và release gate từ API.
 * CÁC HÀM/METHOD TRONG FILE: mountForm(), describe()/it() và stub validate().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): DTO version và thao tác submit -> media, payload và nút Ready đúng.
 * =====================================================================
 */
import { describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import ResourceVersionForm from '@/views/apps/ecommerce/resource-version/ResourceVersionForm.vue'
import { passthroughStubs } from './testStubs'

const packageAsset = { id: 11, kind: 'archive', visibility: 'private', file: { scan_status: 'clean' } }
const documentation = [{ id: 12, kind: 'document', title: 'Guide' }]

/** Input: version DTO. Output: component có form thật, stub field/widget, không gọi API ngoài. */
function mountForm(version) {
  return mount(ResourceVersionForm, {
    props: { resourceId: 7, version },
    global: {
      stubs: {
        ...passthroughStubs(['VRow', 'VCol', 'VCard', 'VCardText', 'VAlert', 'AppTextField', 'AppTextarea']),
        VForm: defineComponent({
          emits: ['submit'],
          setup(_, { expose }) {
            expose({ validate: async () => ({ valid: true }) })
          },
          template: '<form @submit.prevent="$emit(\'submit\', $event)"><slot /></form>',
        }),
        VBtn: { props: ['disabled'], template: '<button :disabled="disabled"><slot /></button>' },
        MediaAssetField: { name: 'MediaAssetField', props: ['field', 'modelValue'], template: '<div />' },
        TemplateProcessingPanel: true,
      },
      config: { globalProperties: { requiredValidator: () => true } },
    },
  })
}

describe('ResourceVersionForm API contract', () => {
  it('reads package/documentation from media and keeps selections after a failed save', async () => {
    const wrapper = mountForm({
      id: 1, version: '1.0.0', requirements: { php: '8.2' },
      media: { package: packageAsset, documentation },
    })

    const fields = wrapper.findAllComponents({ name: 'MediaAssetField' })

    expect(fields.find(field => field.props('field') === 'resource_version.package').props('modelValue')).toEqual(packageAsset)
    expect(fields.find(field => field.props('field') === 'resource_version.documentation').props('modelValue')).toEqual(documentation)
    await wrapper.setProps({ error: 'Không thể lưu version.' })
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.emitted('submit')[0][0]).toMatchObject({
      resourceId: 7, version: '1.0.0', requirements: { php: '8.2' }, package: packageAsset, documentation,
    })
    expect(wrapper.text()).toContain('Không thể lưu version.')
  })

  it('uses backend can_ready for templates even when the archive scan is clean', async () => {
    const version = {
      id: 2, version: '2.0.0', resource_type: 'template', media: { package: packageAsset }, capabilities: { can_ready: false },
    }

    const wrapper = mountForm(version)
    const readyButton = () => wrapper.findAll('button').find(button => button.text() === 'Mark Ready')

    expect(readyButton().element.disabled).toBe(true)
    await wrapper.setProps({ version: { ...version, capabilities: { can_ready: true } } })
    expect(readyButton().element.disabled).toBe(false)
    await readyButton().trigger('click')
    expect(wrapper.emitted('ready')).toHaveLength(1)
  })
})
