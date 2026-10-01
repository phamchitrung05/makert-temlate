/* eslint-disable camelcase -- candidate fixture follows the Laravel output contract. */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import CreateWithAiDialog from '@/views/apps/blog/post/dialog/CreateWithAiDialog.vue'
import { passthroughStubs } from './testStubs'

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử Apply candidate Post giữ provenance đúng API contract.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: beforeEach(), renderCandidate(), apply(); hai test
 * cho lựa chọn toàn bộ output và lựa chọn riêng một field SEO.
 * INPUT/OUTPUT CỦA FILE (tổng thể): Candidate/selection -> payload và lineage
 * theo nhóm field backend cho phép, không nhận thumbnail chưa có asset.
 * SIDE EFFECT: Mount Vue với store mock; không gọi provider hoặc ghi Post.
 * =====================================================================
 */
const state = vi.hoisted(() => ({ store: null }))

vi.mock('@/stores/aiAgent', () => ({ useAiAgentStore: () => state.store }))

const ButtonStub = {
  props: ['disabled'],
  template: '<button :disabled="disabled"><slot /></button>',
}

const PreviewStub = {
  name: 'AiAgentCandidatePreview',
  props: ['candidate', 'selectedFields'],
  emits: ['update:selectedFields'],
  template: '<div />',
}

/** Input: Không có. Output: Component với candidate giả đã ready. */
async function renderCandidate() {
  const wrapper = mount(CreateWithAiDialog, {
    props: { modelValue: true },
    global: {
      mocks: { $vuetify: { display: { smAndDown: false } } },
      stubs: {
        ...passthroughStubs(['VDialog', 'DialogCloseBtn', 'VCard', 'VCardItem', 'VCardTitle', 'VCardSubtitle', 'VCardText', 'VCardActions', 'VAvatar', 'VIcon', 'VSheet', 'VRow', 'VCol', 'VAlert', 'VDivider', 'VProgressLinear', 'AppStepper', 'AppTextField', 'AppSelect', 'AppTextarea', 'ArticleSourcePreviewCard', 'AiImportProgressCard']),
        VBtn: ButtonStub,
        AiAgentCandidatePreview: PreviewStub,
      },
    },
  })

  state.store.candidates = [{
    id: 'run-text',
    outputs: {
      title: 'Tiêu đề AI', content_html: '<p>Nội dung AI</p>', content: '<p>Nội dung AI</p>', excerpt: 'Mô tả AI',
      seo_title: 'SEO AI', seo_description: 'Mô tả SEO AI', focus_keyword: 'AI',
      suggested_category_ids: [2], suggested_tag_ids: [3], thumbnail_prompt: 'Ảnh đề xuất',
      thumbnail: { media_asset_id: null, alt_text: 'Chưa tạo ảnh' },
    },
  }]
  await flushPromises()

  return wrapper
}

/** Input: Component mounted. Output: Event Apply sau thao tác nhấn nút. */
async function apply(wrapper) {
  await wrapper.findAll('button').find(button => button.text() === 'Áp dụng bản đã chọn').trigger('click')

  return wrapper.emitted('apply')?.[0]
}

describe('CreateWithAiDialog provenance on Apply', () => {
  beforeEach(() => {
    state.store = reactive({ capabilities: {}, candidates: [], session: { status: 'ready' }, isLoading: false, reset: vi.fn(), loadCapabilities: vi.fn().mockResolvedValue({}) })
  })

  it('maps raw output keys to accepted provenance groups and excludes an absent thumbnail', async () => {
    const wrapper = await renderCandidate()
    const [payload, provenance] = await apply(wrapper)

    expect(payload).toEqual({
      title: 'Tiêu đề AI', content: '<p>Nội dung AI</p>', excerpt: 'Mô tả AI',
      seo: { title: 'SEO AI', description: 'Mô tả SEO AI', focusKeyword: 'AI' }, categories: [2], tags: [3],
    })
    expect(provenance.runId).toBe('run-text')
    expect(provenance.fields).toHaveLength(5)
    expect(new Set(provenance.fields)).toEqual(new Set(['title', 'content', 'excerpt', 'seo', 'taxonomy']))
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([false])
    wrapper.unmount()
  })

  it('records only the SEO group when the admin selects one SEO field', async () => {
    const wrapper = await renderCandidate()

    wrapper.findComponent(PreviewStub).vm.$emit('update:selectedFields', ['seo_title', 'thumbnail_prompt'])
    await flushPromises()

    expect(await apply(wrapper)).toEqual([
      { seo: { title: 'SEO AI' } },
      { runId: 'run-text', fields: ['seo'] },
    ])
    wrapper.unmount()
  })
})
