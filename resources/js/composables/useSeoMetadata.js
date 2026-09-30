/**
 * =====================================================================
 * CHỨC NĂNG FILE: Composable phân tích metadata SEO dùng chung cho form nội dung.
 * =====================================================================
 *
 * Composable nhận ref, reactive object hoặc getter để Post, Resource và các
 * model tương lai dùng chung engine checklist mà không sao chép state SEO.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - useSeoMetadata(): tạo computed contentAnalysis và seoAnalysis
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : form metadata, slug, trạng thái kiểm tra slug, URL và content.
 * - OUTPUT: computed thống kê nội dung và kết quả checklist SEO.
 * =====================================================================
 */
import { computed, toValue } from 'vue'
import { analyzeContent, analyzeSeo } from './seoMetadata'

/**
 * Input: các nguồn reactive hoặc getter của form nội dung.
 * Output: computed contentAnalysis và seoAnalysis dùng cho settings/analysis/preview.
 */
export function useSeoMetadata({ form, slug, checked, url, content, origin }) {
  const contentAnalysis = computed(() => {
    const html = content === undefined ? toValue(form)?.content : toValue(content)
    const baseUrl = toValue(origin) || window.location.origin

    return analyzeContent(html || '', baseUrl)
  })

  const seoAnalysis = computed(() => analyzeSeo({
    form: toValue(form),
    slug: toValue(slug) || '',
    checked: Boolean(toValue(checked)),
    url: toValue(url) || '',
    content: contentAnalysis.value,
  }))

  return { contentAnalysis, seoAnalysis }
}
