<?php

namespace App\Services\Ai\Content\Agents;

use App\Services\Ai\Content\Prompts\ArticlePromptBuilder;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Data\AiTaskResponse;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Bước biên tập bản nháp và đối chiếu facts trong nguồn.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: run().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : draft, knowledge/plan/source và profile đã snapshot.
 * - OUTPUT: final field cùng issues; một lượt AI, không xác minh ngoài nguồn.
 * =====================================================================
 */
final class EditorAgent
{
    /**
     * =====================================================================
     * Input: provider và context có draft của Writer đã validate.
     * Output: response editor đúng schema; có thể ném lỗi provider.
     * =====================================================================
     */
    public function run(AiProviderContract $provider, array $context, array $groups, array $settings): AiTaskResponse
    {
        return $provider->execute((new ArticlePromptBuilder)->build('article.editor', $context, $groups, $settings));
    }
}
