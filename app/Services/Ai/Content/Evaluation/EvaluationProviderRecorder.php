<?php

namespace App\Services\Ai\Content\Evaluation;

use App\Exceptions\AiImportException;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Contracts\AiResponseMetadataProvider;
use App\Services\Ai\Data\AiTaskRequest;
use App\Services\Ai\Data\AiTaskResponse;
use App\Services\Ai\Providers\Diagnostics\AiResponseDiagnostics;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Ghi các lượt model phục vụ thử nghiệm chất lượng có giới hạn.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: __construct(), configured(), providerName(), modelName(),
 * withOutputFields(), execute(), generate(), responseMetadata(), record().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): provider đã resolve -> artifacts canonical/usage/latency.
 * SIDE EFFECT: delegate HTTP khi caller chạy đánh giá; không ghi DB/Post/Settings.
 * Không lưu raw response, header, endpoint hoặc API key; không retry riêng.
 * =====================================================================
 */
final class EvaluationProviderRecorder implements AiProviderContract, AiResponseMetadataProvider
{
    public array $calls = [];

    /**
     * =====================================================================
     * Input: adapter được phép và giới hạn call.
     * Output: recorder độc lập cho một nhánh.
     * =====================================================================
     */
    public function __construct(private AiProviderContract $inner, private readonly int $maxCalls) {}

    /**
     * =====================================================================
     * Input: không có.
     * Output: cấu hình adapter hợp lệ, không gọi HTTP.
     * =====================================================================
     */
    public function configured(): bool
    {
        return $this->inner->configured();
    }

    /**
     * =====================================================================
     * Input: không có.
     * Output: tên provider public.
     * =====================================================================
     */
    public function providerName(): string
    {
        return $this->inner->providerName();
    }

    /**
     * =====================================================================
     * Input: không có.
     * Output: tên model public.
     * =====================================================================
     */
    public function modelName(): string
    {
        return $this->inner->modelName();
    }

    /**
     * =====================================================================
     * Input: fields đã chọn.
     * Output: recorder dùng adapter có cùng lựa chọn, không call.
     * =====================================================================
     */
    public function withOutputFields(array $fields): static
    {
        if (method_exists($this->inner, 'withOutputFields')) {
            $this->inner = $this->inner->withOutputFields($fields);
        }

        return $this;
    }

    /**
     * =====================================================================
     * Input: task/schema/context.
     * Output: DTO gốc, ghi canonical output để chấm riêng.
     * =====================================================================
     */
    public function execute(AiTaskRequest $request): AiTaskResponse
    {
        return $this->record($request->task, fn () => $this->inner->execute($request));
    }

    /**
     * =====================================================================
     * Input: source/brief của một lượt.
     * Output: fields gốc, không tự sửa bài hoặc fallback.
     * =====================================================================
     */
    public function generate(string $title, string $content, string $language = 'vi', string $rewriteStyle = 'informative', string $promptKey = 'post.create.from_url', string $instructions = ''): array
    {
        return $this->record('single_step', fn () => $this->inner->generate($title, $content, $language, $rewriteStyle, $promptKey, $instructions));
    }

    /**
     * =====================================================================
     * Input: không có.
     * Output: diagnostics allowlist gần nhất hoặc rỗng, không số giả.
     * =====================================================================
     */
    public function responseMetadata(): array
    {
        return $this->inner instanceof AiResponseMetadataProvider ? $this->inner->responseMetadata() : [];
    }

    /**
     * =====================================================================
     * Input: tên task và callback model. Output: response hoặc lỗi giữ nguyên.
     * Budget kiểm trước call, kể cả call lỗi được ghi và tính vào ngân sách.
     * =====================================================================
     */
    private function record(string $task, callable $call): mixed
    {
        if (count($this->calls) >= $this->maxCalls) {
            throw new AiImportException('Đánh giá đã dùng hết ngân sách call.', 'EVALUATION_CALL_BUDGET');
        }
        $index = count($this->calls);
        $started = microtime(true);
        $this->calls[] = ['task' => $task, 'output' => null, 'diagnostics' => [], 'latency_ms' => null];
        try {
            $result = $call();
            $this->calls[$index]['output'] = $result instanceof AiTaskResponse ? $result->output : $result;
            $this->calls[$index]['diagnostics'] = AiResponseDiagnostics::sanitize($result instanceof AiTaskResponse ? $result->diagnostics : $this->responseMetadata());

            return $result;
        } catch (AiImportException $exception) {
            $this->calls[$index]['diagnostics'] = AiResponseDiagnostics::sanitize(array_replace($this->responseMetadata(), $exception->diagnostics, ['error_code' => $exception->errorCode]));
            throw $exception;
        } finally {
            $this->calls[$index]['latency_ms'] = (int) round((microtime(true) - $started) * 1000);
        }
    }
}
