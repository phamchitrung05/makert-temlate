<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Data\AiTaskRequest;
use App\Services\Ai\Data\AiTaskResponse;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Hợp đồng chung cho mọi nhà cung cấp AI.
 * =====================================================================
 *
 * Provider adapter chỉ chịu trách nhiệm giao tiếp với model và trả dữ liệu
 * đã chuẩn hóa; nó không được tự ghi Post, Resource hay MediaAsset.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - configured(), execute(), generate(), providerName(), modelName().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : cấu hình provider và nội dung nguồn đã sanitize.
 * - OUTPUT: canonical AI fields cùng metadata provider/model.
 * - SIDE EFFECT: provider cụ thể có thể gọi HTTP bên ngoài; không ghi domain DB.
 * =====================================================================
 */
interface AiProviderContract
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra provider đã đủ cấu hình.
     * =====================================================================
     * INPUT: không có.
     * OUTPUT: provider đã đủ cấu hình.
     * SIDE EFFECT: đọc config.
     * EXCEPTION/TRANSACTION: không có; không mở transaction.
     * =====================================================================
     */
    public function configured(): bool;

    /**
     * =====================================================================
     * Input: request bất biến theo nhiệm vụ và JSON Schema.
     * Output: response đã kiểm envelope/schema; có thể gọi HTTP, không ghi Post.
     * =====================================================================
     */
    public function execute(AiTaskRequest $request): AiTaskResponse;

    /**
     * =====================================================================
     * CHỨC NĂNG: Sinh output canonical từ nội dung nguồn.
     * =====================================================================
     * INPUT: title, content, language, style, prompt và instruction.
     * OUTPUT: mảng canonical fields.
     * SIDE EFFECT: có thể gọi API bên ngoài.
     * EXCEPTION/TRANSACTION: provider exception; không mở transaction.
     * =====================================================================
     *
     * @return array<string, mixed>
     */
    public function generate(
        string $title,
        string $content,
        string $language = 'vi',
        string $rewriteStyle = 'informative',
        string $promptKey = 'post.create.from_url',
        string $instructions = '',
    ): array;

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả provider key cho provenance.
     * =====================================================================
     * INPUT: không có.
     * OUTPUT: provider key cho provenance.
     * SIDE EFFECT: đọc config.
     * EXCEPTION/TRANSACTION: không có; không mở transaction.
     * =====================================================================
     */
    public function providerName(): string;

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả model key cho audit.
     * =====================================================================
     * INPUT: không có.
     * OUTPUT: model key cho audit.
     * SIDE EFFECT: đọc config.
     * EXCEPTION/TRANSACTION: không có; không mở transaction.
     * =====================================================================
     */
    public function modelName(): string;
}
