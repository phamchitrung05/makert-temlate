import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import PostMediaPanel from '@/views/apps/blog/post/PostMediaPanel.vue'
import MediaAssetField from '@/views/apps/media/field/MediaAssetField.vue'
import { passthroughStubs } from './testStubs'

const Button = { props: ['disabled'], template: '<button :disabled="disabled"><slot /></button>' }

const Picker = {
  props: ['open', 'multiple', 'initialSelection'],
  emits: ['select'],
  template: '<div v-if="open"><span>Selected {{ initialSelection.length }}</span><button @click="$emit(\'select\', multiple ? [{ id: 3, title: \'New\' }] : { id: 3, title: \'New\' })">Confirm picker</button></div>',
}

const global = {
  plugins: [createPinia()],
  stubs: { ...passthroughStubs(['VCard', 'VCardText', 'VCardItem', 'VLabel', 'VAvatar']), VBtn: Button, VIcon: true, VImg: true, MediaLibraryDialog: Picker },
}

describe('Post media fields', () => {
  it('renders the explicitly imported single and multiple fields with public image contracts', () => {
    const wrapper = mount(PostMediaPanel, { global, props: { disabled: true } })
    const fields = wrapper.findAllComponents(MediaAssetField)

    expect(fields).toHaveLength(2)
    expect(fields[0].props()).toMatchObject({ field: 'post.thumbnail', multiple: false, visibility: 'public', disabled: true })
    expect(fields[1].props()).toMatchObject({ field: 'post.content_images', multiple: true, visibility: 'public', disabled: true })
    expect(wrapper.findAll('button').every(button => button.element.disabled)).toBe(true)
    wrapper.unmount()
  })

  it('preselects gallery and emits reorder/remove without mutating the input array', async () => {
    const assets = [{ id: 1, title: 'One' }, { id: 2, title: 'Two' }]
    const wrapper = mount(MediaAssetField, { global, props: { field: 'post.content_images', multiple: true, modelValue: assets, canAttach: true } })

    await wrapper.get('button[aria-label="Move Two earlier"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')[0][0].map(asset => asset.id)).toEqual([2, 1])
    expect(assets.map(asset => asset.id)).toEqual([1, 2])
    await wrapper.findAll('button').find(button => button.text() === 'Change file').trigger('click')
    expect(wrapper.text()).toContain('Selected 2')
    await wrapper.findAll('button').find(button => button.text() === 'Confirm picker').trigger('click')
    expect(wrapper.emitted('update:modelValue')[1][0]).toEqual([{ id: 3, title: 'New' }])
    await wrapper.get('button[aria-label="Remove One"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')[2][0]).toEqual([assets[1]])
    wrapper.unmount()
  })
})
