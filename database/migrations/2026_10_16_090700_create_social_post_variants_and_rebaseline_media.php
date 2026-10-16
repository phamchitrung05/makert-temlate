<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo platform variant và chuyển gallery Social Post MVP.
 * =====================================================================
 *
 * Facebook v1 lưu content có cấu trúc ở variant và ảnh qua media usage
 * polymorphic. Migration này giữ caption/ảnh cũ để rollback và backfill dữ
 * liệu đã tồn tại mà không bịa thêm ảnh.
 */
return new class extends Migration
{
    /**
     * Tạo variant Facebook, gắn target và chuyển image usage legacy.
     *
     * Input: social_posts/social_post_targets/media_asset_usages hiện có.
     * Output: mỗi post có variant Facebook; target và ảnh trỏ đúng variant.
     * Side effect: thêm schema và cập nhật usage trong các batch nhỏ.
     */
    public function up(): void
    {
        Schema::create('social_post_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->string('platform', 32);
            $table->string('format', 48)->default('feed');
            $table->json('content_json')->nullable();
            $table->string('content_status', 24)->default('pending');
            $table->string('generation_status', 24)->default('pending');
            $table->string('review_status', 24)->default('draft');
            $table->json('prompt_snapshot_json')->nullable();
            $table->json('writing_profile_snapshot_json')->nullable();
            $table->json('provider_snapshot_json')->nullable();
            $table->unsignedInteger('current_version_no')->default(0);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();

            $table->unique(['social_post_id', 'platform'], 'social_post_variants_post_platform_unique');
            $table->index(['platform', 'review_status', 'content_status'], 'social_post_variants_platform_status_index');
        });

        Schema::table('social_post_targets', function (Blueprint $table): void {
            // Nullable during the migration so existing deployments can be
            // upgraded safely before all rows are backfilled.
            $table->foreignId('social_post_variant_id')
                ->nullable()
                ->after('social_post_id')
                ->constrained('social_post_variants')
                ->nullOnDelete();
            $table->index(
                ['social_post_variant_id', 'enabled', 'status'],
                'social_post_targets_variant_status_index',
            );
            $table->dropUnique('social_post_targets_unique');
            $table->unique(
                ['social_post_variant_id', 'social_account_id'],
                'social_post_targets_variant_account_unique',
            );
        });

        $this->backfillVariants();
        $this->backfillTargets();
        $this->moveLegacyImagesToVariants();
    }

    /**
     * Backfill một Facebook variant từ caption canonical hiện tại.
     *
     * Input: các row social_posts.
     * Output: social_post_variants với body giữ nguyên caption cũ.
     */
    private function backfillVariants(): void
    {
        DB::table('social_posts')->orderBy('id')->chunkById(100, function ($posts): void {
            foreach ($posts as $post) {
                $caption = trim((string) ($post->caption ?? ''));
                $content = [
                    'title' => null,
                    'body' => $caption !== '' ? $caption : null,
                    'bullet_points' => [],
                    'cta' => null,
                    'contact' => ['address' => null, 'phone' => null, 'website' => null],
                    'hashtags' => [],
                    'link_url' => null,
                    'used_product_fact_ids' => [],
                ];

                DB::table('social_post_variants')->insert([
                    'social_post_id' => $post->id,
                    'platform' => 'facebook',
                    'format' => 'feed',
                    'content_json' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'content_status' => $caption !== '' ? 'ready' : 'pending',
                    'generation_status' => (string) ($post->generation_status ?: 'pending'),
                    'review_status' => (string) ($post->review_status ?: 'draft'),
                    'current_version_no' => 0,
                    'reviewed_by' => $post->reviewed_by,
                    'reviewed_at' => $post->reviewed_at,
                    'review_reason' => $post->review_reason,
                    'created_at' => $post->created_at ?? now(),
                    'updated_at' => $post->updated_at ?? now(),
                ]);
            }
        });
    }

    /**
     * Gắn target legacy vào variant Facebook tương ứng.
     *
     * Input: social_post_targets có social_post_id.
     * Output: social_post_variant_id được backfill; target không mất status.
     */
    private function backfillTargets(): void
    {
        $variantIds = DB::table('social_post_variants')->pluck('id', 'social_post_id');

        DB::table('social_post_targets')->whereNull('social_post_variant_id')->orderBy('id')->chunkById(100, function ($targets) use ($variantIds): void {
            foreach ($targets as $target) {
                $variantId = $variantIds->get($target->social_post_id);
                if ($variantId === null) {
                    continue;
                }

                DB::table('social_post_targets')->where('id', $target->id)->update([
                    'social_post_variant_id' => $variantId,
                ]);
            }
        });
    }

    /**
     * Chuyển usage social_post.image sang gallery ordered của variant.
     *
     * Input: usage legacy single image.
     * Output: usage owner social_post_variant, field Facebook images, order >= 0.
     */
    private function moveLegacyImagesToVariants(): void
    {
        $variantIds = DB::table('social_post_variants')->pluck('id', 'social_post_id');

        DB::table('media_asset_usages')
            ->where('linkable_type', 'social_post')
            ->where('field', 'social_post.image')
            ->orderBy('id')
            ->chunkById(100, function ($usages) use ($variantIds): void {
                foreach ($usages as $usage) {
                    $variantId = $variantIds->get($usage->linkable_id);
                    if ($variantId === null) {
                        continue;
                    }

                    DB::table('media_asset_usages')->where('id', $usage->id)->update([
                        'linkable_type' => 'social_post_variant',
                        'linkable_id' => $variantId,
                        'field' => 'social_post_variant.facebook.images',
                        'sort_order' => $usage->sort_order ?? 0,
                    ]);
                }
            });
    }

    /**
     * Rollback variant schema and restore the first image to legacy ownership.
     *
     * Input: variant targets/usages created by up().
     * Output: target variant link removed; first gallery image restored to post.
     * Limitation: extra gallery images are deleted because legacy field was single.
     */
    public function down(): void
    {
        $this->restoreLegacyImages();

        DB::table('social_post_targets')->update(['social_post_variant_id' => null]);

        Schema::table('social_post_targets', function (Blueprint $table): void {
            $table->dropIndex('social_post_targets_variant_status_index');
            $table->dropUnique('social_post_targets_variant_account_unique');
            $table->unique(
                ['social_post_id', 'social_account_id'],
                'social_post_targets_unique',
            );
            $table->dropForeign(['social_post_variant_id']);
            $table->dropColumn('social_post_variant_id');
        });

        Schema::dropIfExists('social_post_variants');
    }

    /**
     * Restore one gallery image per post for an honest legacy rollback.
     */
    private function restoreLegacyImages(): void
    {
        $variants = DB::table('social_post_variants')->pluck('social_post_id', 'id');
        $usages = DB::table('media_asset_usages')
            ->where('linkable_type', 'social_post_variant')
            ->where('field', 'social_post_variant.facebook.images')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('linkable_id');

        foreach ($usages as $variantId => $variantUsages) {
            $postId = $variants->get($variantId);
            if ($postId === null) {
                continue;
            }

            $first = $variantUsages->first();
            DB::table('media_asset_usages')->where('id', $first->id)->update([
                'linkable_type' => 'social_post',
                'linkable_id' => $postId,
                'field' => 'social_post.image',
                'sort_order' => 0,
            ]);

            $extraIds = $variantUsages->slice(1)->pluck('id')->all();
            if ($extraIds !== []) {
                DB::table('media_asset_usages')->whereIn('id', $extraIds)->delete();
            }
        }
    }
};
