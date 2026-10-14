<?php

namespace Database\Factories;

use App\Models\ResourceVersionTemplate;
use App\Models\ResourceVersionTemplateScreen;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo fixture screen của Resource Template
 * =====================================================================
 *
 * Factory tạo screen path/slug bounded theo manifest v1 để kiểm relation,
 * unique constraint, order và metadata. Screenshot không được tạo tự động;
 * attachment phải đi qua MediaAssetUsageService ở task sau.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - definition(): dữ liệu screen mặc định
 * - secondScreen(): state screen thứ hai để kiểm order/unique
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : override attributes từ test
 * - OUTPUT: ResourceVersionTemplateScreen chưa persist hoặc đã persist
 * - SIDE EFFECT: Factory ghi DB khi caller gọi create(); không mở transaction riêng
 * - EXCEPTION/TRANSACTION: FK/unique lỗi do caller tạo dữ liệu sai; factory không nuốt lỗi
 * =====================================================================
 */
class ResourceVersionTemplateScreenFactory extends Factory
{
    /** @var class-string<ResourceVersionTemplateScreen> */
    protected $model = ResourceVersionTemplateScreen::class;

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo screen home mặc định
     * =====================================================================
     * INPUT: Không có; dùng template factory và path bounded.
     * OUTPUT: array attributes hợp lệ theo screen contract.
     * SIDE EFFECT: Không ghi DB khi chưa gọi create().
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function definition(): array
    {
        return [
            'resource_version_template_id' => ResourceVersionTemplate::factory(),
            'slug' => 'home',
            'title' => 'Home',
            'source_path' => 'index.html',
            'sort_order' => 0,
            'metadata' => [
                'screenshot' => 'screenshots/home.png',
                'warnings' => [],
            ],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo screen thứ hai để kiểm thứ tự và unique
     * =====================================================================
     * INPUT: Override tùy chọn của caller.
     * OUTPUT: State screen settings với slug/settings và sort_order=1.
     * SIDE EFFECT: Không ghi DB trước create().
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function secondScreen(): static
    {
        return $this->state(fn (): array => [
            'slug' => 'settings',
            'title' => 'Settings',
            'source_path' => 'settings.html',
            'sort_order' => 1,
            'metadata' => ['warnings' => []],
        ]);
    }
}
