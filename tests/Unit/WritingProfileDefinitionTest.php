<?php

namespace Tests\Unit;

use App\Exceptions\AiImportException;
use App\Services\Ai\WritingProfiles\WritingProfileDefinition;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm tra bộ validate giữ đủ quy tắc văn phong hợp lệ.
 * =====================================================================
 * Khóa lỗi Laravel loại các field tùy chọn khi chỉ validate ba field bắt buộc.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - test_analysis_preserves_every_allowed_rule_and_list(): giữ đủ 16 field.
 * - test_analysis_rejects_unknown_optional_rules(): chặn key ngoài whitelist.
 * - test_analysis_rejects_invalid_optional_rules(): chặn giá trị lồng sai kiểu.
 * - analysisOutput(): tạo kết quả có đầy đủ quy tắc và trích đoạn thật.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : output AI giả và bài tham khảo có trích đoạn đối chiếu.
 * - OUTPUT: assertions về dữ liệu được giữ và lỗi output không hợp lệ.
 * - SIDE EFFECT: không ghi database, queue hoặc gọi provider AI.
 * =====================================================================
 */
final class WritingProfileDefinitionTest extends TestCase
{
    private const SOURCE = 'Bạn đang mất thời gian tìm lỗi? Hãy bắt đầu từ thông báo đầu tiên.';

    /**
     * Giữ mọi field được phép, cả danh sách và danh sách điểm chưa chắc rỗng.
     *
     * Input: output có đủ 16 field, trong đó chỉ ba field là bắt buộc.
     * Output: result giữ nguyên rules, evidence và hướng dẫn đã validate.
     */
    public function test_analysis_preserves_every_allowed_rule_and_list(): void
    {
        $output = $this->analysisOutput();

        $result = app(WritingProfileDefinition::class)->validate($output, self::SOURCE);

        $this->assertCount(count(WritingProfileDefinition::RULE_KEYS), $result['rules']);
        $this->assertSame($output, $result);
    }

    /**
     * Không âm thầm loại một quy tắc không thuộc contract trước khi kiểm tra.
     *
     * Input: output có key tùy chọn không nằm trong whitelist.
     * Output: AiImportException; không trả result thiếu field để che lỗi.
     */
    public function test_analysis_rejects_unknown_optional_rules(): void
    {
        $output = $this->analysisOutput();
        unset($output['rules']['uncertainties']);
        $output['rules']['unknown_rule'] = 'Quy tắc ngoài contract.';
        $this->expectException(AiImportException::class);

        app(WritingProfileDefinition::class)->validate($output, self::SOURCE);
    }

    /**
     * Kiểm tra giá trị field tùy chọn trước khi trả kết quả phân tích.
     *
     * Input: field vocabulary có danh sách lồng, không phải chuỗi/danh sách chuỗi.
     * Output: AiImportException theo cùng contract với field bắt buộc.
     */
    public function test_analysis_rejects_invalid_optional_rules(): void
    {
        $output = $this->analysisOutput();
        $output['rules']['vocabulary'] = [['nested' => 'Không hợp lệ']];
        $this->expectException(AiImportException::class);

        app(WritingProfileDefinition::class)->validate($output, self::SOURCE);
    }

    /**
     * Tạo fixture đủ mọi loại quy tắc với trích đoạn có trong bài tham khảo.
     *
     * Input: không có.
     * Output: output mới gồm chuỗi, danh sách và danh sách uncertainties rỗng.
     */
    private function analysisOutput(): array
    {
        return [
            'summary' => 'Giải thích trực tiếp và gần gũi.',
            'rules' => [
                'tone' => 'Gần gũi',
                'pronouns' => 'Gọi người đọc là bạn',
                'emotion' => 'Bình tĩnh',
                'opening' => 'Nêu vấn đề',
                'sentence_rhythm' => 'Câu ngắn phối hợp giải thích',
                'paragraph_rhythm' => 'Mỗi đoạn tập trung một ý',
                'transitions' => 'Dẫn từ vấn đề sang bước thực hiện',
                'vocabulary' => 'Dùng từ phổ thông',
                'technical_terms' => 'Dùng thuật ngữ khi cần',
                'structure_patterns' => ['Nêu vấn đề', 'Hướng dẫn thực hiện'],
                'headings' => 'Tiêu đề mô tả nội dung',
                'bullets' => 'Dùng danh sách cho các bước',
                'examples' => 'Ví dụ theo tình huống',
                'ending' => 'Gợi ý bước tiếp theo',
                'avoid' => ['Tránh lặp lại thông tin'],
                'uncertainties' => [],
            ],
            'evidence' => [['feature' => 'opening', 'excerpt' => 'Bạn đang mất thời gian tìm lỗi?', 'explanation' => 'Mở bằng vấn đề của người đọc.']],
            'style_instructions' => 'Nêu vấn đề cụ thể và diễn đạt tự nhiên.',
        ];
    }
}
