/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử contract CRUD Category ở frontend.
 * =====================================================================
 *
 * Test bảo đảm service bóc envelope Laravel, đổi field snake_case và không
 * gửi nhầm field giao diện ngoài whitelist Category API.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - list(): kiểm tra normalize danh sách và pagination.
 * - create()/update(): kiểm tra payload và HTTP method.
 * - remove(): kiểm tra endpoint xóa.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : response/payload giả lập từ Category API.
 * - OUTPUT: assertion trên DTO và lời gọi $api.
 * =====================================================================
 */
/* eslint-disable camelcase -- Fixtures and assertions follow Laravel API keys. */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { categoryService } from '@/services/category'

const mocks = vi.hoisted(() => ({ api: vi.fn() }))

vi.mock('@/utils/api', () => ({ $api: mocks.api }))

describe('Category API service', () => {
  beforeEach(() => mocks.api.mockReset())

  it('normalizes the paginated tree source', async () => {
    mocks.api.mockResolvedValue({
      success: true,
      data: [{ id: 2, name: 'Con', parent_id: 1, status: 'inactive', show_on_menu: false, posts_count: 3 }],
      meta: { pagination: { total: 1, per_page: 100 } },
    })

    await expect(categoryService.list()).resolves.toMatchObject({
      items: [{ id: 2, parentId: 1, status: 'inactive', showOnMenu: false, postCount: 3 }],
      itemsLength: 1,
    })
    expect(mocks.api).toHaveBeenCalledWith('/admin/categories', {
      query: { page: 1, per_page: 100, search: undefined },
    })
  })

  it('sends only Category fields and thumbnail usage when creating', async () => {
    mocks.api.mockResolvedValue({ success: true, data: { id: 8, name: 'AI' } })

    await categoryService.create({
      name: ' AI ',
      parent: 2,
      description: 'Tools',
      isActive: false,
      showOnMenu: true,
      thumbnail: { id: 44 },
      thumbnailDirty: true,
      score: 99,
    })

    expect(mocks.api.mock.calls[0]).toEqual(['/admin/categories', {
      method: 'POST',
      body: {
        name: 'AI',
        parent_id: 2,
        description: 'Tools',
        status: 'inactive',
        show_on_menu: true,
        media: { thumbnail_id: 44 },
      },
    }])
  })

  it('uses PUT and DELETE for edit and remove', async () => {
    mocks.api.mockResolvedValue({ success: true, data: { id: 8, name: 'Updated' } })

    await categoryService.update(8, { name: 'Updated', thumbnailDirty: false })
    expect(mocks.api.mock.calls[0]).toEqual(['/admin/categories/8', {
      method: 'PUT',
      body: {
        name: 'Updated',
        parent_id: null,
        description: null,
        status: 'active',
        show_on_menu: true,
      },
    }])

    await categoryService.remove(8)
    expect(mocks.api.mock.calls[1]).toEqual(['/admin/categories/8', { method: 'DELETE' }])
  })
})
