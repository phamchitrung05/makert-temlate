<?php

namespace App\Services\Ai\Content\Agents;

use App\Services\Ai\Content\Prompts\ArticlePromptBuilder;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Data\AiTaskResponse;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Bước đọc hiểu nguồn và lập kế hoạch viết, không viết bài.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: run().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : provider, context nguồn/brief/profile và snapshot cấu hình.
 * - OUTPUT: knowledge/writing_plan; gọi provider một lần, không ghi Post.
 * =====================================================================
 */
final class AnalyzerPlannerAgent
{
    /**
     * =====================================================================
     * Input: provider và dữ liệu source anchors của run.
     * Output: response analysis-plan đúng schema; có thể ném lỗi provider.
     * =====================================================================
     */
    public function run(AiProviderContract $provider, array $context, array $groups, array $settings): AiTaskResponse
    {
        return $provider->execute((new ArticlePromptBuilder)->build('article.analysis-plan', $context, $groups, $settings));
    }
}
