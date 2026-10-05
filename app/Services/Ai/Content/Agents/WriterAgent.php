<?php

namespace App\Services\Ai\Content\Agents;

use App\Services\Ai\Content\Prompts\ArticlePromptBuilder;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Data\AiTaskResponse;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Bước viết bản nháp từ facts/plan/profile và source anchors.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: run().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : knowledge/plan đã validate cùng source và brief.
 * - OUTPUT: draft field được chọn và refs; gọi AI, không ghi Post.
 * =====================================================================
 */
final class WriterAgent
{
    /**
     * =====================================================================
     * Input: provider và context nguồn/facts/plan của run.
     * Output: response writer đúng schema; có thể ném lỗi provider.
     * =====================================================================
     */
    public function run(AiProviderContract $provider, array $context, array $groups, array $settings): AiTaskResponse
    {
        return $provider->execute((new ArticlePromptBuilder)->build('article.writer', $context, $groups, $settings));
    }
}
