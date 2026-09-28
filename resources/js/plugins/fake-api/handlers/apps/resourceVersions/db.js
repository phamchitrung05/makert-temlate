/* eslint-disable camelcase */
export const db = {
  resourceVersions: [
    {
      id: 1,
      resource_id: 1,
      version: '1.0.0',
      changelog: 'Initial release',
      requirements: { php: '>=8.2' },
      status: 'draft',
      is_default: true,
      released_at: null,
      media: { package_id: null, documentation_ids: [] },
      created_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
    },
  ],
}
