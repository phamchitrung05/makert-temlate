/* eslint-disable camelcase -- fixture mirrors Laravel capability payload. */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AiAgentDialog from '@/components/ai/AiAgentDialog.vue'

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử dialog AI render theo capability của target.
 * =====================================================================
 *
 * Fixture Resource mô phỏng capability backend mà không bật pipeline Resource
 * thật. Test khóa contract rằng dialog đọc operation/input/output/provider từ
 * registry, không gắn cứng danh sách riêng cho Post.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - beforeEach(): reset state/store mock trước mỗi test
 * - test_dialog_uses_non_post_capability_fixture(): kiểm tra render Resource
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : capability fixture Resource và targetType `resource`.
 * - OUTPUT: form render đúng field/provider theo capability.
 * - SIDE EFFECT: chỉ mount Vue component; không gọi HTTP hoặc ghi database.
 * =====================================================================
 */

const storeMock = vi.hoisted(() => ({
  capabilities: {
    target_type: 'resource',
    operations: ['create', 'documentation'],
    input_types: ['text'],
    outputs: ['title', 'description', 'documentation', 'taxonomy'],
    prompts: [{ key: 'resource.create.documentation', label: 'Documentation' }],
    providers: [{ key: 'deterministic', label: 'Deterministic', models: ['deterministic'] }],
  },
  session: null,
  candidates: [],
  isLoading: false,
  loadCapabilities: vi.fn(async () => storeMock.capabilities),
  start: vi.fn(),
  poll: vi.fn(),
  regenerate: vi.fn(),
  retry: vi.fn(),
  cancel: vi.fn(),
  apply: vi.fn(),
}))

vi.mock('@/stores/aiAgent', () => ({
  useAiAgentStore: () => storeMock,
}))

const Passthrough = { template: '<div><slot /></div>' }

const VCardItemStub = {
  props: ['title', 'subtitle'],
  template: '<div data-testid="card-item"><span>{{ title }}</span><span>{{ subtitle }}</span></div>',
}

const AppSelectStub = {
  props: ['items', 'label'],
  template: '<div data-testid="app-select" :data-label="label" :data-items="JSON.stringify(items)">{{ label }}</div>',
}

describe('AiAgentDialog capability fixture', () => {
  beforeEach(() => {
    storeMock.loadCapabilities.mockClear()
  })

  /**
   * =====================================================================
   * CHỨC NĂNG: Kiểm tra dialog dùng capability Resource ngoài Post
   * =====================================================================
   *
   * OUTPUT:
   * - Gọi load capability đúng target.
   * - Render operation, input type, output fields và provider từ fixture.
   *
   * SIDE EFFECT:
   * - Không gọi API thật; store đã được mock ở boundary.
   * =====================================================================
   */
  it('uses a non-Post target capability fixture', async () => {
    const wrapper = mount(AiAgentDialog, {
      props: { modelValue: true, targetType: 'resource' },
      global: {
        stubs: {
          VDialog: Passthrough,
          DialogCloseBtn: Passthrough,
          VCard: Passthrough,
          VCardItem: VCardItemStub,
          VCardText: Passthrough,
          VAlert: Passthrough,
          VRow: Passthrough,
          VCol: Passthrough,
          AppSelect: AppSelectStub,
          AppTextarea: Passthrough,
          VProgressLinear: Passthrough,
          VDivider: Passthrough,
          VCardActions: Passthrough,
          VBtn: Passthrough,
          AiAgentCandidatePreview: Passthrough,
        },
      },
    })

    await wrapper.setProps({ modelValue: false })
    await wrapper.setProps({ modelValue: true })
    await flushPromises()

    expect(storeMock.loadCapabilities).toHaveBeenCalledWith('resource')
    expect(wrapper.text()).toContain('Tài nguyên: resource')

    const renderedItems = wrapper.findAll('[data-testid="app-select"]')
      .map(element => element.attributes('data-items'))
      .join(' ')

    expect(renderedItems).toContain('documentation')
    expect(renderedItems).toContain('taxonomy')
    expect(renderedItems).toContain('deterministic')
  })
})
