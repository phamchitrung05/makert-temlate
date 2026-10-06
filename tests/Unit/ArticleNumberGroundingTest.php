<?php

namespace Tests\Unit;

use App\Exceptions\AiImportException;
use App\Services\Ai\Content\ArticleSourceExtractor;
use App\Services\Ai\Content\Quality\ArticleQualityGate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Regression các lỗi số liệu thực tế và các ca đổi giá trị.
 * =====================================================================
 * Không sửa study hoặc dùng kết quả gate làm điểm văn phong.
 * CÁC HÀM/METHOD TRONG FILE:
 * - test_equivalent_notations_preserve_values(): chấp nhận cách viết tương đương.
 * - equivalentNumbers(): cung cấp ca ngày/giờ/thế kỷ/số đếm/nhãn liệt kê.
 * - test_changed_values_are_not_hidden_by_unrelated_numbers(): chặn đổi giá trị.
 * - changedNumbers(): cung cấp ca mất hoặc thay đổi số liệu có ý nghĩa.
 * - test_saved_c_candidates_pass_without_mutating_historical_study(): kiểm corpus cũ.
 * - test_new_q33_table_candidate_preserves_grouped_integer_counts(): kiểm số đếm bảng.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : nguồn/output cố định và artifacts lịch sử chỉ đọc.
 * - OUTPUT: gate pass/blocked; không gọi HTTP/model hoặc ghi database.
 * =====================================================================
 * =====================================================================
 */
final class ArticleNumberGroundingTest extends TestCase
{
    /** Input: các cách viết tương đương. Output: giữ số liệu mà không đổi HTML. */
    #[DataProvider('equivalentNumbers')]
    public function test_equivalent_notations_preserve_values(string $sourceText, string $outputText): void
    {
        $source = (new ArticleSourceExtractor)->snapshot('<p>'.$sourceText.'</p>', []);
        $output = ['content_html' => '<p>'.$outputText.'</p>'];
        $checks = (new ArticleQualityGate)->inspect($source, $output, 'vi', ['knowledge' => ['facts' => [['important' => true, 'evidence' => $sourceText]]]]);
        $this->assertSame('pass', array_column($checks, 'status', 'check')['important_numbers']);
        $this->assertSame('<p>'.$outputText.'</p>', $output['content_html']);
    }

    /** Input: không có. Output: các ca ngày/giờ/thế kỷ/số đếm có ngữ cảnh. */
    public static function equivalentNumbers(): array
    {
        return [
            'padded day and month' => ['Lễ khánh thành diễn ra sáng 9/3.', 'Buổi lễ được tổ chức sáng 09/03/2025.'],
            'long Vietnamese date' => ['Áp dụng từ ngày 1/3/2010.', 'Thông báo áp dụng từ ngày 01 tháng 03 năm 2010.'],
            'English month name' => ['Support ended on Dec 31, 2023.', 'Hỗ trợ đã kết thúc vào ngày 31/12/2023.'],
            'short source year retains its suffix' => ['Ngày khởi hành: 03/04/17.', 'Bảng nguồn ghi khởi hành ngày 03/04/2017.'],
            '12-hour to 24-hour time' => ['25/11/2025 3:15 PM', 'Bản tin đăng ngày 25/11/2025 lúc 15:15.'],
            'midnight and midday' => ['Lúc 12:00 AM và 12:00 PM.', 'Khung giờ bắt đầu lúc 00:00 và kết thúc lúc 12h00.'],
            'Roman century' => ['Its 16th century heyday.', 'Thương cảng hưng thịnh ở thế kỷ XVI.'],
            'written English ordinal' => ['A trading port in the second century.', 'Thương cảng có từ thế kỷ II.'],
            'word group count' => ['4 nhóm chương trình chính.', 'Chương trình tập trung vào bốn nhóm.'],
            'written topic count' => ['Thảo luận về 3 nội dung.', 'Phiên họp tập trung vào ba nội dung.'],
            'written topic count with nonbreaking spaces' => ['Thảo luận về 3 nội dung.', "Phiên họp tập trung vào ba\u{00A0}nội\u{00A0}dung."],
            'reordered topic retains its regulation number' => ['Nội dung thứ 2 là Quy định 15/2006 về đảng viên làm kinh tế tư nhân.', 'Một trong ba nội dung là Quy định 15/2006 về đảng viên làm kinh tế tư nhân.'],
            'third topic retains its resolution number' => ['Nội dung thứ 3 là tổng kết Nghị quyết 26/2018.', 'Hội nghị thảo luận tổng kết Nghị quyết 26/2018.'],
            'explicit two days by enumeration' => ['02 ngày trong tuần vào Thứ ba và Thứ sáu.', 'Miễn phí vào Thứ ba và Thứ sáu.'],
            'English and Vietnamese check run grouping' => ['A check suite supports 50,000 check runs.', 'Mỗi suite có 50.000 check run.'],
            'runner integer grouping' => ['10,000 runners per group.', 'Mỗi nhóm có tối đa 10.000 runner.'],
            'runner maximum remains an integer count' => ['Normally 1,000 max for Linux CPU runners.', 'Linux CPU larger runners có mức tối đa thông thường là 1.000.'],
            'exact table evidence has adjacent cell text' => ['Check suite50,000 check runs / suite.', 'Mỗi suite có 50.000 check run.'],
            'exact table evidence has adjacent following cell' => ['Runner group10,000 runnersRunners registered at the same time.', 'Mỗi nhóm có 10.000 runner.'],
        ];
    }

