<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo bảng SEO dùng chung cho nhiều model nội dung.
 * =====================================================================
 *
 * Bảng dùng quan hệ polymorphic để Post, Resource và các model nội dung sau
 * này có thể dùng chung metadata SEO. Migration cũng backfill các cột SEO
 * legacy hiện có mà không xóa cột cũ trong giai đoạn tương thích.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): tạo bảng và chuyển dữ liệu SEO hiện có sang metadata polymorphic
 * - down(): xóa bảng SEO dùng chung
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : schema posts/resources và các cột SEO legacy.
 * - OUTPUT: bảng seo_metadata có một bản ghi cho mỗi model SEO.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * Tạo bảng SEO và backfill metadata từ Post/Resource hiện tại.
     *
     * Input: schema đã có bảng posts/resources và cột SEO legacy.
     * Output: bảng seo_metadata, dữ liệu cũ được giữ nguyên trong bản ghi mới.
     */
    public function up(): void
    {
        Schema::create('seo_metadata', function (Blueprint $table): void {
            $table->id();
            $table->string('seoable_type');
            $table->unsignedBigInteger('seoable_id');
            $table->string('focus_keyword')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->boolean('robots_index')->default(true);
            $table->boolean('robots_follow')->default(true);
            $table->string('og_title')->nullable();
            $table->text('og_description')->nullable();
            $table->foreignId('og_image_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->timestamps();

            $table->unique(['seoable_type', 'seoable_id']);
            $table->index(['seoable_type', 'seoable_id']);
        });

        $this->backfillPosts();
        $this->backfillResources();
    }

    /**
     * Backfill metadata SEO từ các cột legacy của Post.
     *
     * Input: posts có các cột SEO cũ.
     * Output: bản ghi seo_metadata với morph alias `post`.
     */
    private function backfillPosts(): void
    {
        if (! Schema::hasTable('posts')) {
            return;
        }

        DB::table('posts')->orderBy('id')->chunkById(500, function ($posts): void {
            $rows = $posts->map(function ($post): ?array {
                $hasMetadata = collect([
                    $post->focus_keyword,
                    $post->seo_title,
                    $post->seo_description,
                    $post->canonical_url,
                    $post->robots_index === 0 || $post->robots_index === false ? false : null,
                    $post->robots_follow === 0 || $post->robots_follow === false ? false : null,
                    $post->og_title,
                    $post->og_description,
                    $post->og_image_id ?? null,
                ])->contains(fn ($value): bool => $value !== null && $value !== '');

                if (! $hasMetadata) {
                    return null;
                }

                return [
                    'seoable_type' => 'post',
                    'seoable_id' => $post->id,
                    'focus_keyword' => $post->focus_keyword,
                    'seo_title' => $post->seo_title,
                    'seo_description' => $post->seo_description,
                    'canonical_url' => $post->canonical_url,
                    'robots_index' => (bool) $post->robots_index,
                    'robots_follow' => (bool) $post->robots_follow,
                    'og_title' => $post->og_title,
                    'og_description' => $post->og_description,
                    'og_image_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })->filter()->values()->all();

            if ($rows !== []) {
                DB::table('seo_metadata')->insertOrIgnore($rows);
            }
        });
    }

    /**
     * Backfill metadata SEO từ các cột legacy của Resource.
     *
     * Input: resources có seo_title/seo_description/canonical_url cũ.
     * Output: bản ghi seo_metadata với morph alias `resource`.
     */
    private function backfillResources(): void
    {
        if (! Schema::hasTable('resources')) {
            return;
        }

        DB::table('resources')->orderBy('id')->chunkById(500, function ($resources): void {
            $rows = $resources->map(function ($resource): ?array {
                $hasMetadata = collect([
                    $resource->seo_title,
                    $resource->seo_description,
                    $resource->canonical_url,
                ])->contains(fn ($value): bool => $value !== null && $value !== '');

                if (! $hasMetadata) {
                    return null;
                }

                return [
                    'seoable_type' => 'resource',
                    'seoable_id' => $resource->id,
                    'focus_keyword' => null,
                    'seo_title' => $resource->seo_title,
                    'seo_description' => $resource->seo_description,
                    'canonical_url' => $resource->canonical_url,
                    'robots_index' => true,
                    'robots_follow' => true,
                    'og_title' => null,
                    'og_description' => null,
                    'og_image_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })->filter()->values()->all();

            if ($rows !== []) {
                DB::table('seo_metadata')->insertOrIgnore($rows);
            }
        });
    }

    /**
     * Xóa bảng metadata SEO khi rollback migration.
     *
     * Input: bảng seo_metadata.
     * Output: bảng seo_metadata bị xóa; cột legacy không bị tác động.
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_metadata');
    }
};
