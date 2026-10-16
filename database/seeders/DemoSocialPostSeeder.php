<?php

namespace Database\Seeders;

use App\Enums\MediaAssetField;
use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostGenerationStatus;
use App\Enums\SocialPostPublicationStatus;
use App\Enums\SocialPostReviewStatus;
use App\Enums\SocialPublicationStatus;
use App\Models\Campaign;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialPostVariant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Dữ liệu mẫu Facebook Social Post để xem local UI.
 *
 * Seeder không được gọi trong DatabaseSeeder; chỉ chạy thủ công ở môi trường
 * development. Chỉ dùng ảnh có sẵn trong public/build, không gọi provider thật.
 */
final class DemoSocialPostSeeder extends Seeder
{
    public function run(): void
    {
        $actor = User::query()->firstOrFail();
        $account = SocialAccount::query()->updateOrCreate(
            ['external_id' => 'demo-facebook-page-giai-phap-in'],
            [
                'provider' => SocialPlatform::Facebook,
                'account_type' => 'page',
                'display_name' => 'Giải Pháp In Ấn (Demo)',
                'access_token' => 'demo-access-token',
                'status' => 'active',
                'scopes_json' => ['pages_show_list', 'pages_manage_posts'],
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ],
        );

        $product = Product::query()->firstOrCreate(
            ['name' => 'Bộ tag bánh kem Bonny Bakery'],
            [
                'short_description' => 'Tag và mác bánh kem thiết kế riêng cho tiệm bánh.',
                'default_cta' => 'Inbox để nhận mẫu thiết kế.',
                'status' => 'active',
                'created_by' => $actor->getKey(),
            ],
        );
        $product->facts()->firstOrCreate(
            ['fact_key' => 'material'],
            [
                'fact_value' => 'Giấy mỹ thuật in màu, cắt theo hình dạng yêu cầu.',
                'source' => 'demo-seeder',
                'verified' => true,
                'is_allowed_for_ai' => true,
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
            ],
        );

        $campaign = Campaign::query()->firstOrCreate(
            ['name' => 'Demo Facebook - Tag bánh kem'],
            [
                'objective' => 'Giới thiệu bộ tag/mác/tem nhãn cho tiệm bánh.',
                'message' => 'Tăng nhận diện thương hiệu trên từng hộp bánh.',
                'status' => 'active',
                'created_by' => $actor->getKey(),
            ],
        );
        $campaign->products()->syncWithoutDetaching([$product->getKey()]);

        $captions = [
            '[DEMO] ✨ ĐIỂM NHẤN HOÀN HẢO CHO MỌI CHIẾC BÁNH KEM ✨\n\nTag bánh kem giúp chiếc bánh thêm bắt mắt, chuyên nghiệp và giúp khách hàng lưu lại thông tin để đặt bánh lần sau.\n\n✅ Cắt bế thành phẩm chỉn chu\n✅ In nét căng, màu tươi chuẩn thiết kế\n✅ Gia công viền trực tiếp tại xưởng\n\nInbox để nhận mẫu tag riêng cho tiệm của bạn!\n\n📍 Giải Pháp In Ấn Kon Tum\n☎ Hotline: 098 958 07 03\n#GiaiPhapInAn #InTagCaoLai #MacBanhKem',
            '[DEMO] Bộ tag nhỏ, nhận diện thương hiệu lớn.\n\nMỗi chiếc bánh trao đi đều có thể trở thành một lần khách hàng nhớ đến thương hiệu của bạn. Chọn mẫu tag riêng, in đúng màu và giao đúng hẹn.\n\nInbox để xem bộ mẫu dành cho tiệm bánh.',
            '[DEMO] Một chiếc bánh đẹp cần một chiếc tag thật chỉn chu.\n\nTừ tên tiệm, số điện thoại đến thông điệp cảm ơn, mọi chi tiết đều được dàn trang rõ ràng và in sắc nét trên chất liệu phù hợp.\n\n📞 098 958 07 03\n#TagBanhKem #InAnDakHa',
        ];
        $imagePaths = [
            base_path('public/build/assets/5-CFPERer_.jpg'),
            base_path('public/build/assets/3d-safe-box-with-golden-dollar-coins-1l8l0kXJ.png'),
            base_path('public/build/assets/3d-space-rocket-with-smoke-DR35GWyq.png'),
        ];

        foreach ($captions as $caption) {
            $post = SocialPost::query()->firstOrCreate(
                ['caption' => $caption],
                [
                    'campaign_id' => $campaign->getKey(),
                    'product_id' => $product->getKey(),
                    'generation_status' => SocialPostGenerationStatus::Ready,
                    'review_status' => SocialPostReviewStatus::Draft,
                    'publication_status' => SocialPostPublicationStatus::Pending,
                    'generation_no' => 1,
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ],
            );
            $post->forceFill([
                'campaign_id' => $campaign->getKey(),
                'product_id' => $product->getKey(),
                'generation_status' => SocialPostGenerationStatus::Ready,
                'review_status' => SocialPostReviewStatus::Draft,
                'publication_status' => SocialPostPublicationStatus::Pending,
                'updated_by' => $actor->getKey(),
            ])->save();

            $variant = SocialPostVariant::query()->firstOrCreate(
                ['social_post_id' => $post->getKey(), 'platform' => SocialPlatform::Facebook],
                ['format' => 'feed', 'review_status' => SocialPostReviewStatus::Draft],
            );
            $variant->forceFill([
                'content_json' => [
                    'title' => null,
                    'body' => $caption,
                    'bullet_points' => [],
                    'cta' => null,
                    'contact' => ['address' => null, 'phone' => null, 'website' => null],
                    'hashtags' => [],
                    'link_url' => null,
                    'used_product_fact_ids' => [],
                ],
                'content_status' => 'ready',
                'generation_status' => 'ready',
                'review_status' => SocialPostReviewStatus::Draft,
            ])->save();

            $target = SocialPostTarget::query()->updateOrCreate(
                ['social_post_id' => $post->getKey(), 'social_account_id' => $account->getKey()],
                [
                    'social_post_variant_id' => $variant->getKey(),
                    'platform' => SocialPlatform::Facebook,
                    'enabled' => true,
                    'status' => SocialPublicationStatus::Pending,
                ],
            );

            foreach ($imagePaths as $slot => $imagePath) {
                $this->attachImage($variant, $actor, $imagePath, $slot + 1);
            }
            $this->command?->line(sprintf('Social Post demo #%d ready (target #%d).', $post->getKey(), $target->getKey()));
        }
    }

    private function attachImage(SocialPostVariant $variant, User $actor, string $path, int $number): void
    {
        if (! File::exists($path)) {
            return;
        }

        $title = 'Demo Facebook Social Post image '.$number;
        $asset = MediaAsset::query()->firstOrCreate(
            ['title' => $title, 'created_by' => $actor->getKey()],
            ['kind' => MediaAssetKind::Image, 'visibility' => MediaAssetVisibility::Public],
        );
        if (! $asset->getFirstMedia('library')) {
            $asset->addMedia($path)->preservingOriginal()->toMediaCollection('library');
        }

        MediaAssetUsage::query()->updateOrCreate(
            [
                'media_asset_id' => $asset->getKey(),
                'linkable_type' => 'social_post_variant',
                'linkable_id' => $variant->getKey(),
                'field' => MediaAssetField::SocialPostFacebookImages,
            ],
            ['sort_order' => $number - 1],
        );
    }
}
