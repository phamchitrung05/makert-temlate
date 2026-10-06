/* eslint-disable camelcase */
export const db = {
  posts: [
    {
      id: 1,
      title: 'Welcome to the Media Library',
      content: 'Demo post',
      status: 'draft',
      media: { thumbnail_id: null, gallery_image_ids: [] },
      created_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
    },
  ],
}
