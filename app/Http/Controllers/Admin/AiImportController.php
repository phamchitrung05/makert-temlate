<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiImportRequest;
use App\Http\Responses\BaseResponse;
use App\Jobs\ProcessAiImportJob;
use App\Models\AiImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: HTTP API tạo và đọc trạng thái AI import bài viết.
 * =====================================================================
 * Controller tạo AiImport, chạy job và trả draft theo envelope API chuẩn.
 * CÁC HÀM/METHOD TRONG FILE:
 * - store(): validate URL và khởi chạy pipeline.
 * - show(): trả trạng thái/kết quả của import thuộc user hiện tại.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : request admin chứa URL hoặc UUID import.
 * - OUTPUT: JSON status/draft hoặc lỗi validation.
 * =====================================================================
 */
class AiImportController extends Controller
{
    /** Input: payload URL đã validate. Output: JSON job và draft/error. */
    public function store(AiImportRequest $request): JsonResponse
    {
        if (! config('ai-import.enabled', true)) {
            return BaseResponse::error('AI import đang tắt.', 503);
        }
        $import = AiImport::query()->create(['id' => (string) Str::uuid(), 'created_by' => $request->user()->getKey(), 'source_url' => $request->string('url'), 'status' => 'processing', 'provider' => config('ai-import.provider')]);
        try {
            // dispatchSync keeps local development usable with no queue worker;
            // production can switch this to dispatch() and poll the same endpoint.
            ProcessAiImportJob::dispatchSync($import->id);
            $import->refresh();
            $result = $import->result_json ?? [];
            $import->update(['status' => 'completed', 'result_json' => $result, 'provider' => $result['provider'], 'prompt_version' => $result['prompt_version'], 'expires_at' => now()->addDay()]);

            return BaseResponse::success(['job_id' => $import->id, 'status' => 'completed', ...$result], 'Đã tạo bản nháp từ URL.');
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'error_message' => Str::limit($e->getMessage(), 500)]);

            return BaseResponse::error($e->getMessage(), 422);
        }
    }

    /** Input: UUID import và admin hiện tại. Output: JSON trạng thái/payload. */
    public function show(Request $request, AiImport $aiImport): JsonResponse
    {
        abort_unless($aiImport->created_by === $request->user()->getKey(), 404);

        return BaseResponse::success(['job_id' => $aiImport->id, 'status' => $aiImport->status, 'error' => $aiImport->error_message, ...($aiImport->result_json ?? [])]);
    }
}
