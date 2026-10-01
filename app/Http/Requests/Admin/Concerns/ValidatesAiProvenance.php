<?php

namespace App\Http\Requests\Admin\Concerns;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chia sẻ validation metadata lineage của candidate AI.
 * =====================================================================
 *
 * Client chỉ được gửi ID run và danh sách field đã chọn. Provider, model,
 * prompt và giá trị audit được server đọc lại từ AiImport sau khi kiểm tra
 * ownership; trait này không cho phép client tự ghi provenance.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - aiProvenanceRules(): khai báo rule cho run và field được áp dụng.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : request Post create/update chưa tin cậy.
 * - OUTPUT: mảng rule Laravel cho cặp ai_run_id/ai_fields.
 * - SIDE EFFECT: không gọi database, provider hoặc ghi audit.
 * =====================================================================
 */
trait ValidatesAiProvenance
{
    /**
     * Khai báo contract lineage tùy chọn cho Post create/update.
     *
     * Input: không có.
     * Output: rule yêu cầu run UUID đi cùng ít nhất một field allowlist.
     * Side effect: không có; FormRequest mới thực thi validation.
     */
    protected function aiProvenanceRules(): array
    {
        return [
            'ai_run_id' => ['nullable', 'uuid', 'required_with:ai_fields'],
            'ai_fields' => ['sometimes', 'array', 'min:1', 'required_with:ai_run_id'],
            'ai_fields.*' => ['string', 'distinct', 'in:title,excerpt,content,seo,taxonomy,thumbnail'],
        ];
    }
}
