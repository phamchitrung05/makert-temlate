/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử composable slug cho model không phải Post.
 * CÁC HÀM/METHOD TRONG FILE: useSlug() gửi model_type/model_id và nhận slug.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): state reactive -> request slug preview.
 * =====================================================================
 */
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { shallowRef } from 'vue'
import { expect, it, vi } from 'vitest'
import { useSlug } from '@/composables/useSlug'

const api = vi.hoisted(() => vi.fn())

vi.mock('@/utils/api', () => ({ $api: api }))

const Harness = {
  props: { modelType: String, modelId: Number },
  setup(props) {
    const title = shallowRef('Resource title')
    const slugState = useSlug({ title, modelType: () => props.modelType, modelId: () => props.modelId })

    return { ...slugState, title }
  },
  template: '<button data-testid="generate" @click="generate">{{ slug }}</button>',
}

it('uses the configured model type and id instead of Post-specific values', async () => {
  api.mockResolvedValueOnce({ success: true, data: { slug: 'resource-title', 'model_type': 'resource' } })

  const wrapper = mount(Harness, {
    props: { modelType: 'resource', modelId: 12 },
    global: { plugins: [createPinia()] },
  })

  await wrapper.get('[data-testid="generate"]').trigger('click')
  await flushPromises()

  expect(api).toHaveBeenCalledWith('/admin/slugs/preview', expect.objectContaining({
    body: { title: 'Resource title', 'model_type': 'resource', 'model_id': 12 },
  }))
  expect(wrapper.get('[data-testid="generate"]').text()).toBe('resource-title')
  wrapper.unmount()
})
