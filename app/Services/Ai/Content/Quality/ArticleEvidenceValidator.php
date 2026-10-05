<?php

namespace App\Services\Ai\Content\Quality;

use App\Exceptions\AiImportException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm source anchors và coverage của ledger facts tối thiểu.
 * =====================================================================
 * Không coi khai báo used IDs là chứng minh ngữ nghĩa hay độ đúng của nguồn.
 * CÁC HÀM/METHOD TRONG FILE: analysis(), references(), normalize(), fail().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : source snapshot, knowledge/plan hoặc fact references.
 * - OUTPUT: dữ liệu hợp lệ; ném lỗi với mã/path, không gọi provider/DB.
 * =====================================================================
 */
final class ArticleEvidenceValidator
{
    /**
     * =====================================================================
     * Input: analysis đã kiểm schema và source blocks thật.
     * Output: xác nhận IDs/evidence/plan coverage; AI_SOURCE_REFERENCE nếu sai.
     * =====================================================================
     */
    public function analysis(array $analysis, array $source): void
    {
        $blocks = array_column($source['blocks'], null, 'id');
        $ids = [];
        foreach ($analysis['knowledge']['facts'] as $fact) {
            if (in_array($fact['id'], $ids, true)) {
                $this->fail('duplicate_fact_id');
            }
            $ids[] = $fact['id'];
            $evidence = $this->normalize($fact['evidence']);
            if ($evidence === '') {
                $this->fail('evidence_not_in_source');
            }
            $evidenceMatches = false;
            foreach ($fact['source_block_ids'] as $blockId) {
                if (! isset($blocks[$blockId])) {
                    $this->fail('unknown_source_block');
                }
                $evidenceMatches = $evidenceMatches || str_contains($this->normalize($blocks[$blockId]['text']), $evidence);
            }
            if (! $evidenceMatches) {
                $this->fail('evidence_not_in_source');
            }
        }
        $this->references($analysis['writing_plan']['coverage_fact_ids'], $analysis, requireImportant: true);
    }

    /**
     * =====================================================================
     * Input: IDs Writer/Editor/Plan dùng, analysis và yêu cầu coverage.
     * Output: xác nhận references thật và đủ important facts; không chấm tự báo.
     * =====================================================================
     */
    public function references(array $usedIds, array $analysis, bool $requireImportant = false): void
    {
        $facts = $analysis['knowledge']['facts'];
        if (array_diff($usedIds, array_column($facts, 'id')) !== []) {
            $this->fail('unknown_fact_reference');
        }
        if ($requireImportant) {
            $importantIds = array_column(array_filter($facts, fn (array $fact): bool => $fact['important']), 'id');
            if (array_diff($importantIds, $usedIds) !== []) {
                $this->fail('missing_important_fact_reference');
            }
        }
    }

    /**
     * =====================================================================
     * Input: excerpt/text Unicode.
     * Output: text chuẩn whitespace để kiểm bằng chứng nguyên văn.
     * =====================================================================
     */
    private function normalize(string $text): string
    {
        return trim(preg_replace('/[\s\p{Z}\p{Cf}]+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $text);
    }

    /**
     * =====================================================================
     * Input: mã lý do ref/coverage.
     * Output: luôn ném AI_SOURCE_REFERENCE; chỉ lưu mã, không trích raw source.
     * =====================================================================
     */
    private function fail(string $reason): never
    {
        throw new AiImportException('AI trả bằng chứng hoặc tham chiếu nguồn không hợp lệ.', 'AI_SOURCE_REFERENCE', diagnostics: [
            // Task field dùng nhãn cố định trong allowlist diagnostics, không làm mất lý do.
            'stage' => 'validate', 'validation_errors' => [['group' => 'task', 'field' => 'task_output', 'reason' => $reason]],
        ]);
    }
}
