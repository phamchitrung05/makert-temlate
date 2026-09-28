/* eslint-disable camelcase */

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cung cấp dataset giả cho Media Library admin
 * =====================================================================
 *
 * Dataset chỉ tồn tại trong memory của MSW browser worker và mô phỏng shape
 * MediaAssetResource từ Laravel, gồm file status, usage và metadata cơ bản.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : không có
 * - OUTPUT: db.mediaAssets cho các handler Media Library
 * =====================================================================
 */
export const db = {
  mediaAssets: [
    {
      id: 1,
      kind: 'image',
      title: 'Vuexy dashboard cover',
      alt_text: 'Vuexy dashboard preview',
      visibility: 'public',
      created_by: 1,
      owner: { id: 1, name: 'Development Admin' },
      file: { id: 101, original_name: 'dashboard-cover.jpg', file_name: 'dashboard-cover.jpg', mime_type: 'image/jpeg', extension: 'jpg', size: 183420, checksum_sha256: 'fake-image-checksum-1', scan_status: 'clean', conversion_status: 'ready', url: '/storage/media/dashboard-cover.jpg', preview_url: '/storage/media/conversions/dashboard-cover-thumb.jpg' },
      usages: [],
      created_at: '2026-09-20T08:30:00+07:00',
      updated_at: '2026-09-20T08:30:00+07:00',
    },
    {
      id: 2,
      kind: 'document',
      title: 'Media integration guide',
      alt_text: null,
      visibility: 'private',
      created_by: 1,
      owner: { id: 1, name: 'Development Admin' },
      file: { id: 102, original_name: 'media-guide.pdf', file_name: 'media-guide.pdf', mime_type: 'application/pdf', extension: 'pdf', size: 542120, checksum_sha256: 'fake-document-checksum-2', scan_status: 'clean', conversion_status: null, url: null, preview_url: null },
      usages: [{ id: 201, field: 'resource.documentation', linkable_type: 'resource', linkable_id: 1, sort_order: null }],
      created_at: '2026-09-18T12:00:00+07:00',
      updated_at: '2026-09-18T12:00:00+07:00',
    },
    {
      id: 3,
      kind: 'archive',
      title: 'Starter package',
      alt_text: null,
      visibility: 'private',
      created_by: 1,
      owner: { id: 1, name: 'Development Admin' },
      file: { id: 103, original_name: 'starter-package.zip', file_name: 'starter-package.zip', mime_type: 'application/zip', extension: 'zip', size: 1834210, checksum_sha256: 'fake-archive-checksum-3', scan_status: 'error', conversion_status: null, url: null, preview_url: null },
      usages: [],
      created_at: '2026-09-16T09:15:00+07:00',
      updated_at: '2026-09-16T09:15:00+07:00',
    },
    {
      id: 4,
      kind: 'video',
      title: 'Product tour preview',
      alt_text: null,
      visibility: 'public',
      created_by: 1,
      owner: { id: 1, name: 'Development Admin' },
      file: { id: 104, original_name: 'product-tour.mp4', file_name: 'product-tour.mp4', mime_type: 'video/mp4', extension: 'mp4', size: 2842010, checksum_sha256: 'fake-video-checksum-4', scan_status: 'clean', conversion_status: null, url: '/storage/media/product-tour.mp4', preview_url: null },
      usages: [],
      created_at: '2026-09-10T16:20:00+07:00',
      updated_at: '2026-09-10T16:20:00+07:00',
    },
  ],
}

