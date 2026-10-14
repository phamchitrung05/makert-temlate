<?php

namespace App\Services\Resources;

use App\Enums\ResourceType;
use App\Enums\ResourceVersionTemplateProcessingStatus;
use App\Exceptions\MediaSecurityException;
use App\Exceptions\TemplatePackageInputException;
use App\Jobs\Resources\ProcessTemplateVersionJob;
use App\Models\AiTaskRun;
use App\Models\MediaAsset;
use App\Models\ResourceVersion;
use App\Models\ResourceVersionTemplate;
use App\Services\Ai\Runs\AiTaskRunService;
use App\Services\ResourceVersionPackageGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối queue và artifact lifecycle của template version
 * =====================================================================
 *
 * Service tách phần transaction ngắn khỏi file processing dài: enqueue khóa
 * version và tạo token, job recheck package/scan/checksum, extractor ghi staging
 * private, rồi service publish artifact và ghi manifest/screens trong transaction
 * cuối. Token/version guard khiến worker cũ không thể ghi đè lượt mới.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - enqueue(): tạo processing token, tracker và dispatch job after commit
 * - process(): chạy một lượt extract/publish với retryable boundary
 * - cleanupArtifacts(): xóa đúng staging/artifact theo version/token
 * - markFailed(): ghi lỗi public-safe và kết thúc tracker
 * - markProcessing(): lock/recheck input trước khi bắt đầu file work
 * - persistSuccess(): ghi manifest/screens/pointer sau khi artifact ready
 * - publishArtifact(): chuyển site root từ staging sang private artifact
 * - ensureTemplateVersion(): chặn resource type ngoài template
 * - packageInput(): lấy archive media và checksum hiện tại
 * - tracker(): đăng ký/đọc tracker projection cho template
 * - hasPublishedArtifact(): kiểm artifact private còn tồn tại cho ready gate
 * - artifactExists(): kiểm artifact private đã publish
 * - processingRoot(): dựng staging directory theo token
 * - artifactRelativePath(): dựng pointer tương đối không lộ absolute path
 * - diskPath(): đổi private disk path thành absolute path local
 * - manifestSnapshot(): dựng snapshot bounded cho database
 * - screenRows(): đổi parser screens thành rows metadata bounded
 * - publicFailureMessage(): chuẩn hóa lỗi không lộ stack/secret
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ResourceVersion, package checksum/media/token và TemplatePackageExtractor result.
 * - OUTPUT: AiTaskRun lifecycle, ResourceVersionTemplate ready/failed và private artifact pointer.
 * - SIDE EFFECT: queue dispatch, file move/delete trên media_private, DB transaction ngắn.
 * - EXCEPTION/TRANSACTION: lỗi input được đánh failed không retry; lỗi vận hành được ném lại để queue retry.
 * =====================================================================
 */
