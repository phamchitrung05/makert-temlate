<?php

namespace Tests\Unit;

use App\Services\Ai\Content\ArticleSourceExtractor;
use PHPUnit\Framework\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Regression nhận vùng bài HTML thay vì section footer/menu.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - test_named_div_body_wins_over_footer_and_longer_unrelated_section().
 * - test_schema_article_body_wins_over_longer_section().
 * - test_common_body_labels_are_recognized_without_site_specific_selectors().
 * - test_unnamed_prose_div_wins_over_link_heavy_section().
 * - test_empty_named_body_does_not_hide_valid_main().
 * - test_copyright_inside_article_remains_part_of_source().
 * - test_footer_only_html_does_not_become_an_article().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTML tổng hợp tái hiện layout div/form và footer section.
 * - OUTPUT: assertions vùng bài đầy đủ, an toàn và bỏ chrome.
 * - SIDE EFFECT: chỉ DOM trong memory, không database, HTTP hoặc model.
 * =====================================================================
 */
final class ArticleSourceExtractorTest extends TestCase
{
    /**
     * =====================================================================
     * Input: kiểu layout của file HTML lỗi, phần ngoài bài dài hơn body.
     * Output: giữ đầu/cuối bài/code/ảnh; không chọn footer/sidebar/phần quảng bá.
     * =====================================================================
     */
    public function test_named_div_body_wins_over_footer_and_longer_unrelated_section(): void
    {
        $html = '<html><body><form><div class="site-header"><p>Menu cần bỏ</p></div>'
            .'<div class="wrapper"><div id="article-content" class="max-width-content">'
            .'<p>Mở đầu bài về du thuyền Ovation.</p><p>Royal Caribbean là chủ đề của bài.</p>'
            .'<pre>if (true) {'."\n".'    run();'."\n".'}</pre>'
            .'<img src="./ship.jpg" alt="Tàu"><p>Phần cuối: hệ thống nhà hàng.</p></div>'
            .'<div class="block-sidebar"><p>Sidebar cần bỏ</p></div></div>'
            .'<section><p>'.str_repeat('Thông tin tour khác. ', 100).'</p></section>'
            .'<div class="wrapper footer"><div class="copyright"><section>'
            .'<p>© 2005. GPDKKD: 0303941729.</p></section></div></div></form></body></html>';

        $content = (new ArticleSourceExtractor)->extract($html);

        $this->assertStringContainsString('Mở đầu bài về du thuyền Ovation.', $content);
        $this->assertStringContainsString('Phần cuối: hệ thống nhà hàng.', $content);
        $this->assertStringContainsString("\n    run();\n", $content);
        $this->assertStringContainsString('src="./ship.jpg"', $content);
        $this->assertStringNotContainsString('GPDKKD', $content);
        $this->assertStringNotContainsString('Sidebar cần bỏ', $content);
        $this->assertStringNotContainsString('Menu cần bỏ', $content);
        $this->assertStringNotContainsString('Thông tin tour khác', $content);
    }

    /**
     * =====================================================================
     * Input: body dùng schema.org, section ngoài bài có nhiều text hơn.
     * Output: chọn articleBody ở tag bất kỳ và loại phần ngoài vùng bài.
     * =====================================================================
     */
    public function test_schema_article_body_wins_over_longer_section(): void
    {
        $html = '<section><p>'.str_repeat('Thông tin ngoài bài. ', 60).'</p></section>'
            .'<div itemprop="description articleBody"><p>Nội dung bài theo schema.</p></div>';

        $content = (new ArticleSourceExtractor)->extract($html);

        $this->assertStringContainsString('Nội dung bài theo schema.', $content);
        $this->assertStringNotContainsString('Thông tin ngoài bài', $content);
    }

    /**
     * =====================================================================
     * Input: các nhãn body phổ biến với dấu nối/gạch dưới.
     * Output: mỗi nhãn ưu tiên bài, không cần hardcode host Startravel.
     * =====================================================================
     */
    public function test_common_body_labels_are_recognized_without_site_specific_selectors(): void
    {
        foreach (['entry-content', 'post__body', 'article_content', 'js-story-body', 'news-content'] as $label) {
            $html = '<section><p>'.str_repeat('Phần ngoài. ', 60).'</p></section>'
                .'<div class="'.$label.'"><p>Bài cần giữ.</p></div>';

            $content = (new ArticleSourceExtractor)->extract($html);

            $this->assertStringContainsString('Bài cần giữ.', $content, $label);
            $this->assertStringNotContainsString('Phần ngoài', $content, $label);
        }
    }

    /**
     * =====================================================================
     * Input: không có nhãn body, section menu chứa nhiều link hơn prose.
     * Output: fallback chọn div chứa các đoạn văn, không ưu tiên text link dài.
     * =====================================================================
     */
    public function test_unnamed_prose_div_wins_over_link_heavy_section(): void
    {
        $html = '<section>'.str_repeat('<a href="https://example.test">Mục điều hướng rất dài</a>', 100).'</section>'
            .'<div><p>Đoạn mở bài giữ lại.</p><p>Đoạn cuối bài giữ lại.</p></div>'
            .'<div role="contentinfo"><p>Bản quyền website.</p></div>';

        $content = (new ArticleSourceExtractor)->extract($html);

        $this->assertStringContainsString('Đoạn mở bài giữ lại.', $content);
        $this->assertStringContainsString('Đoạn cuối bài giữ lại.', $content);
        $this->assertStringNotContainsString('điều hướng', $content);
        $this->assertStringNotContainsString('Bản quyền website', $content);
    }

    /**
     * =====================================================================
     * Input: body ưu tiên rỗng/NBSP, main còn prose thật.
     * Output: không chọn container chỉ có khoảng trắng và bỏ nội dung main.
     * =====================================================================
     */
    public function test_empty_named_body_does_not_hide_valid_main(): void
    {
        $html = '<div id="article-content">&nbsp; </div><main><p>Nội dung thật ở main.</p></main>';

        $content = (new ArticleSourceExtractor)->extract($html);

        $this->assertStringContainsString('Nội dung thật ở main.', $content);
    }

    /**
     * =====================================================================
     * Input: bài đang giải thích copyright; đoạn nội dung dùng class copyright.
     * Output: giữ nội dung bài và footnote hợp lệ, không lọc chỉ vì một từ khóa.
     * =====================================================================
     */
    public function test_copyright_inside_article_remains_part_of_source(): void
    {
        $html = '<article><p>Giải thích quyền tác giả.</p><div class="copyright">'
            .'<p>Copyright là chủ đề cần giữ trong bài.</p></div></article>'
            .'<div class="copyright"><section><p>Bản quyền website cần bỏ.</p></section></div>';

        $content = (new ArticleSourceExtractor)->extract($html);

        $this->assertStringContainsString('Copyright là chủ đề cần giữ trong bài.', $content);
        $this->assertStringNotContainsString('Bản quyền website cần bỏ', $content);
    }

    /**
     * =====================================================================
     * Input: tài liệu chỉ có footer dùng div/section.
     * Output: nguồn rỗng để controller trả SOURCE_EMPTY, không giả làm bài.
     * =====================================================================
     */
    public function test_footer_only_html_does_not_become_an_article(): void
    {
        $html = '<html><body><div class="wrapper footer"><div class="copyright">'
            .'<section><p>© 2005. Bản quyền website.</p></section></div></div></body></html>';

        $this->assertSame('', (new ArticleSourceExtractor)->extract($html));
    }
}
