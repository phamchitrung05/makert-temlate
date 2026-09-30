<?php

namespace App\Services\Ai\Contracts;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Hợp đồng chung cho mọi nhà cung cấp AI.
 * =====================================================================
 *
 * Provider adapter chỉ chịu trách nhiệm giao tiếp với model và trả dữ liệu
 * đã chuẩn hóa; nó không được tự ghi Post, Resource hay MediaAsset.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - configured(), generate(), providerName(), modelName().
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
     * INPUT: không có. OUTPUT: provider đã đủ cấu hình.
     * SIDE EFFECT: đọc config. EXCEPTION/TRANSACTION: không có.
     */
    public function configured(): bool;

    /**
     * INPUT: title, content, language, style, prompt và instruction.
     * OUTPUT: mảng canonical fields. SIDE EFFECT: có thể gọi API bên ngoài.
     * EXCEPTION/TRANSACTION: provider exception; không mở transaction.
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
     * INPUT: không có. OUTPUT: provider key cho provenance.
     * SIDE EFFECT: đọc config. EXCEPTION/TRANSACTION: không có.
     */
    public function providerName(): string;

    /**
     * INPUT: không có. OUTPUT: model key cho audit.
     * SIDE EFFECT: đọc config. EXCEPTION/TRANSACTION: không có.
     */
    public function modelName(): string;
}
