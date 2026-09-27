<?php

namespace Database\Seeders;

use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Seed metadata MediaAsset mẫu cho môi trường local
 * =====================================================================
 *
 * Seeder chỉ tạo metadata mẫu để kiểm tra list/filter trong admin. Không giả
 * lập file vật lý trong bảng `media`; file thật phải đi qua upload pipeline.
 * Seeder được gọi có điều kiện bằng MEDIA_LIBRARY_SEED=true.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - run(): tạo ba asset metadata mẫu idempotent
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : admin local đầu tiên và config MEDIA_LIBRARY_SEED
 * - OUTPUT: image, document và archive MediaAsset mẫu
 * =====================================================================
 */
class MediaAssetSeeder extends Seeder
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo asset metadata mẫu khi được bật bằng environment
     * =====================================================================
     *
     * SIDE EFFECT:
     * - INSERT MediaAsset metadata; không tạo file trong storage
     */
    public function run(): void
    {
        if (! (bool) env('MEDIA_LIBRARY_SEED', false)) {
            return;
        }

        $admin = User::query()->where('email', 'admin@example.com')->first();

        MediaAsset::factory()->image()->create([
            'title' => 'Sample cover image',
            'created_by' => $admin?->id,
        ]);
        MediaAsset::factory()->document()->create([
            'title' => 'Sample documentation',
            'created_by' => $admin?->id,
        ]);
        MediaAsset::factory()->archive()->create([
            'title' => 'Sample resource package',
            'created_by' => $admin?->id,
        ]);
    }
}
