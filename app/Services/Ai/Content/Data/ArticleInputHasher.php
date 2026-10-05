<?php

namespace App\Services\Ai\Content\Data;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Hash canonical cho snapshot/checkpoint độc lập thứ tự key JSON.
 * =====================================================================
 * MySQL JSON có thể sắp xếp key; cùng dữ liệu không được gọi AI lại vì key order.
 * CÁC HÀM/METHOD TRONG FILE: hash(), normalize().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : DTO/array/scalar chứa input pipeline.
 * - OUTPUT: SHA-256 canonical; không lưu nội dung hoặc có side effect.
 * =====================================================================
 */
final class ArticleInputHasher
{
    /**
     * =====================================================================
     * Input: DTO/array/scalar của request snapshot.
     * Output: hash 64 ký tự; giữ thứ tự list và sort key object đệ quy.
     * =====================================================================
     */
    public static function hash(mixed $input): string
    {
        return hash('sha256', json_encode(self::normalize($input), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * =====================================================================
     * Input: dữ liệu chưa canonical.
     * Output: mảng object đã sort key, list giữ nguyên; không mutate input.
     * =====================================================================
     */
    private static function normalize(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::normalize(...), $value);
    }
}
