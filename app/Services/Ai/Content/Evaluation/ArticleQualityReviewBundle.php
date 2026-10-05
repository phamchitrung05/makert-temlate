<?php

namespace App\Services\Ai\Content\Evaluation;

use App\Services\Ai\Content\AiContentSanitizer;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Render form chấm mù độc lập, không lộ nhánh/model/usage.
 * CÁC HÀM/METHOD: render(), input(), text().
 * INPUT/OUTPUT: corpus, outputs, key, reviewer slot -> HTML chứa nhãn X/Y.
 * SIDE EFFECT: không ghi file/AI/DB; sanitize mọi HTML nguồn/model.
 * =====================================================================
 */
final class ArticleQualityReviewBundle
{
    /**
     * =====================================================================
     * Input: cùng bản nguồn, brief và output gốc theo thứ tự nhãn đã freeze.
     * Output: HTML offline, form trống; slot không thay danh tính người chấm.
     * =====================================================================
     */
    public function render(array $manifest, array $sources, array $artifacts, array $key, string $reader, string $bundleHash, string $styleInstructions = ''): string
    {
        $fields = (new ArticleQualityHumanReview)->fields();
        $html = '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; script-src &#39;self&#39;; style-src &#39;self&#39;; img-src &#39;none&#39;; connect-src &#39;none&#39;; base-uri &#39;none&#39;; form-action &#39;none&#39;"><title>Chấm bài độc lập</title><link rel="stylesheet" href="review.css"></head><body>';
        $html .= '<header><div><span class="eyebrow">BỘ CHẤM ĐỘC LẬP · '.$this->text($reader).'</span><h1>Đọc nguồn. Chấm từng bài.</h1></div><p>Chấm độc lập trước khi xem báo cáo thử nghiệm. Đối chiếu dữ kiện trước khi đánh giá diễn đạt; để trống khi chưa đọc.</p></header>';
        $html .= '<nav><label>Người chấm <input id="reviewer-name" placeholder="Nhập tên hoặc mã người chấm riêng" maxlength="120"></label><label>Chọn nguồn <select id="case-picker">';
        foreach ($manifest['cases'] as $case) {
            $html .= '<option value="'.$this->text($case['case_id']).'">'.$this->text($case['case_id'].' · '.$case['group']).'</option>';
        }
        $html .= '</select></label><span id="progress" role="status"></span></nav><main>';
        $html .= '<details class="source"><summary>Văn phong được yêu cầu và hướng dẫn chấm</summary><p>'.$this->text($styleInstructions).'</p><ul><li>Tiếng Việt tự nhiên: 1 dịch sát, gượng; 3 còn đoạn gượng; 5 tự nhiên, ít cần sửa.</li><li>Rõ và hữu ích: 1 khó hiểu/thiếu bối cảnh; 3 cần thêm giải thích; 5 người đọc mục tiêu hiểu và biết dùng thông tin.</li><li>Cấu trúc: 1 heading/mở/kết ép buộc; 3 dùng được nhưng có phần thừa; 5 theo loại bài, chuyển ý hợp lý.</li><li>Ít lặp: 1 lặp và kéo dài; 3 còn phần có thể cắt; 5 mỗi đoạn có thông tin/công dụng.</li><li>Brief/văn phong: 1 bỏ yêu cầu, lấy facts bài mẫu; 3 còn lệch audience/giọng; 5 ưu tiên brief, profile linh hoạt.</li></ul><p>2 và 4 là mức giữa. Lỗi dữ kiện là gate riêng; điểm diễn đạt tốt không bù lỗi critical/major. Chấm độc lập rồi mới trao đổi.</p></details>';
        $scoreLabels = ['naturalness_1_5' => 'Tiếng Việt tự nhiên', 'usefulness_1_5' => 'Rõ ràng và hữu ích', 'structure_1_5' => 'Cấu trúc phù hợp', 'repetition_1_5' => 'Ít lặp và filler', 'brief_style_1_5' => 'Đúng brief/văn phong'];
        $countLabels = ['critical_errors' => 'Lỗi nghiêm trọng (critical)', 'major_errors' => 'Lỗi lớn (major)', 'minor_errors' => 'Lỗi nhỏ (minor)', 'important_facts_expected' => 'Dữ kiện quan trọng từ nguồn', 'important_facts_preserved' => 'Dữ kiện giữ đúng', 'fact_edits' => 'Số sửa dữ kiện', 'expression_edits' => 'Số sửa câu chữ', 'paragraphs_added' => 'Số đoạn thêm', 'paragraphs_removed' => 'Số đoạn xóa'];
        foreach ($manifest['cases'] as $index => $case) {
            $id = $case['case_id'];
            $source = $sources[$id];
            $html .= '<section class="case" data-case="'.$this->text($id).'"'.($index ? ' hidden' : '').'><h2>'.$this->text($id.' · '.$case['group']).'</h2><div class="brief">';
            foreach (['audience' => 'Người đọc', 'purpose' => 'Mục đích', 'length' => 'Độ dài', 'source_scope' => 'Phạm vi nguồn'] as $field => $name) {
                if (isset($case['writing_brief'][$field])) {
                    $html .= '<p><strong>'.$name.':</strong> '.$this->text((string) $case['writing_brief'][$field]).'</p>';
                }
            }
            $html .= '</div>';
            $html .= '<p class="scope">'.$this->text(($case['attribution'] ?? '').' · '.($case['selection'] ?? '').' · '.($case['notes'] ?? '')).'</p><details class="source"><summary>Đọc bản nguồn và các đoạn dẫn chứng</summary><div class="source-body">'.(new AiContentSanitizer)->sanitize($source['content_html']).'<hr><h3>Dẫn chứng từ nguồn · chưa có facts chuẩn đã duyệt</h3>';
            foreach ($source['blocks'] as $block) {
                $html .= '<p><strong>'.$this->text($block['id']).'</strong> · '.$this->text($block['text']).'</p>';
            }
            if (($source['source_images'] ?? []) !== []) {
                $html .= '<h3>Thông tin ảnh nguồn</h3><p>Chỉ có URL, alt và chú thích. Không đánh giá pixel hoặc giả rằng ảnh đã được gắn vào bài.</p>';
                foreach ($source['source_images'] as $image) {
                    $html .= '<p>'.$this->text(($image['alt'] ?? '').' · '.($image['context'] ?? '').' · '.($image['source_url'] ?? '')).'</p>';
                }
            }
            $paired = count($key[$id]) > 1;
            $html .= '</div></details>';
            if ($paired) {
                $html .= '<label class="preference">Sau khi đọc cả hai, bài nào tốt hơn? <select data-preference="'.$this->text($id).'"><option value="">Chưa chọn</option><option value="X">X</option><option value="Y">Y</option><option value="tie">Ngang nhau</option><option value="undetermined">Chưa xác định</option></select></label>';
            }
            $html .= '<div class="pair'.($paired ? '' : ' single').'">';
            foreach ($key[$id] as $label => $arm) {
                $candidate = $artifacts[$id][$arm]['candidate_for_review'] ?? null;
                $html .= '<article><h3>Bài '.$this->text($label).'</h3><div class="candidate">';
                $html .= $candidate ? '<h4>'.$this->text($candidate['title']).'</h4>'.(new AiContentSanitizer)->sanitize($candidate['content_html']) : '<p>Không có bài để chấm. Giữ ô trống; lỗi kỹ thuật được tổng hợp riêng.</p>';
                $html .= '</div><form data-row="'.$this->text($id.'-'.$label).'" data-case="'.$this->text($id).'" data-label="'.$this->text($label).'" data-available="'.($candidate ? 'yes' : 'no').'"><fieldset'.($candidate ? '' : ' disabled').'><legend>Đối chiếu dữ kiện và công sửa</legend><label class="check"><input type="checkbox" data-field="source_facts_confirmed"> Tôi đã lập/đối chiếu dữ kiện quan trọng từ bản nguồn, không lấy Analyzer làm đáp án</label><label>Gate chính xác <select data-field="accuracy_gate"><option value="">Chưa chấm</option><option value="pass">Đạt</option><option value="fail">Không đạt</option><option value="undetermined">Chưa xác định</option></select></label><div class="fields">';
                foreach ($countLabels as $field => $name) {
                    $html .= $this->input($field, $name, 'number', '0', '1000000');
                }
                $html .= $this->input('editing_minutes', 'Phút sửa thực tế', 'number', '0', '1000000', '0.1').'</div>';
                foreach (['important_fact_evidence' => 'Dữ kiện cần giữ: mã đoạn nguồn → claim/điều kiện', 'facts_errors_and_severity' => 'Lỗi: dẫn chứng nguồn → câu output → mức độ → cách sửa', 'coverage_notes' => 'Dữ kiện thiếu hoặc lệch phạm vi', 'preservation_notes' => 'Ghi chú code, link, bảng và chú thích ảnh'] as $field => $name) {
                    $html .= '<label>'.$name.'<textarea data-field="'.$field.'" placeholder="Ghi dẫn chứng cụ thể; để trống khi chưa đối chiếu" rows="3"></textarea></label>';
                }
                $html .= '<p class="hint">Phút sửa và số sửa là số bạn thực sự đo/ghi lại. Không dùng ký tự diff hoặc thời gian chạy model làm công biên tập.</p></fieldset><fieldset'.($candidate ? '' : ' disabled').'><legend>Diễn đạt · 1 kém, 3 dùng được sau sửa, 5 tốt</legend><div class="fields">';
                foreach ($scoreLabels as $field => $name) {
                    $html .= '<label>'.$name.'<select data-field="'.$field.'"><option value="">Chưa chấm</option>';
                    foreach ([1, 2, 3, 4, 5] as $score) {
                        $html .= '<option value="'.$score.'">'.$score.'</option>';
                    }
                    $html .= '</select></label>';
                }
                $html .= '</div><label class="check"><input type="checkbox" data-field="review_state"> Hoàn thành bài này sau khi kiểm đủ dữ kiện và điểm</label></fieldset></form></article>';
            }
            $html .= '</div></section>';
        }
        $config = json_encode(['reader' => $reader, 'bundleHash' => $bundleHash, 'fields' => $fields], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $html .= '</main><footer><p id="save-state" role="status">Lưu nháp trên trình duyệt khi được hỗ trợ; tải CSV để giữ bản chắc chắn.</p><button id="download-csv" type="button">Tải bảng chấm CSV</button><button id="preview-csv" type="button">Xem CSV để sao chép</button><p id="review-error" role="alert"></p><label id="csv-preview-label" hidden>Nội dung CSV · chọn tất cả và lưu file UTF-8 nếu trình duyệt chặn tải<textarea id="csv-preview" readonly rows="4"></textarea></label></footer><script type="application/json" id="review-config">'.$config.'</script><script src="review.js" defer></script></body></html>';

        return $html;
    }

    /**
     * =====================================================================
     * Input: tên field/label/giới hạn. Output: input rỗng, 0 là nhập chủ động.
     * =====================================================================
     */
    private function input(string $field, string $label, string $type, string $min, string $max, string $step = '1'): string
    {
        return '<label>'.$label.'<input data-field="'.$field.'" type="'.$type.'" min="'.$min.'" max="'.$max.'" step="'.$step.'" placeholder="Chưa ghi"></label>';
    }

    /**
     * =====================================================================
     * Input: dữ liệu không tin cậy. Output: text HTML escaped.
     * =====================================================================
     */
    private function text(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
