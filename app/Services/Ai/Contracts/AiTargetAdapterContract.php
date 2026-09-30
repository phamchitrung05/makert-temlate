<?php

namespace App\Services\Ai\Contracts;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Hợp đồng adapter chuyển response AI sang domain model.
 * =====================================================================
 *
 * Adapter được đăng ký theo target (post, resource, sound...). Provider
 * không biết schema database; adapter là ranh giới duy nhất của domain.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - key(), toPreview(), toApplyPayload().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : canonical outputs và danh sách field người dùng chọn.
 * - OUTPUT: preview hoặc payload dành cho domain Action.
 * - SIDE EFFECT: không ghi database; transaction thuộc caller Apply.
 * =====================================================================
 */
interface AiTargetAdapterContract
{
    /**
     * INPUT: không có. OUTPUT: target key ổn định.
     * SIDE EFFECT: không có. EXCEPTION/TRANSACTION: không có.
     */
    public function key(): string;

    /**
     * INPUT: canonical outputs. OUTPUT: preview domain.
     * SIDE EFFECT: không ghi database. EXCEPTION/TRANSACTION: validation exception; không mở transaction.
     *
     * @param  array<string, mixed>  $outputs
     * @return array<string, mixed>
     */
    public function toPreview(array $outputs): array;

    /**
     * INPUT: outputs và fields chọn. OUTPUT: payload cho domain Action.
     * SIDE EFFECT: không ghi database. EXCEPTION/TRANSACTION: validation exception; không mở transaction.
     *
     * @param  array<string, mixed>  $outputs
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function toApplyPayload(array $outputs, array $fields): array;
}
