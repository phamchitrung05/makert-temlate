<?php

namespace App\Services\Marketing\Publishing;

use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialPostVariant;
use App\Models\SocialPublication;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Hợp đồng publisher cho mọi nền tảng Social Post.
 * =====================================================================
 * Adapter nhận một variant và ordered image gallery; không biết review UI hay
 * cách xếp queue. Facebook là adapter đầu tiên, platform khác mở rộng sau.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - platform(): trả platform key.
 * - publish(): đăng caption + ảnh và trả external reference bounded.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : SocialPost canonical, target/account và MediaAsset image.
 * - OUTPUT: external ID/URL/provider metadata; exception khi publish lỗi.
 * - SIDE EFFECT: gọi API nền tảng bên ngoài và lưu journal của publication.
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
     * Đăng content variant và gallery ảnh lên target.
     *
     * Input: SocialPost/target/variant/gallery đã xác thực và publication đã claim.
     * Output: external_id/url/request_id/metadata bounded.
     * Side effect: gọi API platform và lưu checkpoint trước khi tạo bài bên ngoài.
     *
     * @return array<string, mixed>
     */
    public function publish(SocialPost $post, SocialPostTarget $target, ?SocialPostVariant $variant, array $images, SocialPublication $publication): array;
}
