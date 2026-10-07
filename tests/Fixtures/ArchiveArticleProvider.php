<?php

namespace Tests\Fixtures;

use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Data\AiTaskRequest;
use App\Services\Ai\Data\AiTaskResponse;
use RuntimeException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Provider giả kiểm archive với pipeline Analyze/Write/Edit thật.
 * =====================================================================
 *
 * Provider giả dành cho kiểm kho với pipeline Analyze/Write/Edit thật. Output/usage được dựng từ fixture để kiểm trace mà không phát sinh request hoặc chi phí AI.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - configured().
 * - providerName().
 * - modelName().
 * - execute().
 * - generate().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : AiTaskRequest fixture thuộc pipeline ba bước.
 * - OUTPUT: DTO/schema output và usage giả dành riêng cho test.
 * - SIDE EFFECT: Chỉ đếm calls trong memory; không HTTP/DB.
 * - EXCEPTION/TRANSACTION: Task lạ hoặc generate legacy ném RuntimeException; không mở transaction.
 * =====================================================================
 */
final class ArchiveArticleProvider implements AiProviderContract
{
    public int $calls = 0;

    /**
     * =====================================================================
     * CHỨC NĂNG: Báo provider fixture sẵn sàng trong kiểm thử
     * =====================================================================
     *
     * INPUT:
     * - Không có; provider chỉ dùng trong test.
     *
     * OUTPUT:
     * - bool true.
     *
     * SIDE EFFECT:
     * - Chỉ trả cấu hình fixture; không DB/HTTP.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction hoặc gọi AI thật.
     *
     * =====================================================================
     */
    public function configured(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả định danh provider fixture dùng đối chiếu provenance
     * =====================================================================
     *
     * INPUT:
     * - Không có.
     *
     * OUTPUT:
     * - Tên provider cố định cho fixture của class.
     *
     * SIDE EFFECT:
     * - Chỉ đọc identity trong memory; không I/O.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction hoặc tra catalog thật.
     *
     * =====================================================================
     */
    public function providerName(): string
    {
        return 'archive-fixture';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả định danh model fixture dùng đối chiếu metadata
     * =====================================================================
     *
     * INPUT:
     * - Không có.
     *
     * OUTPUT:
     * - Tên model cố định cho fixture của class.
     *
     * SIDE EFFECT:
     * - Chỉ đọc identity trong memory; không I/O.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction hoặc gọi provider thật.
     *
     * =====================================================================
     */
    public function modelName(): string
    {
        return 'archive-fixture-model';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giả lập output Analyze/Write/Edit cho pipeline ba bước
     * =====================================================================
     *
     * INPUT:
     * - AiTaskRequest có task thuộc ba bước pipeline fixture.
     *
     * OUTPUT:
     * - AiTaskResponse đúng schema với output và usage giả dành cho test.
     *
     * SIDE EFFECT:
     * - Tăng calls trong memory; không HTTP/DB hoặc chi phí AI.
     *
     * EXCEPTION/TRANSACTION:
     * - Task lạ ném RuntimeException; không mở transaction.
     *
     * =====================================================================
     */
    public function execute(AiTaskRequest $request): AiTaskResponse
    {
        $this->calls++;
        $fact = 'Version 2.0 supports 100 items and requires a paid plan.';
        $draft = ['title' => 'Bản cập nhật thử', 'content_html' => '<p>Bản 2.0 hỗ trợ tối đa 100 phần tử, yêu cầu gói trả phí.</p>'];
        $output = match ($request->task) {
            'article.analysis-plan' => [
                'knowledge' => ['facts' => [['id' => 'F1', 'claim' => $fact, 'source_block_ids' => ['S001'], 'evidence' => $fact, 'important' => true]], 'terms' => [], 'uncertainties' => []],
                'writing_plan' => ['article_type' => 'release', 'audience' => 'Người mới', 'angle' => 'Giới hạn', 'outline' => ['Thay đổi'], 'coverage_fact_ids' => ['F1']],
            ],
            'article.writer' => ['draft' => $draft, 'used_fact_ids' => ['F1'], 'used_asset_ids' => []],
            'article.editor' => ['final' => $draft, 'used_fact_ids' => ['F1'], 'issues' => []],
            default => throw new RuntimeException('Unexpected fixture task'),
        };

        return new AiTaskResponse($output, ['usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn gọi contract legacy trên fixture chỉ hỗ trợ ba bước
     * =====================================================================
     *
     * INPUT:
     * - Các tham số generate() legacy của AiProviderContract.
     *
     * OUTPUT:
     * - Không trả output; luôn ném lỗi để phát hiện gọi nhầm luồng.
     *
     * SIDE EFFECT:
     * - Không I/O hoặc thay DB.
     *
     * EXCEPTION/TRANSACTION:
     * - Luôn ném RuntimeException; không mở transaction.
     *
     * =====================================================================
     */
    public function generate(string $title, string $content, string $language = 'vi', string $rewriteStyle = 'informative', string $promptKey = 'post.create.from_url', string $instructions = ''): array
    {
        throw new RuntimeException('Fixture requires the three-step pipeline');
    }
}
