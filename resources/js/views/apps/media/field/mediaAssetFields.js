/**
 * =====================================================================
 * CHỨC NĂNG FILE: Metadata hiển thị cho Media Picker field
 * =====================================================================
 *
 * Giá trị field vẫn do backend `MediaAssetField` làm nguồn chuẩn. File này
 * chỉ mô tả nhãn, kind và cardinality để UI render nhất quán; không thay thế
 * validation hoặc authorization ở Laravel.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - getMediaAssetFieldConfig(): lấy metadata của field cho UI
 * - kindAccept(): lấy accept attribute cho vùng upload
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : field/kind string từ picker hoặc form nghiệp vụ.
 * - OUTPUT: metadata hiển thị frontend, không chứa dữ liệu nhạy cảm.
 * =====================================================================
 */

export const mediaAssetFieldConfig = Object.freeze({
  'category.thumbnail': Object.freeze({
    title: 'Category thumbnail',
    kind: 'image',
    multiple: false,
  }),
  'post.thumbnail': Object.freeze({
    title: 'Post thumbnail',
    kind: 'image',
    multiple: false,
  }),
  'post.gallery': Object.freeze({
    title: 'Post image gallery',
    kind: 'image',
    multiple: true,
  }),
  'post.og_image': Object.freeze({
    title: 'Post Open Graph image',
    kind: 'image',
    multiple: false,
  }),
  'resource.cover': Object.freeze({
    title: 'Resource cover',
    kind: 'image',
    multiple: false,
  }),
  'resource.preview': Object.freeze({
    title: 'Resource preview',
    kind: 'image',
    multiple: true,
  }),
  'resource_version.package': Object.freeze({
    title: 'Resource package',
    kind: 'archive',
    multiple: false,
  }),
  'resource_version.documentation': Object.freeze({
    title: 'Resource documentation',
    kind: 'document',
    multiple: true,
  }),
})

/** Input: field contract key. Output: immutable UI metadata hoặc null. */
export const getMediaAssetFieldConfig = field => mediaAssetFieldConfig[field] ?? null

/** Input: media kind. Output: accept attribute cho file input. */
export const kindAccept = kind => ({
  image: 'image/*',
  document: '.pdf,.doc,.docx,.txt,.md',
  archive: '.zip,.tar,.gz,.tgz,.rar,application/zip,application/x-tar',
  video: 'video/*',
}[kind] ?? '*/*')

