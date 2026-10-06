/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo markup ảnh bằng URL; candidate AI có thể giữ ref nội bộ để kiểm nguồn.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: inlineAssetUrl(), inlineUrlImageHtml(), inlineImageHtml(), hasTemporaryImages().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): asset/alt/caption -> HTML escaped/ref ổn định.
 * SIDE EFFECT: DOM tách rời; ảnh trong Post chỉ lưu link, không tạo usage.
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
  return imageHtml(inlineAssetUrl(asset), alt, caption, asset.id)
}

/** Input: link/alt/caption. Output: HTML ảnh Post không có ID hoặc quan hệ MediaAsset. */
export function inlineUrlImageHtml(url, alt = '', caption = '') {
  const value = String(url ?? '').trim()
  const hasUnsafeCharacter = [...value].some(character => character.charCodeAt(0) <= 32 || character.charCodeAt(0) === 127)

  try {
    if (!value || hasUnsafeCharacter || value.includes('\\')
      || (!value.startsWith('/') && !/^https?:\/\//i.test(value))
      || !['http:', 'https:'].includes(new URL(value, 'https://url-validation.example').protocol))
      throw new Error('URL')
  }
  catch { throw new Error('Nhập link ảnh HTTP/HTTPS hoặc đường dẫn từ gốc website.') }

  return imageHtml(value, alt, caption)
}

/** Input: URL đã kiểm và metadata. Output: img/figure escaped; ref chỉ dành cho candidate AI. */
function imageHtml(url, alt, caption, assetId = null) {
  const image = document.createElement('img')

  image.setAttribute('src', url)
  if (assetId !== null) image.setAttribute('data-media-asset-id', String(assetId))
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
