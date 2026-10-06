/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuẩn hóa nội dung so sánh và HTML xem trước của bài AI.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: normalizeText(), textOfHtml(), comparisonBlocks(), previewOfHtml().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): HTML đã lưu -> văn bản, đoạn khác biệt,
 * HTML allowlist; không sửa dữ liệu nguồn hoặc gọi API.
 * SIDE EFFECT: parse trong template DOM rời; không chạy HTML đầu vào.
 * =====================================================================
 */
const blockedTags = 'script,style,iframe,object,embed,svg,math,noscript,form,input,button,textarea,select,link,meta,base'
const allowedTags = new Set('p div span h1 h2 h3 h4 h5 h6 strong em b i u s blockquote ul ol li br hr pre code table caption thead tbody tfoot tr th td img a figure figcaption'.split(' '))

/** Input: văn bản. Output: gộp khoảng trắng để đối chiếu đoạn chính xác. */
const normalizeText = text => text.replace(/\s+/g, ' ').trim()

/** Input: HTML. Output: text giữ đoạn/code, loại nội dung thực thi trong DOM rời. */
export function textOfHtml(html) {
  const template = document.createElement('template')

  template.innerHTML = html || ''
  template.content.querySelectorAll(blockedTags).forEach(node => node.remove())
  template.content.querySelectorAll('br').forEach(node => node.replaceWith('\n'))
  template.content.querySelectorAll('p,div,h1,h2,h3,h4,h5,h6,li,pre,tr,blockquote').forEach(node => node.append('\n\n'))

  return (template.content.textContent || '').replace(/\n{3,}/g, '\n\n').trim()
}

/** Input: text hai phía. Output: các đoạn và dấu khác biệt theo text, chi phí tuyến tính. */
export function comparisonBlocks(text, otherText) {
  const other = new Set((otherText || '').split(/\n\s*\n/).map(normalizeText).filter(Boolean))

  return (text || '').split(/\n\s*\n/).filter(block => block.trim()).map((block, index) => ({
    id: index, text: block, changed: !other.has(normalizeText(block)),
  }))
}

/**
 * Input: HTML và text đối chiếu tùy chọn. Output: HTML allowlist cho preview.
 * Bỏ event/style/URL nguy hiểm; chỉ giữ ảnh/liên kết HTTP(S) và thuộc tính bảng.
 * Text/code được escape bởi DOM serializer; không gắn HTML đầu vào vào trang.
 */
export function previewOfHtml(html, referenceText = null) {
  const template = document.createElement('template')

  template.innerHTML = html || ''
  template.content.querySelectorAll(blockedTags).forEach(node => node.remove())
  for (const node of [...template.content.querySelectorAll('*')]) {
    if (!allowedTags.has(node.localName)) {
      node.replaceWith(...node.childNodes)
      continue
    }
    const original = Object.fromEntries([...node.attributes].map(attribute => [attribute.name, attribute.value]))

    for (const attribute of [...node.attributes]) node.removeAttribute(attribute.name)
    if (original.title) node.setAttribute('title', original.title)
    for (const name of ['colspan', 'rowspan']) {
      if (['td', 'th'].includes(node.localName) && /^[1-9]\d?$/.test(original[name] || '')) node.setAttribute(name, original[name])
    }
    if (['a', 'img'].includes(node.localName)) {
      const attribute = node.localName === 'img' ? 'src' : 'href'
      let url

      try { url = new URL(original[attribute] || '', location.origin) }
      catch { url = null }
      if (!original[attribute] || !url || !['http:', 'https:'].includes(url.protocol)) {
        node.replaceWith(node.localName === 'img' ? original.alt || '' : document.createTextNode(node.textContent || ''))
        continue
      }
      node.setAttribute(attribute, url.href)
      if (node.localName === 'img') {
        node.setAttribute('alt', original.alt || '')
        node.setAttribute('loading', 'lazy')
        node.setAttribute('referrerpolicy', 'no-referrer')
      }
      else {
        node.setAttribute('target', '_blank')
        node.setAttribute('rel', 'noopener noreferrer')
      }
    }
  }
  if (referenceText !== null) {
    const reference = normalizeText(referenceText)

    template.content.querySelectorAll('p,h1,h2,h3,h4,h5,h6,li,pre,tr,blockquote').forEach(node => {
      const text = normalizeText(node.textContent || '')

      if (text && !reference.includes(text) && !node.parentElement?.closest('.ai-comparison-added')) node.classList.add('ai-comparison-added')
    })
  }

  return template.innerHTML
}