final class TemplateVersionProcessingService
{
    public function __construct(
        private readonly ResourceVersionPackageGuard $packageGuard,
        private readonly TemplatePackageExtractor $extractor,
        private readonly AiTaskRunService $taskRuns,
    ) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Đưa một ResourceVersion template vào queue xử lý
     * =====================================================================
     * INPUT: ResourceVersion draft có package archive private/clean.
     * OUTPUT: AiTaskRun queued/processing/ready hiện tại của token.
     * SIDE EFFECT: lock version, ghi template metadata và dispatch after commit.
     * EXCEPTION/TRANSACTION: ValidationException nếu package/type chưa đạt gate.
     * =====================================================================
     */
    public function enqueue(ResourceVersion $version): AiTaskRun
    {
        return DB::transaction(function () use ($version): AiTaskRun {
            $version = ResourceVersion::query()
                ->with('resource')
                ->whereKey($version->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureTemplateVersion($version);

            [$package, $media, $checksum] = $this->packageInput($version);
            $template = ResourceVersionTemplate::query()
                ->where('resource_version_id', $version->getKey())
                ->lockForUpdate()
                ->first();
            if (! $template) {
                $template = ResourceVersionTemplate::query()->create([
                    'resource_version_id' => $version->getKey(),
                ]);
                $template = ResourceVersionTemplate::query()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();
            }

            if ($this->canReuseCurrentRun($template, $checksum)) {
                $task = $this->tracker($template);
                if (in_array($task->status, AiTaskRunService::ACTIVE_STATUSES, true)
                    || ($task->status === 'ready' && $this->artifactExists($template))) {
                    return $task;
                }
            }

            if ($template->processing_token !== null) {
                $this->cleanupArtifacts((int) $version->getKey(), (string) $template->processing_token);
            }

            $token = (string) Str::uuid();
            $template->forceFill([
                'source_media_id' => $package->getKey(),
                'package_sha256' => $checksum,
                'processing_status' => ResourceVersionTemplateProcessingStatus::Pending,
                'processing_token' => $token,
                'source_root' => null,
                'entry_screen_slug' => null,
                'manifest_json' => null,
                'preview_path' => null,
                'processing_error' => null,
                'processed_at' => null,
            ])->save();

            $task = $this->tracker($template->fresh(['resourceVersion.resource']));
            ProcessTemplateVersionJob::dispatch(
                (int) $version->getKey(),
                (int) $media->getKey(),
                $checksum,
                $token,
            )->afterCommit();

            return $task;
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chạy một lượt template processing trong queue
     * =====================================================================
     * INPUT: version/media/checksum/token đã snapshot lúc enqueue và job id tùy chọn.
     * OUTPUT: void; template ready hoặc tracker chờ retry/failed.
     * SIDE EFFECT: đọc ZIP, ghi private staging/artifact và database transaction.
     * EXCEPTION/TRANSACTION: lỗi input được consume; lỗi vận hành được ném lại cho queue retry.
     * =====================================================================
     */
    public function process(
        int $versionId,
        int $sourceMediaId,
        string $checksum,
        string $processingToken,
        ?string $jobId = null,
    ): void {
        $template = $this->markProcessing($versionId, $sourceMediaId, $checksum, $processingToken);
        if (! $template) {
            return;
        }

        $task = $this->tracker($template, $jobId);
        $this->taskRuns->start($task, 5, 'processing');

        $processingRoot = $this->processingRoot($versionId, $processingToken);
        $artifactPath = null;

        try {
            $version = ResourceVersion::query()->with('resource')->findOrFail($versionId);
            $this->ensureTemplateVersion($version);
            [$package, $media, $currentChecksum] = $this->packageInput($version);
            if ((int) $package->getKey() !== $sourceMediaId || ! hash_equals($checksum, $currentChecksum)) {
                throw new TemplatePackageInputException(
                    'TEMPLATE_STALE_INPUT',
                    'Package đã thay đổi trong khi template đang được xử lý.',
                );
            }

            $deadlineAt = microtime(true) + (int) config('media-assets.template_process_deadline', 120);
            $result = $this->extractor->extract($media->getPath(), $processingRoot, $deadlineAt);
            $this->taskRuns->sync($task, 'processing', 65);
            if (! $result['valid']) {
                throw new TemplatePackageInputException(
                    'TEMPLATE_PACKAGE_INVALID',
                    $this->publicFailureMessage($result['errors']),
                );
            }

            $artifactPath = $this->publishArtifact($result, $versionId, $processingToken);
            $this->persistSuccess($versionId, $sourceMediaId, $checksum, $processingToken, $result);
            $freshTemplate = ResourceVersionTemplate::query()
                ->with('resourceVersion.resource')
                ->where('resource_version_id', $versionId)
                ->firstOrFail();
            $this->taskRuns->syncFrom($freshTemplate, $jobId);
        } catch (TemplatePackageInputException|MediaSecurityException|ValidationException $exception) {
            $this->cleanupArtifacts($versionId, $processingToken);
            Log::warning('Resource template processing rejected input.', [
                'version_id' => $versionId,
                'source_media_id' => $sourceMediaId,
                'processing_token' => $processingToken,
                'error' => $exception->getMessage(),
            ]);
            $this->markFailed($versionId, $sourceMediaId, $checksum, $processingToken, $exception);

            return;
        } catch (Throwable $exception) {
            $this->cleanupArtifacts($versionId, $processingToken);
            Log::error('Resource template processing worker failed.', [
                'version_id' => $versionId,
                'source_media_id' => $sourceMediaId,
                'processing_token' => $processingToken,
                'exception' => $exception,
            ]);

            throw $exception;
        } finally {
            if ($artifactPath === null) {
                $this->cleanupArtifacts($versionId, $processingToken);
            }
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn staging và artifact của đúng processing token
     * =====================================================================
     * INPUT: ResourceVersion id và token do service tạo.
     * OUTPUT: Không trả giá trị.
     * SIDE EFFECT: Xóa private template-processing/template-previews subtree.
     * EXCEPTION/TRANSACTION: best-effort cleanup, không ném lỗi ra queue.
     * =====================================================================
     */
    public function cleanupArtifacts(int $versionId, string $processingToken): void
    {
        try {
            File::deleteDirectory($this->processingRoot($versionId, $processingToken));
            File::deleteDirectory($this->diskPath($this->artifactRelativePath($versionId, $processingToken)));
        } catch (Throwable) {
            // Cleanup không được che lỗi lifecycle chính; TPL-12 sẽ bổ sung orphan sweep.
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi failed public-safe khi input blocking hoặc queue hết retry
     * =====================================================================
     * INPUT: identity processing và Throwable terminal.
     * OUTPUT: template failed và tracker terminal failed nếu token còn hiện hành.
     * SIDE EFFECT: ghi processing_error bounded.
     * EXCEPTION/TRANSACTION: không ném lỗi gốc ra response.
     * =====================================================================
     */
    public function markFailed(
        int $versionId,
        int $sourceMediaId,
        string $checksum,
        string $processingToken,
        ?Throwable $exception = null,
    ): void {
        $template = DB::transaction(function () use ($versionId, $sourceMediaId, $checksum, $processingToken, $exception): ?ResourceVersionTemplate {
            $template = ResourceVersionTemplate::query()
                ->where('resource_version_id', $versionId)
                ->lockForUpdate()
                ->first();
            if (! $template
                || (string) $template->processing_token !== $processingToken
                || (int) $template->source_media_id !== $sourceMediaId
                || (string) $template->package_sha256 !== $checksum
                || $template->processing_status === ResourceVersionTemplateProcessingStatus::Failed
                || $template->processing_status === ResourceVersionTemplateProcessingStatus::Ready) {
                return null;
            }

            $failureMessage = match (true) {
                $exception instanceof TemplatePackageInputException || $exception instanceof MediaSecurityException => $this->publicFailureMessage([$exception->getMessage()]),
                $exception instanceof ValidationException => 'Package template không còn đạt điều kiện xử lý; hãy kiểm tra lại package và scan.',
                default => 'Template processing thất bại do lỗi vận hành. Hệ thống sẽ retry hoặc cần rebuild.',
            };
            $template->forceFill([
                'processing_status' => ResourceVersionTemplateProcessingStatus::Failed,
                'processing_error' => mb_substr($failureMessage, 0, 1000),
                'preview_path' => null,
                'processed_at' => null,
            ])->save();

            return $template;
        });

        if ($template) {
            $this->taskRuns->syncFrom($template->fresh(['resourceVersion.resource']));
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lock và chuyển template pending sang processing
     * =====================================================================
     * INPUT: processing identity từ job.
     * OUTPUT: template hiện hành hoặc null nếu worker stale/đã terminal.
     * SIDE EFFECT: cập nhật processing_status trong transaction ngắn.
     * EXCEPTION/TRANSACTION: package identity sai trả null, không ghi đè lượt mới.
     * =====================================================================
     */
    private function markProcessing(int $versionId, int $sourceMediaId, string $checksum, string $processingToken): ?ResourceVersionTemplate
    {
        return DB::transaction(function () use ($versionId, $sourceMediaId, $checksum, $processingToken): ?ResourceVersionTemplate {
            $template = ResourceVersionTemplate::query()
                ->where('resource_version_id', $versionId)
                ->lockForUpdate()
                ->first();
            if (! $template
                || (int) $template->source_media_id !== $sourceMediaId
                || (string) $template->package_sha256 !== $checksum
                || (string) $template->processing_token !== $processingToken
                || $template->processing_status === ResourceVersionTemplateProcessingStatus::Failed
                || $template->processing_status === ResourceVersionTemplateProcessingStatus::Ready) {
                return null;
            }

            $template->forceFill([
                'processing_status' => ResourceVersionTemplateProcessingStatus::Processing,
                'processing_error' => null,
                'processed_at' => null,
            ])->save();

            return $template->fresh(['resourceVersion.resource']);
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Publish screens/manifest sau khi artifact private hoàn tất
     * =====================================================================
     * INPUT: version identity, token và extractor result.
     * OUTPUT: void; template ready có screens/manifest bounded.
     * SIDE EFFECT: transaction xóa/tạo screen rows và cập nhật template snapshot.
     * EXCEPTION/TRANSACTION: stale token trả ValidationException để không overwrite run mới.
     * =====================================================================
     */
    private function persistSuccess(int $versionId, int $sourceMediaId, string $checksum, string $processingToken, array $result): void
    {
        if (! is_dir($this->diskPath($this->artifactRelativePath($versionId, $processingToken)))) {
            throw new RuntimeException('Template artifact chưa publish đầy đủ.');
        }

        DB::transaction(function () use ($versionId, $sourceMediaId, $checksum, $processingToken, $result): void {
            $template = ResourceVersionTemplate::query()
                ->where('resource_version_id', $versionId)
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $template->source_media_id !== $sourceMediaId
                || (string) $template->package_sha256 !== $checksum
                || (string) $template->processing_token !== $processingToken
                || $template->processing_status !== ResourceVersionTemplateProcessingStatus::Processing) {
                throw ValidationException::withMessages(['package' => 'Template processing token đã hết hiệu lực.']);
            }

            $oldScreenIds = $template->screens()->pluck('id');
            if ($oldScreenIds->isNotEmpty()) {
                DB::table('media_asset_usages')
                    ->where('linkable_type', 'template_screen')
                    ->whereIn('linkable_id', $oldScreenIds->all())
                    ->delete();
            }
            $template->screens()->delete();
            foreach ($this->screenRows($result) as $row) {
                $template->screens()->create($row);
            }
            $template->forceFill([
                'processing_status' => ResourceVersionTemplateProcessingStatus::Ready,
                'source_root' => $result['source_root'],
                'entry_screen_slug' => $result['entry_screen'],
                'manifest_json' => $this->manifestSnapshot($result),
                'preview_path' => $this->artifactRelativePath($versionId, $processingToken),
                'processing_error' => null,
                'processed_at' => now(),
            ])->save();

            activity('resources')
                ->performedOn($template)
                ->event('template.processed')
                ->withProperties([
                    'resource_version_id' => $versionId,
                    'source_media_id' => $sourceMediaId,
                    'screen_count' => count($result['screens']),
                ])
                ->log('Resource template processed.');
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Move site root từ staging sang private artifact directory
     * =====================================================================
     * INPUT: extractor result có staging_path/source_root và version/token.
     * OUTPUT: absolute artifact path.
     * SIDE EFFECT: move directory trên media_private; xóa wrapper staging.
     * EXCEPTION/TRANSACTION: RuntimeException nếu source/target không move được.
     * =====================================================================
     */
    private function publishArtifact(array $result, int $versionId, string $processingToken): string
    {
        $stagingRun = (string) $result['staging_path'];
        $sourceRoot = $result['source_root'];
        $siteRoot = $sourceRoot === null
            ? $stagingRun
            : $stagingRun.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $sourceRoot);
        $artifactPath = $this->diskPath($this->artifactRelativePath($versionId, $processingToken));

        if (! is_dir($siteRoot)) {
            throw new RuntimeException('Template staging site root không tồn tại.');
        }
        File::deleteDirectory($artifactPath);
        File::ensureDirectoryExists(dirname($artifactPath), 0700);
        if (! File::moveDirectory($siteRoot, $artifactPath)) {
            throw new RuntimeException('Không thể publish template artifact private.');
        }
        File::deleteDirectory(dirname($stagingRun));

        return $artifactPath;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm resource version thuộc loại template
     * =====================================================================
     * INPUT: ResourceVersion đã eager-load resource.
     * OUTPUT: Không trả giá trị khi type hợp lệ.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: ValidationException nếu type khác template.
     * =====================================================================
     */
    private function ensureTemplateVersion(ResourceVersion $version): void
    {
        if ($version->resource?->type !== ResourceType::Template) {
            throw ValidationException::withMessages(['resource_version' => 'Chỉ resource type template mới được xử lý package.']);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy package archive clean và checksum hiện tại
     * =====================================================================
     * INPUT: ResourceVersion.
     * OUTPUT: [MediaAsset, Media, sha256].
     * SIDE EFFECT: Đọc usage/media private; hash file nếu upload chưa có checksum.
     * EXCEPTION/TRANSACTION: ValidationException/MediaSecurityException nếu package invalid.
     * =====================================================================
     */
    private function packageInput(ResourceVersion $version): array
    {
        $package = $this->packageGuard->cleanPackageFor($version->fresh());
        $media = $package->getFirstMedia('library');
        if (! $media) {
            throw new MediaSecurityException('Package không có file vật lý để xử lý.');
        }

        $checksum = (string) $media->getCustomProperty('checksum_sha256');
        if ($checksum === '') {
            $path = $media->getPath();
            if (! is_file($path)) {
                throw new MediaSecurityException('Không đọc được package private.');
            }
            $checksum = (string) hash_file('sha256', $path);
        }

        return [$package, $media, $checksum];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đăng ký/đồng bộ tracker template
     * =====================================================================
     * INPUT: template source và queue job id tùy chọn.
     * OUTPUT: AiTaskRun projection.
     * SIDE EFFECT: create/update ai_task_runs.
     * EXCEPTION/TRANSACTION: RuntimeException nếu source không còn tồn tại.
     * =====================================================================
     */
    private function tracker(ResourceVersionTemplate $template, ?string $jobId = null): AiTaskRun
    {
        $task = $this->taskRuns->syncFrom($template->fresh(['resourceVersion.resource']), $jobId);

        return $task ?? throw new RuntimeException('Không tạo được tracker template processing.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm run hiện tại có thể tái sử dụng hay không
     * =====================================================================
     * INPUT: template hiện tại và package checksum mới.
     * OUTPUT: true nếu cùng checksum và status active/ready có artifact.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function canReuseCurrentRun(ResourceVersionTemplate $template, string $checksum): bool
    {
        if ((string) $template->package_sha256 !== $checksum || $template->processing_token === null) {
            return false;
        }

        return in_array($template->processing_status, [
            ResourceVersionTemplateProcessingStatus::Pending,
            ResourceVersionTemplateProcessingStatus::Processing,
        ], true) || ($template->processing_status === ResourceVersionTemplateProcessingStatus::Ready && $this->artifactExists($template));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm artifact pointer private đã tồn tại
     * =====================================================================
     * INPUT: template có version/token.
     * OUTPUT: bool directory artifact tồn tại.
     * SIDE EFFECT: Chỉ đọc private disk.
     * EXCEPTION/TRANSACTION: Disk path không hỗ trợ local sẽ trả false.
     * =====================================================================
     */
    private function artifactExists(ResourceVersionTemplate $template): bool
    {
        if ($template->processing_token === null) {
            return false;
        }

        return is_dir($this->diskPath($this->artifactRelativePath((int) $template->resource_version_id, (string) $template->processing_token)));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm artifact private đã publish cho ready/promote gate
     * =====================================================================
     * INPUT: ResourceVersionTemplate có processing token hiện hành.
     * OUTPUT: true khi directory artifact còn tồn tại trên media_private.
     * SIDE EFFECT: Chỉ đọc filesystem private.
     * EXCEPTION/TRANSACTION: Token/disk không hợp lệ trả false an toàn.
     * =====================================================================
     */
    public function hasPublishedArtifact(ResourceVersionTemplate $template): bool
    {
        try {
            return $this->artifactExists($template);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dựng staging root theo version/token
     * =====================================================================
     * INPUT: version id và processing token.
     * OUTPUT: absolute private path.
     * SIDE EFFECT: Không tạo directory; extractor tạo khi bắt đầu.
     * EXCEPTION/TRANSACTION: RuntimeException nếu disk không expose local path.
     * =====================================================================
     */
    private function processingRoot(int $versionId, string $processingToken): string
    {
        if (! Str::isUuid($processingToken)) {
            throw new RuntimeException('Processing token không hợp lệ.');
        }

        return $this->diskPath(config('media-assets.template_processing_directory').'/'.$versionId.'/'.$processingToken);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dựng pointer artifact tương đối cho database/preview
     * =====================================================================
     * INPUT: version id và token.
     * OUTPUT: relative private path; không chứa absolute storage root.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function artifactRelativePath(int $versionId, string $processingToken): string
    {
        if (! Str::isUuid($processingToken)) {
            throw new RuntimeException('Processing token không hợp lệ.');
        }

        return trim((string) config('media-assets.template_artifact_directory'), '/').'/'.$versionId.'/'.$processingToken;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve relative path trên media_private local disk
     * =====================================================================
     * INPUT: relative private disk path.
     * OUTPUT: absolute path.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: RuntimeException nếu disk không hỗ trợ path().
     * =====================================================================
     */
    private function diskPath(string $relativePath): string
    {
        $disk = Storage::disk((string) config('resource-templates.artifact_disk', 'media_private'));
        if (! method_exists($disk, 'path')) {
            throw new RuntimeException('Template processing cần media_private local disk.');
        }

        return $disk->path(ltrim($relativePath, '/'));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo manifest snapshot kèm diagnostics bounded
     * =====================================================================
     * INPUT: extractor result.
     * OUTPUT: array JSON-safe không chứa HTML/raw bytes.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function manifestSnapshot(array $result): array
    {
        $manifest = $result['manifest'] ?? [
            'schema_version' => 1,
            'entry_screen' => $result['entry_screen'],
            'screens' => array_map(fn (array $screen): array => array_filter([
                'slug' => $screen['slug'] ?? null,
                'title' => $screen['title'] ?? null,
                'path' => $screen['path'] ?? $screen['source_path'] ?? null,
                'screenshot' => $screen['screenshot'] ?? null,
            ], fn ($value): bool => $value !== null), $result['screens']),
        ];

        return [
            'manifest' => $manifest,
            'discovery' => ! $result['manifest_present'],
            'requires_entry_selection' => $result['requires_entry_selection'],
            'source_root' => $result['source_root'],
            'files' => $result['files'],
            'diagnostics' => $result['diagnostics'],
            'warnings' => $result['warnings'],
            'warnings_total' => $result['warnings_total'],
            'warnings_truncated' => $result['warnings_truncated'],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa screen DTO thành row database bounded
     * =====================================================================
     * INPUT: extractor result screens.
     * OUTPUT: list slug/title/source_path/sort_order/metadata.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function screenRows(array $result): array
    {
        return array_values(array_map(function (array $screen, int $index) use ($result): array {
            return [
                'slug' => $screen['slug'],
                'title' => $screen['title'],
                'source_path' => $screen['source_path'] ?? $screen['path'],
                'sort_order' => $index,
                'metadata' => array_filter([
                    'screenshot' => $screen['screenshot'] ?? null,
                    'discovered' => ! $result['manifest_present'],
                ], fn ($value): bool => $value !== null),
            ];
        }, $result['screens'], array_keys($result['screens'])));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giới hạn error diagnostics thành message public-safe
     * =====================================================================
     * INPUT: danh sách lỗi parser hoặc message exception.
     * OUTPUT: một message bounded, không stack/secret.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function publicFailureMessage(array $errors): string
    {
        $message = trim((string) ($errors[0] ?? 'Template package không hợp lệ.'));

        return mb_substr($message === '' ? 'Template package không hợp lệ.' : $message, 0, 1000);
    }
}
