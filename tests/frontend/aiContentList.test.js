/* eslint-disable camelcase -- Fixture dùng DTO Laravel. */
import { h } from 'vue'
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AiContentList from '@/views/ai/content/AiContentList.vue'

const Passthrough = { template: '<div><slot /></div>' }

const DataTable = {
  props: ['items'],
  render() {
    return h('div', this.items.map(item => h('div', { 'data-test': 'content-row' }, this.$slots['item.title']?.({ item }))))
  },
}

const Image = { props: ['src', 'alt'], template: '<img :src="src" :alt="alt">' }
const Icon = { props: ['icon'], template: '<span :icon="icon" />' }

function renderList(thumbnail) {
  return mount(AiContentList, {
    props: {
      items: [{
        id: 'candidate-1',
        title: 'Laravel 14',
        source: 'https://example.com/laravel-14',
        targetType: 'post',
        status: 'review',
        thumbnail,
      }],
      targets: [{ value: 'post', title: 'Bài viết', icon: 'tabler-file-text' }],
    },
    global: { stubs: {
      VCard: Passthrough,
      VCardItem: Passthrough,
      VCardText: Passthrough,
      VTabs: Passthrough,
      VTab: Passthrough,
      VChip: Passthrough,
      VAvatar: Passthrough,
      VImg: Image,
      VIcon: Icon,
      VDataTable: DataTable,
      VBtn: true,
      VDivider: true,
      VAlert: true,
      AppTextField: true,
      AppSelect: true,
      VSpacer: true,
      TablePagination: true,
    } },
  })
}

describe('Ai Content list thumbnails', () => {
  it('shows a failed run reason with config labels after reload', async () => {
    const wrapper = renderList(null)

    await wrapper.setProps({
      items: [{ id: 'failed', status: 'failed', title: 'Bài lỗi', error: 'AI thiếu nội dung', targetType: 'post',
        validationErrors: [{ group: 'content', field: 'content_html', reason: 'missing' }] }],
      outputOptions: [{ value: 'content', title: 'Nội dung' }],
    })
    expect(wrapper.get('[data-test="content-row"]').text()).toContain('Nội dung thiếu dữ liệu bắt buộc.')
    wrapper.unmount()
  })
  it('shows the preview image beside the post title with its alternative text', () => {
    const wrapper = renderList({
      file: { preview_url: '/media/thumbnail.webp', url: '/media/original.jpg' },
      alt_text: 'Ảnh minh họa Laravel 14',
    })

    const row = wrapper.get('[data-test="content-row"]')

    expect(row.text()).toContain('Laravel 14')
    expect(row.get('img').attributes('src')).toBe('/media/thumbnail.webp')
    expect(row.get('img').attributes('alt')).toBe('Ảnh minh họa Laravel 14')
    expect(row.find('[icon="tabler-file-text"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('uses the original image and post title when preview and alternative text are missing', () => {
    const wrapper = renderList({ file: { preview_url: null, url: '/media/original.jpg' } })

    expect(wrapper.get('img').attributes('src')).toBe('/media/original.jpg')
    expect(wrapper.get('img').attributes('alt')).toBe('Laravel 14')
    wrapper.unmount()
  })

  it.each([
    ['missing thumbnail', undefined],
    ['null thumbnail', null],
    ['thumbnail without accessible URLs', { file: { preview_url: null, url: null } }],
  ])('shows the post icon for a %s', (_label, thumbnail) => {
    const wrapper = renderList(thumbnail)

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.get('[icon="tabler-file-text"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Laravel 14')
    wrapper.unmount()
  })
})
