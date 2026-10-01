<?php

namespace App\Console\Commands;

use App\Exceptions\AiImportException;
use App\Models\AiProvider;
use App\Services\Ai\AiProviderCatalogService;
use Illuminate\Console\Command;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đồng bộ catalog định kỳ qua service dùng chung với Admin UI.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: handle().
 * INPUT: providers active dùng models endpoint.
 * OUTPUT: exit code và số lượng model sync; không in endpoint/key/provider body.
 * SIDE EFFECT: service gọi HTTPS và cập nhật catalog/activity log.
 * EXCEPTION/TRANSACTION: service quản lý lock/atomic update; một provider lỗi không dừng provider khác.
 * =====================================================================
 */
final class SyncAiProviderModelsCommand extends Command
{
    protected $signature = 'ai-providers:sync-models';

    protected $description = 'Sync model catalogs for active AI provider connections';

    /**
     * =====================================================================
     * CHỨC NĂNG: Sync lần lượt catalog active mà không xóa catalog khi provider lỗi.
     * =====================================================================
     * INPUT: AiProviderCatalogService từ container.
     * OUTPUT: SUCCESS nếu tất cả provider sync được, FAILURE nếu có lỗi.
     * SIDE EFFECT: gọi GET catalog, ghi models/audit; không gọi generation tính phí.
     * EXCEPTION/TRANSACTION: AiImportException chỉ in safe code; transaction thuộc service.
     * =====================================================================
     */
    public function handle(AiProviderCatalogService $catalog): int
    {
        $failed = false;
        AiProvider::query()->where('is_active', true)->where('discovery_mode', 'models_endpoint')
            ->orderBy('id')->each(function (AiProvider $provider) use ($catalog, &$failed): void {
                try {
                    $result = $catalog->sync($provider);
                    $this->info('Provider #'.$provider->id.': synced '.$result['count'].' models.');
                } catch (AiImportException $exception) {
                    $failed = true;
                    $this->warn('Provider #'.$provider->id.': '.$exception->errorCode.'. Catalog preserved.');
                }
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
