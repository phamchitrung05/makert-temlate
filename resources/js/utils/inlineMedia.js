/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo markup ảnh inline có ID MediaAsset và kiểm URL tạm.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: inlineAssetUrl(), inlineImageHtml(), hasTemporaryImages().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): asset/alt/caption -> HTML escaped/ref ổn định.
 * SIDE EFFECT: DOM tách rời; backend vẫn kiểm file/quyền/usage khi lưu.
 * =====================================================================
 */

/**
 * =====================================================================
 * Input: asset API. Output: URL gốc public hợp lệ hoặc lỗi; không dùng URL preview
 * private/blob/base64, không tự đoán URL từ ID hoặc filename.
 * =====================================================================
 */
export function inlineAssetUrl(asset) {
  const id = Number(asset?.id)
  if (!Number.isSafeInteger(id) || id < 1 || asset.kind !== 'image' || asset.visibility !== 'public') throw new Error('Chọn một ảnh public còn tồn tại trong MediaLibrary.')
  const url = String(asset.file?.url ?? '')
  try { if (!['http:', 'https:'].includes(new URL(url).protocol)) throw new Error('URL') }
  catch { throw new Error('Ảnh chưa có URL file public. Chờ upload/xử lý hoàn tất rồi chọn lại.') }

  return url
}

/**
 * =====================================================================
 * Input: asset, alt và caption. Output: img hoặc figure/figcaption escaped;
 * data-media-asset-id giữ nguyên asset.id, không thêm srcset/event handler.
 * =====================================================================
 */
export function inlineImageHtml(asset, alt = '', caption = '') {
  const image = document.createElement('img')

  image.setAttribute('src', inlineAssetUrl(asset))
  image.setAttribute('data-media-asset-id', String(asset.id))
  image.setAttribute('alt', alt)
  if (!caption.trim()) return image.outerHTML
  const figure = document.createElement('figure')
  const text = document.createElement('figcaption')

  figure.className = 'image'
  text.textContent = caption.trim()
  figure.append(image, text)

  return figure.outerHTML
}

/**
 * =====================================================================
 * Input: HTML editor. Output: true khi còn blob/base64 chưa upload; không chạy HTML.
 * =====================================================================
 */
export function hasTemporaryImages(html) {
  const dom = new DOMParser().parseFromString(String(html || ''), 'text/html')

  return [...dom.querySelectorAll('img')].some(image => /^(?:blob:|data:)/i.test(image.getAttribute('src') || ''))
}