    /** Input: đổi giá trị/ngữ cảnh và số đánh lạc hướng. Output: vẫn chặn. */
    #[DataProvider('changedNumbers')]
    public function test_changed_values_are_not_hidden_by_unrelated_numbers(string $sourceText, string $outputText): void
    {
        $source = (new ArticleSourceExtractor)->snapshot('<p>'.$sourceText.'</p>', []);
        try {
            (new ArticleQualityGate)->inspect($source, ['content_html' => '<p>'.$outputText.'</p>'], 'vi', ['knowledge' => ['facts' => [['important' => true, 'evidence' => $sourceText]]]]);
            $this->fail('A changed value must not match.');
        } catch (AiImportException $exception) {
            $this->assertSame('important_number_missing', $exception->diagnostics['validation_errors'][0]['reason']);
        }
    }

    /** Input: không có. Output: ca âm chống mất thời điểm, số lượng, phiên bản. */
    public static function changedNumbers(): array
    {
        return [
            'changed month' => ['Sáng 9/3.', 'Sự kiện sáng 09/04; có 3 chương trình.'],
            'changed year with distractor' => ['Ngày 9/3/2025.', 'Sự kiện ngày 09/03/2024; một hoạt động khác trong năm 2025.'],
            'explicit century of a year still required' => ['Ngày 9/3/2025.', 'Sự kiện ngày 09/03/1925; một hoạt động khác trong năm 2025.'],
            'changed short-year suffix' => ['Ngày 03/04/17.', 'Bảng nguồn ghi ngày 03/04/2018.'],
            'changed PM to AM' => ['3:15 PM', 'Thông báo lúc 03:15 AM, gồm 15 dòng.'],
            'changed minutes' => ['15:15', 'Thông báo lúc 15:30 và 30:15.'],
            'invalid 12-hour notation' => ['3:15 PM', 'Thông báo lúc 15:15 PM.'],
            'changed century with distractor' => ['16th century', 'Thương cảng ở thế kỷ XIV có 16 di tích.'],
            'invalid Roman notation' => ['3rd century', 'Thương cảng ở thế kỷ IIIX.'],
            'component of large count' => ['4 nhóm.', 'Có hai mươi bốn nhóm.'],
            'component of decimal count' => ['4 nhóm.', 'Có hai phẩy bốn nhóm.'],
            'changed topic count' => ['Thảo luận 3 nội dung.', 'Thảo luận bốn nội dung.'],
            'component of large topic count' => ['Thảo luận 3 nội dung.', 'Thảo luận mười ba nội dung.'],
            'fractional topic count' => ['Thảo luận 3 nội dung.', 'Thảo luận ba nội dung rưỡi.'],
            'reordered topic still requires its regulation number' => ['Nội dung thứ 2 là Quy định 15/2006.', 'Một trong ba nội dung là Quy định 16/2006.'],
            'conference ordinal is factual' => ['Hội nghị lần thứ 4 diễn ra.', 'Hội nghị lần thứ 3 diễn ra.'],
            'clause ordinal is factual' => ['Điều khoản thứ 2 được áp dụng.', 'Điều khoản thứ 3 được áp dụng.'],
            'changed weekday condition' => ['02 ngày vào Thứ ba và Thứ sáu.', 'Miễn phí 2 ngày vào Thứ ba và Thứ năm.'],
            'duplicate weekday' => ['02 ngày vào Thứ ba và Thứ sáu.', 'Miễn phí 2 ngày vào Thứ ba và Thứ ba.'],
            'version is not a decimal or padded count' => ['Phiên bản 2.10.', 'Chỉ hỗ trợ phiên bản 2.1.'],
            'three decimal currency digits are not inferred as integer count' => ['Giá trị 2,090 USD.', 'Giá trị 2090 USD.'],
            'three digit version suffix remains exact' => ['Phiên bản 2.090.', 'Phiên bản 2090.'],
            'version prefix beside a count unit is protected' => ['Bản v2.090 runners.', 'Bản v2090 runners.'],
            'written Vietnamese version prefix beside a count unit' => ['Phiên bản 2.090 runners.', 'Phiên bản 2090 runners.'],
            'written English version prefix beside a count unit' => ['Version 2,090 runners.', 'Version 2090 runners.'],
        ];
    }

