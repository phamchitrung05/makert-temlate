/* eslint-disable camelcase -- Fixture quality theo contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm lịch sử phiên bản và điểm quality của Content AI.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: render(), afterEach(), test history score,
 * test polling ổn định, test mở phiên bản và test hiển thị đủ dẫn chứng.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): session versions -> nhãn phiên bản,
 * điểm chất lượng và trạng thái hiện tại hiển thị trong dialog.
 * SIDE EFFECT: mount component với stub UI; không gọi API hoặc mutate fixture.
 * =====================================================================
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AiContentVersionHistoryDialog from '@/views/ai/content/AiContentVersionHistoryDialog.vue'
import { aiAgentService } from '@/services/aiAgent'

let wrapper

/**
 * =====================================================================
 * CHỨC NĂNG: Mount dialog lịch sử với các stub layout tối thiểu.
 * =====================================================================
 * INPUT: item session đã gom theo workspace.
 * OUTPUT: wrapper DOM để kiểm nội dung badge và phiên bản.
 * SIDE EFFECT: mount Vue cục bộ; không gọi API thật.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
function render(item) {
  wrapper = mount(AiContentVersionHistoryDialog, {
    props: { item },
    global: {
      stubs: {
        VDialog: { props: ['modelValue'], template: '<div v-if="modelValue"><slot /></div>' },
        AppDialogLayout: { props: ['title', 'subtitle'], template: '<section><header>{{ title }} {{ subtitle }}</header><slot /><slot name="footer" /></section>' },
        VCardText: { template: '<div><slot /></div>' },
        VList: { template: '<div><slot /></div>' },
        VListItem: { props: ['title', 'subtitle'], template: '<article>{{ title }} {{ subtitle }}<slot name="prepend" /><slot name="append" /></article>' },
        VAvatar: { template: '<span><slot /></span>' },
        VIcon: true,
        VChip: { template: '<span><slot /></span>' },
        VBtn: { emits: ['click'], template: '<button @click="$emit(\'click\', $event)"><slot /></button>' },
      },
    },
  })

  return wrapper
}

afterEach(() => {
  wrapper?.unmount()
  vi.restoreAllMocks()
})

describe('AI content version history dialog', () => {
  it('shows each version quality score beside its status', () => {
    render({ title: 'Bài Laravel', currentVersionId: 'run-2', versions: [
      { id: 'run-2', versionNo: 2, title: 'Bản mới', model: 'gpt', createdAt: '2026-10-10T10:00:00Z', status: 'review', qualityEvaluation: { score_total: 4.7 } },
      { id: 'run-1', versionNo: 1, title: 'Bản cũ', model: 'gpt', createdAt: '2026-10-09T10:00:00Z', status: 'rejected', qualityEvaluation: { score_total: 3.2 } },
    ] })

    expect(wrapper.text()).toContain('Phiên bản 2')
    expect(wrapper.text()).toContain('Điểm 4.7/5')
    expect(wrapper.text()).toContain('Điểm 3.2/5')
    expect(wrapper.text()).toContain('Hiện tại')
  })

  it('does not refetch detail when polling updates the same session progress', async () => {
    const reviewSpy = vi.spyOn(aiAgentService, 'review').mockResolvedValue({
      draft: { title: 'Bản đang tạo', content: '<p>Nội dung</p>' },
    })

    const qualitySpy = vi.spyOn(aiAgentService, 'quality').mockResolvedValue({ score_total: 4.2 })

    const item = {
      sessionId: 'session-1',
      currentVersionId: 'run-2',
      versions: [{ id: 'run-2', targetType: 'post', status: 'generating', runStatus: 'queued', title: 'Bản đang tạo' }],
    }

    render(item)
    await flushPromises()
    expect(reviewSpy).toHaveBeenCalledTimes(1)
    expect(qualitySpy).toHaveBeenCalledTimes(1)

    await wrapper.setProps({ item: {
      ...item,
      versions: [{ ...item.versions[0], runStatus: 'processing' }],
    } })
    await flushPromises()

    expect(reviewSpy).toHaveBeenCalledTimes(1)
    expect(qualitySpy).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[aria-current="true"]').exists()).toBe(true)
  })

  it('opens the selected version record instead of forwarding the click event', async () => {
    render({
      sessionId: 'session-1',
      currentVersionId: 'run-2',
      versions: [{ id: 'run-2', status: 'review', title: 'Bản mới' }],
    })

    const openButton = wrapper.findAll('button').find(button => button.text().includes('Xem phiên bản'))

    expect(openButton).toBeDefined()
    await openButton.trigger('click')

    expect(wrapper.emitted('open')).toHaveLength(1)
    expect(wrapper.emitted('open')[0][0]).toMatchObject({ id: 'run-2', targetType: 'post' })
  })

  it('renders every source evidence row inside the scrollable list', () => {
    const evidence = Array.from({ length: 6 }, (_, index) => ({
      source_block_id: `S${index + 1}`,
      excerpt: `Dẫn chứng ${index + 1}`,
    }))

    render({
      currentVersionId: 'run-2',
      versions: [{
        id: 'run-2',
        status: 'review',
        qualityEvaluation: { evidence },
      }],
    })

    expect(wrapper.find('.ai-version-history__evidence-list').exists()).toBe(true)
    expect(wrapper.findAll('.ai-version-history__evidence-row')).toHaveLength(6)
    expect(wrapper.text()).toContain('Dẫn chứng 6')
  })

})
