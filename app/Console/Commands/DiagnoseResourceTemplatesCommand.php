<?php

namespace App\Console\Commands;

use App\Enums\ResourceVersionTemplateProcessingStatus;
use App\Models\ResourceVersionTemplate;
use App\Services\Resources\TemplateVersionProcessingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chẩn đoán template processing stuck/missing artifact/orphan
 * =====================================================================
 *
 * Command chỉ đọc metadata bounded và có thể dọn directory orphan dưới
 * template-previews theo token UUID; không nhận path tùy ý, không log token.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(): quét template rows, in bảng/JSON và optional cleanup.
 * - inspect(): phân loại một template thành diagnostic reason.
 * - cleanupOrphans(): xóa artifact cũ không còn được row tham chiếu.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể): trạng thái DB/private disk -> report vận hành.
 * =====================================================================
 */
final class DiagnoseResourceTemplatesCommand extends Command
{
    protected $signature = 'resource-templates:diagnose
        {--stale-hours=2 : Số giờ pending/processing được coi là stuck}
        {--cleanup : Dọn artifact orphan quá retention}
        {--json : In JSON bounded cho monitoring}';

    protected $description = 'Chẩn đoán template processing stuck, artifact thiếu và orphan';

    /** Input: CLI options. Output: SUCCESS khi scan hoàn tất, FAILURE nếu có bất thường. */
    public function handle(TemplateVersionProcessingService $processing): int
    {
        $staleAt = now()->subHours(max(1, (int) $this->option('stale-hours')));
        $rows = [];
        ResourceVersionTemplate::query()->with('resourceVersion')->orderBy('id')->chunkById(200, function ($templates) use (&$rows, $staleAt, $processing): void {
            foreach ($templates as $template) {
                $reason = $this->inspect($template, $staleAt, $processing);
                if ($reason !== null) {
                    $rows[] = [
                        'template_id' => (int) $template->id,
                        'version_id' => (int) $template->resource_version_id,
                        'status' => $template->processing_status->value,
                        'reason' => $reason,
                        'updated_at' => $template->updated_at?->toIso8601String(),
                    ];
                }
            }
        });
        $orphanCount = $this->option('cleanup') ? $this->cleanupOrphans() : 0;
        $report = ['issues' => $rows, 'issue_count' => count($rows), 'orphan_removed' => $orphanCount];
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['template_id', 'version_id', 'status', 'reason', 'updated_at'], $rows);
            $this->info('Template diagnostics: '.count($rows).' issue(s); orphan removed: '.$orphanCount.'.');
        }

        return $rows === [] ? self::SUCCESS : self::FAILURE;
    }

    /** Input: template row/stale boundary/service. Output: bounded reason or null. */
    private function inspect(ResourceVersionTemplate $template, \DateTimeInterface $staleAt, TemplateVersionProcessingService $processing): ?string
    {
        if (in_array($template->processing_status, [ResourceVersionTemplateProcessingStatus::Pending, ResourceVersionTemplateProcessingStatus::Processing], true)
            && $template->updated_at?->lte($staleAt)) {
            return 'stale_processing';
        }
        if ($template->processing_status === ResourceVersionTemplateProcessingStatus::Ready
            && ! $processing->hasPublishedArtifact($template)) {
            return 'missing_artifact';
        }

        return null;
    }

    /** Input: media_private template artifact tree. Output: count removed orphan directories. */
    private function cleanupOrphans(): int
    {
        $disk = Storage::disk((string) config('resource-templates.artifact_disk', 'media_private'));
        $base = trim((string) config('media-assets.template_artifact_directory', 'template-previews'), '/');
        $cutoff = now()->subHours((int) config('resource-templates.orphan_hours', 24));
        $removed = 0;
        foreach ($disk->directories($base) as $versionDirectory) {
            $versionId = basename(str_replace('\\', '/', $versionDirectory));
            if (! ctype_digit($versionId)) {
                continue;
            }
            $active = ResourceVersionTemplate::query()->where('resource_version_id', (int) $versionId)->value('processing_token');
            foreach ($disk->directories($versionDirectory) as $artifactDirectory) {
                $token = basename(str_replace('\\', '/', $artifactDirectory));
                if (! Str::isUuid($token) || (string) $active === $token) {
                    continue;
                }
                $absolute = method_exists($disk, 'path') ? $disk->path($artifactDirectory) : null;
                if ($absolute && is_dir($absolute) && File::lastModified($absolute) < $cutoff->getTimestamp()) {
                    File::deleteDirectory($absolute);
                    $removed++;
                }
            }
        }

        return $removed;
    }
}