    /** Input: 19 candidates C thật. Output: kiểm lại offline, không đổi trạng thái cũ. */
    public function test_saved_c_candidates_pass_without_mutating_historical_study(): void
    {
        $root = base_path('docs/qa/task2-quality');
        $manifest = json_decode(file_get_contents($root.'/corpus-v2/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['cases'] as $case) {
            $id = $case['case_id'];
            $path = $root.'/study-2026-10-05-corpus-v2/'.$id.'-C.json';
            $before = hash_file('sha256', $path);
            $artifact = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if ($id === 'Q33') {
                $this->assertSame('AI_PROVIDER_TIMEOUT', $artifact['error_code']);
                $this->assertNull($artifact['candidate_for_review'] ?? null);

                continue;
            }
            $source = json_decode(file_get_contents($root.'/corpus-v2/'.$case['source_snapshot_path']), true, flags: JSON_THROW_ON_ERROR);
            $final = $artifact['candidate_for_review'] ?? $artifact['final'];
            $checks = (new ArticleQualityGate)->inspect($source, $final, 'vi', $artifact['calls'][0]['output']);
            $this->assertSame('pass', array_column($checks, 'status', 'check')['important_numbers'], $id);
            $this->assertSame($before, hash_file('sha256', $path), $id);
        }
    }

    /** Input: Q33 C mới đã chạy đủ ba bước. Output: gate offline, giữ lỗi phiên gốc. */
    public function test_new_q33_table_candidate_preserves_grouped_integer_counts(): void
    {
        $root = base_path('docs/qa/TASK2_COMPLETE_2026-10-05');
        $path = $root.'/Q33-C-600s/Q33-C.json';
        $before = hash_file('sha256', $path);
        $artifact = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $source = json_decode(file_get_contents($root.'/Q33-corpus/Q33.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(3, $artifact['calls']);
        $checks = (new ArticleQualityGate)->inspect($source, $artifact['candidate_for_review'], 'vi', $artifact['calls'][0]['output']);
        $this->assertSame('pass', array_column($checks, 'status', 'check')['important_numbers']);
        $this->assertSame('failed', $artifact['status']);
        $this->assertSame($before, hash_file('sha256', $path));
    }
}
