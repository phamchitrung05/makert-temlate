/* eslint-disable camelcase -- Fixture giữ task_type theo contract queue. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm icon mode và trạng thái từng dòng task popup.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - task(): tạo fixture task theo mode.
 * - renderItem(): mount row với UI stub, không gọi API.
 * - các test mode(): kiểm avatar icon theo article/image/writing/sound.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): task summary -> icon và nhãn mode.
 * SIDE EFFECT: mount component trong DOM test; không gọi router/provider.
 * =====================================================================
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AiTaskQueueItem from '@/components/ai/AiTaskQueueItem.vue'

const Passthrough = { template: '<div><slot /></div>' }
const Icon = { template: '<span><slot /></span>' }
const Avatar = { props: ['ariaLabel'], template: '<div :aria-label="ariaLabel"><slot /></div>' }

const task = (task_type, source) => ({ id: task_type, task_type, source, name: 'Task', status: 'queued' })

function renderItem(value) {
  return mount(AiTaskQueueItem, {
    props: { task: value },
    global: { stubs: {
      VAvatar: Avatar, VIcon: Icon, VProgressLinear: true, VBtn: true, VMenu: Passthrough,
      VList: Passthrough, VListItem: Passthrough,
    } },
  })
}

describe('AI task queue item mode icons', () => {
  it.each([
    ['article_generation', 'ai_content', 'tabler-file-text', 'Bài viết'],
    ['image_generation', 'ai_image', 'tabler-photo', 'Hình ảnh'],
    ['writing_profile_analysis', 'ai_writing_profile', 'tabler-writing-sign', 'Văn phong'],
    ['sound_generation', 'ai_sound', 'tabler-volume', 'Âm thanh'],
  ])('shows the %s mode icon before task details', (type, source, icon, label) => {
    const wrapper = renderItem(task(type, source))

    expect(wrapper.text()).toContain(icon)
    expect(wrapper.get('[aria-label]').attributes('aria-label')).toBe(label)
    wrapper.unmount()
  })
})
