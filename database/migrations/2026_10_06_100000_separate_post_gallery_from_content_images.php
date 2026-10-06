<?php

use App\Models\MediaAsset;
use App\Services\Media\ContentMediaReferenceService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Tách usage cũ: ảnh xuất hiện trong HTML bỏ quan hệ; ảnh chọn riêng chuyển sang Gallery, giữ file. */
return new class extends Migration
{
    /** Input: usages legacy. Output: Gallery độc lập; không sửa nội dung Post hoặc xóa MediaAsset/file. */
    public function up(): void
    {
        DB::table('posts')->whereExists(function ($query): void {
            $query->selectRaw('1')->from('media_asset_usages')
                ->whereColumn('linkable_id', 'posts.id')
                ->where('linkable_type', 'post')->where('field', 'post.content_images');
        })->orderBy('id')->chunkById(100, function ($posts): void {
            foreach ($posts as $post) {
                DB::transaction(function () use ($post): void {
                    $current = DB::table('posts')->where('id', $post->id)->lockForUpdate()->first();
                    if (! $current) {
                        return;
                    }
                    $references = app(ContentMediaReferenceService::class);
                    $html = (string) $current->content;
                    $inlineIds = $references->referencedIds($html);
                    $usages = DB::table('media_asset_usages')->where('linkable_type', 'post')
                        ->where('linkable_id', $post->id)->where('field', 'post.content_images')
                        ->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get();
                    $galleryIds = DB::table('media_asset_usages')->where('linkable_type', 'post')
                        ->where('linkable_id', $post->id)->where('field', 'post.gallery')
                        ->pluck('media_asset_id')->map(fn ($id): int => (int) $id)->all();
                    $nextOrder = (int) (DB::table('media_asset_usages')->where('linkable_type', 'post')
                        ->where('linkable_id', $post->id)->where('field', 'post.gallery')->max('sort_order') ?? -1) + 1;
                    foreach ($usages as $usage) {
                        $asset = MediaAsset::withTrashed()->with('media')->find($usage->media_asset_id);
                        $inline = in_array((int) $usage->media_asset_id, $inlineIds, true)
                            || ($asset && $references->isLinkedInHtml($html, $asset));
                        if ($inline || in_array((int) $usage->media_asset_id, $galleryIds, true)) {
                            DB::table('media_asset_usages')->where('id', $usage->id)->delete();
                        } else {
                            DB::table('media_asset_usages')->where('id', $usage->id)->update([
                                'field' => 'post.gallery', 'sort_order' => $nextOrder++,
                            ]);
                            $galleryIds[] = (int) $usage->media_asset_id;
                        }
                    }
                });
            }
        });
    }

    /** Input: Gallery hiện có. Output: tên field legacy; không tái tạo quan hệ ảnh content đã bỏ. */
    public function down(): void
    {
        DB::table('media_asset_usages')->where('linkable_type', 'post')->where('field', 'post.gallery')
            ->update(['field' => 'post.content_images']);
    }
};
