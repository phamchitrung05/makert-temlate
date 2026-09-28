/* eslint-disable camelcase */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import MediaLibraryDialog from '@/views/apps/media/field/MediaLibraryDialog.vue'
import { passthroughStubs } from './testStubs'

const serviceMocks = vi.hoisted(() => ({
  service: {
    list: vi.fn(),
    show: vi.fn(),
    upload: vi.fn(),
    retry: vi.fn(),
  },
}))

vi.mock('@/services/mediaAsset', () => ({ mediaAssetService: serviceMocks.service }))

const asset = (id, kind = 'image') => ({
  id,
  kind,
  title: `Asset ${id}`,
  visibility: kind === 'archive' ? 'private' : 'public',
  file: { scan_status: 'clean' },
})

const VDialogStub = {
  props: { modelValue: Boolean },
  emits: ['update:modelValue'],
  template: '<div v-if="modelValue" data-testid="media-dialog"><slot /></div>',
}

const VBtnStub = {
  inheritAttrs: false,
  props: { disabled: Boolean },
  emits: ['click'],
  template: '<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
}

const GridStub = {
  props: { assets: { type: Array, default: () => [] } },
  emits: ['toggle'],
  template: `<div data-testid="media-grid">
    <button
      v-for="item in assets"
      :key="item.id"
      :data-testid="'asset-' + item.id"
      @click="$emit('toggle', item)"
    >{{ item.title }}</button>
  </div>`,
}

const dialogPassthroughs = passthroughStubs([
  'VCardTitle',
  'VCardSubtitle',
  'VCardItem',
  'VAlert',
  'AppTextField',
  'VCol',
  'AppSelect',
  'VRow',
  'VExpansionPanelText',
  'VExpansionPanel',
  'VExpansionPanels',
  'VProgressLinear',
  'TablePagination',
  'VCardText',
  'VCardActions',
  'VCard',
  'VSnackbar',
])

const mountDialog = (props = {}) => mount(MediaLibraryDialog, {
  props: {
    open: true,
    field: 'resource.cover',
    kind: 'image',
    canAttach: true,
    canUpload: true,
    ...props,
  },
  global: {
    plugins: [createPinia()],
    stubs: {
      ...dialogPassthroughs,
      VDialog: VDialogStub,
      VBtn: VBtnStub,
      MediaAssetGrid: GridStub,
      MediaUploadDropZone: true,
      MediaAssetDetails: true,
      DialogCloseBtn: true,
    },
  },
})

describe('MediaLibraryDialog', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    serviceMocks.service.list.mockReset()
    serviceMocks.service.show.mockReset()
    serviceMocks.service.upload.mockReset()
    serviceMocks.service.retry.mockReset()
  })

  it('passes kind and field filters to the store and emits one selected asset', async () => {
    const cover = asset(1)

    serviceMocks.service.list.mockResolvedValue({
      items: [cover],
      itemsLength: 1,
      pagination: { current_page: 1, per_page: 12, total: 1 },
    })

    const wrapper = mountDialog()

    await flushPromises()

    expect(serviceMocks.service.list).toHaveBeenCalledWith(expect.objectContaining({
      kind: 'image',
      field: 'resource.cover',
      page: 1,
      per_page: 12,
    }))

    await wrapper.find('[data-testid="asset-1"]').trigger('click')

    expect(wrapper.emitted('select')).toEqual([[cover]])
    expect(wrapper.emitted('update:open')).toEqual([[false]])
  })

  it('collects multiple selections and emits them only on confirmation', async () => {
    const first = asset(1)
    const second = asset(2)

    serviceMocks.service.list.mockResolvedValue({
      items: [first, second],
      itemsLength: 2,
      pagination: { current_page: 1, per_page: 12, total: 2 },
    })

    const wrapper = mountDialog({ field: 'resource.preview', multiple: true })

    await flushPromises()

    await wrapper.find('[data-testid="asset-1"]').trigger('click')
    await wrapper.find('[data-testid="asset-2"]').trigger('click')

    const selectButton = wrapper.findAll('button').find(button => button.text().includes('Select'))

    await selectButton.trigger('click')

    expect(wrapper.emitted('select')).toEqual([[[first, second]]])
    expect(wrapper.emitted('update:open')).toEqual([[false]])
  })

  it('does not query the API when field and kind are incompatible', async () => {
    const wrapper = mountDialog({ field: 'resource.cover', kind: 'archive' })

    await flushPromises()

    expect(serviceMocks.service.list).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('resource.cover')
    expect(wrapper.text()).toContain('image')
  })
})
