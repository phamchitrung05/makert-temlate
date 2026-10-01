import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AiAgentCandidatePreview from '@/components/ai/AiAgentCandidatePreview.vue'
import { passthroughStubs } from './testStubs'

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử metadata provider của candidate AI.
 * =====================================================================
 *
 * CÁC HÀM/METHOD TRONG FILE: test resolve label/logo từ capability backend.
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : candidate và provider allowlist đã trả từ capability endpoint.
 * - OUTPUT: UI dùng label/logo allowlist, vẫn hiển thị model/run metadata.
 * - SIDE EFFECT: mount component trong jsdom; không gọi API.
 * =====================================================================
 */

const IconStub = {
  props: ['icon'],
  template: '<i :data-icon="icon" />',
}

describe('AiAgentCandidatePreview', () => {
  it('uses provider identity from backend capability instead of candidate branding', () => {
    const wrapper = mount(AiAgentCandidatePreview, {
      props: {
        candidate: {
          provider: 'openai',
          'provider_label': 'Nhãn giả từ candidate',
          logo: 'untrusted-logo',
          model: 'gpt-4o-mini',
          outputs: { title: 'Tiêu đề AI' },
        },
        selectedFields: ['title'],
        providers: [{ key: 'openai', label: 'OpenAI', logo: 'openai', models: ['gpt-4o-mini'] }],
      },
      global: {
        stubs: {
          ...passthroughStubs([
            'VCard',
            'VCardTitle',
            'VCardText',
            'VAvatar',
            'VChip',
            'VAlert',
            'VExpansionPanels',
            'VExpansionPanel',
            'VExpansionPanelTitle',
            'VExpansionPanelText',
            'VCheckbox',
          ]),
          VIcon: IconStub,
        },
      },
    })

    expect(wrapper.text()).toContain('OpenAI')
    expect(wrapper.text()).toContain('gpt-4o-mini')
    expect(wrapper.text()).not.toContain('Nhãn giả từ candidate')
    expect(wrapper.get('[data-icon="tabler-brand-openai"]')).toBeTruthy()
  })
})
