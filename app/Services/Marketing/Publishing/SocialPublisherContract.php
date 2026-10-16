<?php

namespace App\Services\Marketing\Publishing;

use App\Models\MediaAsset;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Hợp đồng publisher cho mọi nền tảng Social Post.
 * =====================================================================
 * Adapter nhận canonical caption và một image asset; không biết review UI hay
 * cách xếp queue. Facebook là adapter đầu tiên, platform khác mở rộng sau.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - platform(): trả platform key.
 * - publish(): đăng caption + ảnh và trả external reference bounded.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : SocialPost canonical, target/account và MediaAsset image.
 * - OUTPUT: external ID/URL/provider metadata; exception khi publish lỗi.
 * - SIDE EFFECT: gọi API nền tảng bên ngoài; không ghi DB.
 * =====================================================================
 */
interface SocialPublisherContract
{
    /**
     * Trả platform adapter key.
     *
     * Input: không có.
     * Output: facebook hoặc platform tương lai; không side effect.
     */
    public function platform(): string;

    /**
     * Đăng canonical caption và ảnh lên target.
     *
     * Input: SocialPost/target/image đã xác thực.
     * Output: external_id/url/request_id/metadata bounded.
     * Side effect: gọi API platform; không ghi database.
     *
     * @return array<string, mixed>
     */
    public function publish(SocialPost $post, SocialPostTarget $target, MediaAsset $image): array;
}
