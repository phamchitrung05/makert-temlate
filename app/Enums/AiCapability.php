<?php

namespace App\Enums;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Capability chuẩn dùng chung cho catalog, resolver và transport.
 * =====================================================================
 * INPUT: capability key.
 * OUTPUT: enum capability; không truy vấn DB hoặc gọi provider.
 * SIDE EFFECT: Không ghi database hoặc gọi network.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dữ liệu theo contract và method chính của file.
 * - OUTPUT: kết quả public của file; side effect/exception theo implementation.
 * CÁC HÀM/METHOD TRONG FILE:
 * - Không có method nghiệp vụ; enum chỉ khai báo capability cases.
 */
enum AiCapability: string
{
    case Text = 'text_generation';
    case Structured = 'structured_output';
    case Image = 'image_generation';
    case Vision = 'vision';
    case Embedding = 'embedding';
}
