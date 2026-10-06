// @vitest-environment node
/* eslint-disable camelcase */

import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest'
import { setupServer } from 'msw/node'
import { http } from 'msw'
import { handlerAppsMedia } from '@db/apps/media/index'
import { db } from '@db/apps/media/db'

const server = setupServer(
  http.get('http://localhost/api/admin/media-assets', handlerAppsMedia[0].resolver),
  http.post('http://localhost/api/admin/media-assets/:mediaAsset/usages', handlerAppsMedia[2].resolver),
  http.delete('http://localhost/api/admin/media-assets/:mediaAsset/usages/:usage', handlerAppsMedia[3].resolver),
)

describe('fake Media API contract', () => {
  beforeAll(() => {
    server.listen({ onUnhandledRequest: 'error' })
  })
  afterEach(() => server.resetHandlers())
  afterAll(() => server.close())

  it('returns Laravel-compatible envelope and pagination metadata', async () => {
    const url = new URL('http://localhost/api/admin/media-assets')

    url.search = new URLSearchParams({ kind: 'image', field: 'resource.cover', page: '1', per_page: '1' }).toString()

    const response = await fetch(url)
    const body = await response.json()

    expect(response.ok).toBe(true)
    expect(body).toEqual(expect.objectContaining({
      success: true,
      data: expect.objectContaining({
        items: expect.any(Array),
        itemsLength: expect.any(Number),
      }),
      errors: expect.any(Array),
      meta: expect.objectContaining({ pagination: expect.any(Object) }),
    }))
    expect(body.data.items.length).toBeLessThanOrEqual(1)
    expect(body.meta.pagination).toEqual(expect.objectContaining({
      current_page: 1,
      per_page: 1,
      total: expect.any(Number),
      last_page: expect.any(Number),
    }))
  })

  it('rejects a field and kind combination that violates the shared contract', async () => {
    const url = new URL('http://localhost/api/admin/media-assets')

    url.search = new URLSearchParams({ kind: 'archive', field: 'resource.cover' }).toString()

    const response = await fetch(url)
    const body = await response.json()

    expect(response.status).toBe(422)
    expect(body).toEqual(expect.objectContaining({
      success: false,
      data: null,
      errors: expect.objectContaining({ field: expect.any(Array) }),
    }))
  })

  it('supports attach and detach usage with the same response contract as Laravel', async () => {
    const asset = db.mediaAssets.find(item => item.id === 2)
    const originalUsages = structuredClone(asset.usages)

    try {
      const attachResponse = await fetch('http://localhost/api/admin/media-assets/2/usages', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          field: 'post.gallery',
          linkable_type: 'post',
          linkable_id: 7,
          sort_order: 0,
        }),
      })

      const attached = await attachResponse.json()

      expect(attachResponse.status).toBe(201)
      expect(attached).toEqual(expect.objectContaining({
        success: true,
        data: expect.objectContaining({ field: 'post.gallery', media_asset_id: 2 }),
      }))

      const usageId = attached.data.id
      const detachResponse = await fetch(`http://localhost/api/admin/media-assets/2/usages/${usageId}`, { method: 'DELETE' })

      expect(detachResponse.status).toBe(204)
      expect(asset.usages).toEqual(originalUsages)
    }
    finally {
      asset.usages = originalUsages
    }
  })
})
