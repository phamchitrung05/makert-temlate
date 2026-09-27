<?php

namespace App\Services\Media;

use App\Enums\MediaAssetField;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Resolve model nghiệp vụ từ morph alias của Media API
 * =====================================================================
 *
 * Resolver chỉ cho phép alias đã được khai báo trong MediaAssetField và morph
 * map của ứng dụng. Nhờ vậy client không thể truyền FQCN tùy ý hoặc attach
 * usage vào một model không hỗ trợ MediaAsset.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - resolve(): nạp model linkable từ alias và id
 * - allowedAliases(): trả alias hợp lệ từ field contract
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : morph alias và primary key từ request attach/reorder
 * - OUTPUT: Eloquent Model hỗ trợ mediaAssetUsages hoặc ValidationException
 * =====================================================================
 */
class MediaAssetLinkableResolver
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve model nghiệp vụ an toàn từ morph alias và id
     * =====================================================================
     *
     * INPUT:
     * - $alias: alias trong morph map, ví dụ resource/resource_version
     * - $id: primary key model nghiệp vụ
     * OUTPUT: Model đã tồn tại và có relation mediaAssetUsages()
     * EXCEPTION/TRANSACTION: ValidationException nếu alias/model không hợp lệ
     * =====================================================================
     */
    public function resolve(string $alias, int $id): Model
    {
        if (! in_array($alias, $this->allowedAliases(), true)) {
            throw ValidationException::withMessages([
                'linkable_type' => 'Model linkable không được phép.',
            ]);
        }

        $class = Relation::getMorphedModel($alias);
        if ($class === null || ! class_exists($class)) {
            throw ValidationException::withMessages([
                'linkable_type' => "Model linkable {$alias} chưa được triển khai.",
            ]);
        }

        $model = $class::query()->find($id);
        if (! $model instanceof Model) {
            throw ValidationException::withMessages([
                'linkable_id' => 'Không tìm thấy model linkable.',
            ]);
        }

        if (! method_exists($model, 'mediaAssetUsages')) {
            throw ValidationException::withMessages([
                'linkable_type' => 'Model linkable chưa hỗ trợ MediaAsset usage.',
            ]);
        }

        return $model;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy alias được phép từ field contract
     * =====================================================================
     *
     * OUTPUT:
     * - list<string>: alias duy nhất được dùng ở API attach/reorder
     */
    private function allowedAliases(): array
    {
        return collect(MediaAssetField::cases())
            ->map(fn (MediaAssetField $field): string => $field->linkableMorphAlias())
            ->unique()
            ->values()
            ->all();
    }
}
