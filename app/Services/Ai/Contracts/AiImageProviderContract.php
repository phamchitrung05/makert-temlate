<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Providers\Transport\AiConnection;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Boundary riêng cho capability tạo ảnh, không dùng text contract.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: generate().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * INPUT: connection snapshot và prompt của một image run.
 * OUTPUT: bytes ảnh; service Media Library chịu trách nhiệm validate và upload.
 * SIDE EFFECT: có thể gọi provider; không ghi Post, MediaAsset hoặc database.
 * EXCEPTION/TRANSACTION: AiImportException an toàn khi provider lỗi; không transaction.
 * =====================================================================
 */
interface AiImageProviderContract
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo bytes ảnh theo giao thức của connection
     * =====================================================================
     * INPUT: AiConnection đã kiểm tra capability và prompt người dùng.
     * OUTPUT: chuỗi binary; không tin MIME/filename do provider khai báo.
     * SIDE EFFECT: một POST tạo ảnh, có thể GET URL ảnh public đã kiểm tra SSRF.
     * EXCEPTION/TRANSACTION: AiImportException; không ghi database hoặc mở transaction.
     * =====================================================================
     */
    public function generate(AiConnection $connection, string $prompt): string;
}
