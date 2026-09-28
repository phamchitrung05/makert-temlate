/* eslint-disable camelcase */

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cung cấp dataset giả cho Media Library admin
 * =====================================================================
 *
 * Dataset mô phỏng shape MediaAssetResource của Laravel. MSW handler dùng
 * dữ liệu này để màn hình File có thể chạy local mà không cần storage thật.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - Không có; file chỉ export object dữ liệu giả.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : Không có.
 * - OUTPUT: db.mediaAssets gồm metadata asset, file status và usages.
 * =====================================================================
 */
export const db = {
  mediaAssets: [
    {
      id: 1,
      kind: 'image',
      title: 'Resource cover sample',
      alt_text: 'Dashboard resource cover',
      visibility: 'public',
      created_by: 1,
      owner: { id: 1, name: 'Development Admin' },
      file: {
        id: 101,
        original_name: 'resource-cover.png',
        file_name: 'resource-cover.png',
        mime_type: 'image/png',
        extension: 'png',
        size: 248320,
        checksum_sha256: 'a'.repeat(64),
        scan_status: 'clean',
        conversion_status: 'ready',
        url: '/storage/media/resource-cover.png',
        preview_url: '/storage/media/conversions/resource-cover-thumb.jpg',
      },
      download_url: '/api/admin/media-assets/1/download',
      usages: [
        { id: 1001, media_asset_id: 1, linkable_type: 'resource', linkable_id: 1, field: 'resource.cover', sort_order: null },
      ],
      created_at: '2026-09-27T08:30:00+07:00',
      updated_at: '2026-09-27T08:30:00+07:00',
    },
    {
      id: 2,
      kind: 'image',
      title: 'Preview gallery image',
      alt_text: 'Preview screen for SaaS dashboard',
      visibility: 'public',
      created_by: 1,
      owner: { id: 1, name: 'Development Admin' },
      file: {
        id: 102,
        original_name: 'preview-gallery.webp',
        file_name: 'preview-gallery.webp',
        mime_type: 'image/webp',
        extension: 'webp',
        size: 683008,
        checksum_sha256: 'b'.repeat(64),
        scan_status: 'clean',
        conversion_status: 'ready',
        url: '/storage/media/preview-gallery.webp',
        preview_url: '/storage/media/conversions/preview-gallery-thumb.webp',
      },
      download_url: '/api/admin/media-assets/2/download',
      usages: [],
      created_at: '2026-09-26T14:20:00+07:00',
      updated_at: '2026-09-26T14:20:00+07:00',
    },
    {
      id: 3,
      kind: 'document',
      title: 'Resource documentation',
      alt_text: null,
      visibility: 'private',
      created_by: 1,
      owner: { id: 1, name: 'Development Admin' },
      file: {
        id: 103,
        original_name: 'documentation.pdf',
        file_name: 'documentation.pdf',
        mime_type: 'application/pdf',
        extension: 'pdf',
        size: 1458176,
        checksum_sha256: 'c'.repeat(64),
        scan_status: 'clean',
        conversion_status: 'ready',
        url: null,
        preview_url: null,
      },
      download_url: '/api/admin/media-assets/3/download',
      usages: [],
      created_at: '2026-09-25T10:15:00+07:00',
      updated_at: '2026-09-25T10:15:00+07:00',
    },
    {
      id: 4,
      kind: 'archive',
      title: 'Resource package v1.0.0',
      alt_text: null,
      visibility: 'private',
      created_by: 1,
      owner: { id: 1, name: 'Development Admin' },
      file: {
        id: 104,
        original_name: 'resource-package.zip',
        file_name: 'resource-package.zip',
        mime_type: 'application/zip',
        extension: 'zip',
        size: 8388608,
        checksum_sha256: 'd'.repeat(64),
        scan_status: 'pending',
        conversion_status: null,
        url: null,
        preview_url: null,
      },
      download_url: '/api/admin/media-assets/4/download',
      usages: [],
      created_at: '2026-09-24T16:45:00+07:00',
      updated_at: '2026-09-24T16:45:00+07:00',
    },
    {
      id: 5,
      kind: 'video',
      title: 'Product walkthrough',
      alt_text: 'Product walkthrough video',
      visibility: 'private',
      created_by: 1,
      owner: { id: 1, name: 'Development Admin' },
      file: {
        id: 105,
        original_name: 'walkthrough.mp4',
        file_name: 'walkthrough.mp4',
        mime_type: 'video/mp4',
        extension: 'mp4',
        size: 25165824,
        checksum_sha256: 'e'.repeat(64),
        scan_status: 'error',
        conversion_status: 'failed',
        url: null,
        preview_url: null,
      },
      download_url: '/api/admin/media-assets/5/download',
      usages: [],
      created_at: '2026-09-23T12:00:00+07:00',
      updated_at: '2026-09-23T12:00:00+07:00',
    },
  ],
}
