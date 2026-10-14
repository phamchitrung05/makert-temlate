<?php

namespace Database\Factories;

use App\Enums\ResourceVersionTemplateProcessingStatus;
use App\Models\ResourceVersion;
use App\Models\ResourceVersionTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo fixture metadata xử lý Resource Template
 * =====================================================================
 *
 * Factory phục vụ TPL-01 model/relationship test và các task processing sau.
 * Nó tạo version template ở trạng thái pending; package/media thật chỉ được
 * gắn qua pipeline MediaAsset ở feature test tương ứng.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - definition(): dữ liệu metadata v1 bounded
 * - processing(): state đang processing
 * - ready(): state ready có manifest/entry tối thiểu
 * - failed(): state failed có lỗi public-safe
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : override attributes từ test
 * - OUTPUT: ResourceVersionTemplate chưa persist hoặc đã persist khi create()
 * - SIDE EFFECT: Factory ghi DB khi caller gọi create(); không mở transaction riêng
 * - EXCEPTION/TRANSACTION: FK/unique lỗi do caller tạo dữ liệu sai; factory không nuốt lỗi
 * =====================================================================
 */
class ResourceVersionTemplateFactory extends Factory
{
    /** @var class-string<ResourceVersionTemplate> */
    protected $model = ResourceVersionTemplate::class;

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo metadata template pending mặc định
     * =====================================================================
     * INPUT: Không có; Faker cung cấp checksum/token/path bounded.
     * OUTPUT: array attributes hợp lệ cho ResourceVersionTemplate.
     * SIDE EFFECT: Không ghi DB khi chưa gọi create().
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function definition(): array
    {
        return [
            'resource_version_id' => ResourceVersion::factory(),
            'package_sha256' => fake()->sha256(),
            'processing_status' => ResourceVersionTemplateProcessingStatus::Pending,
            'processing_token' => fake()->uuid(),
            'source_root' => 'template',
            'entry_screen_slug' => 'home',
            'manifest_json' => [
                'schema_version' => 1,
                'entry_screen' => 'home',
                'screens' => [[
                    'slug' => 'home',
                    'title' => 'Home',
                    'path' => 'index.html',
                ]],
            ],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo state đang processing
     * =====================================================================
     * INPUT: Override tùy chọn của caller.
     * OUTPUT: Factory state processing, xóa lỗi/processed_at cũ.
     * SIDE EFFECT: Không ghi DB trước create().
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function processing(): static
    {
        return $this->state(fn (): array => [
            'processing_status' => ResourceVersionTemplateProcessingStatus::Processing,
            'processing_error' => null,
            'processed_at' => null,
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo state ready tối thiểu cho ready gate test
     * =====================================================================
     * INPUT: Override tùy chọn của caller.
     * OUTPUT: Factory state ready có thời điểm processed.
     * SIDE EFFECT: Không ghi DB trước create().
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function ready(): static
    {
        return $this->state(fn (): array => [
            'processing_status' => ResourceVersionTemplateProcessingStatus::Ready,
            'processed_at' => now(),
            'processing_error' => null,
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo state failed với lỗi bounded
     * =====================================================================
     * INPUT: Override tùy chọn của caller.
     * OUTPUT: Factory state failed và lỗi public-safe.
     * SIDE EFFECT: Không ghi DB trước create().
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function failed(): static
    {
        return $this->state(fn (): array => [
            'processing_status' => ResourceVersionTemplateProcessingStatus::Failed,
            'processing_error' => 'Template processing failed.',
            'processed_at' => null,
        ]);
    }
}
