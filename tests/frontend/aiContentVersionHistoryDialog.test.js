/* eslint-disable camelcase -- Fixture quality theo contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm lịch sử phiên bản và điểm quality của Content AI.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: render(), afterEach(), test history score.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): session versions -> nhãn phiên bản,
 * điểm chất lượng và trạng thái hiện tại hiển thị trong dialog.
 * SIDE EFFECT: mount component với stub UI; không gọi API hoặc mutate fixture.
 * =====================================================================
 */
import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AiContentVersionHistoryDialog from '@/views/ai/content/AiContentVersionHistoryDialog.vue'

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
        AppDialogLayout: { props: ['title', 'subtitle'], template: '<section><header>{{ title }} {{ subtitle }}</header><slot /></section>' },
        VCardText: { template: '<div><slot /></div>' },
        VList: { template: '<div><slot /></div>' },
        VListItem: { props: ['title', 'subtitle'], template: '<article>{{ title }} {{ subtitle }}<slot name="prepend" /><slot name="append" /></article>' },
        VAvatar: { template: '<span><slot /></span>' },
        VIcon: true,
        VChip: { template: '<span><slot /></span>' },
        VBtn: { template: '<button><slot /></button>' },
      },
    },
  })

  return wrapper
}

afterEach(() => wrapper?.unmount())

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
})
