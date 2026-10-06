<?php

namespace Tests\Feature;

use App\Models\AiImport;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Services\Ai\Runs\AiRunAssetCleaner;
use App\Services\Media\ContentMediaReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử refs ảnh, URL/quyền, usage Post và cleanup candidate.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp()/tearDown(): database và storage cô lập.
 * - token()/image()/html()/aiRun(): fixtures không upload thật hoặc gọi model.
 * - test_post_derives_ordered_distinct_image_usage_from_content(): suy thứ tự/dedup.
 * - test_content_update_detaches_removed_images_without_deleting_files(): gỡ usage khi bỏ ảnh.
 * - test_image_url_must_match_the_referenced_public_asset(): từ chối URL giả.
 * - test_image_requires_valid_existing_public_image_with_file(): kiểm asset còn dùng được.
 * - test_content_image_requires_media_attach_permission(): kiểm quyền attach.
 * - test_image_event_and_alternative_sources_cannot_bypass_verified_url(): chặn event/srcset/picture.
 * - test_only_completed_conversion_urls_are_accepted(): kiểm URL conversion đã tạo.
 * - test_cleanup_protects_inline_images_in_other_candidates_and_checkpoints(): giữ ảnh trong run khác.
 * - test_cleanup_reference_reader_is_conservative_and_deduplicates(): đọc ID dương, không cấp quyền.
 * - test_direct_delete_protects_retained_candidate_and_failed_run_snapshot(): chặn DELETE ảnh còn giữ.
 * - test_media_validation_locks_asset_rows_only_inside_a_transaction(): kiểm chính sách row lock.
 * - test_queued_child_parent_draft_snapshot_retains_images_after_parent_changes(): giữ baseline child.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT: HTTP payload/HTML/asset fixtures.
 * - OUTPUT: assertions về lỗi và dữ liệu; không gọi AI hoặc dùng GD.
 * =====================================================================
 */
class ContentMediaReferenceTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: schema SQLite/storage/quyền cô lập.
     * =====================================================================
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('media_public');
        config()->set('media-library.asset_disks.public', 'media_public');
    }

    /**
     * =====================================================================
     * INPUT: Test đã chạy.
     * OUTPUT: dọn database riêng.
     * =====================================================================
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * =====================================================================
     * INPUT: Quyền actor.
     * OUTPUT: token admin đã xác thực.
     * =====================================================================
     */
    private function token(array $permissions = ['posts.manage', 'media.attach']): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo($permissions);
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('inline-media-test', ['admin'])->plainTextToken;
    }

    /**
     * =====================================================================
     * INPUT: Attributes tùy chọn.
     * OUTPUT: public image với metadata Spatie, không chạy conversion GD.
     * =====================================================================
     */
    private function image(array $attributes = []): MediaAsset
    {
        $asset = MediaAsset::factory()->image()->create($attributes);
        $asset->media()->create([
            'collection_name' => 'library', 'name' => 'Inline image', 'file_name' => 'inline.png',
            'disk' => 'media_public', 'conversions_disk' => 'media_public', 'mime_type' => 'image/png',
            'size' => 100, 'manipulations' => [], 'custom_properties' => [],
            'generated_conversions' => [], 'responsive_images' => [], 'order_column' => 1,
        ]);

        return $asset->fresh('media');
    }

    /**
     * =====================================================================
     * INPUT: Asset và URL tùy chọn.
     * OUTPUT: HTML ảnh canonical có ID/alt.
     * =====================================================================
     */
    private function html(MediaAsset $asset, ?string $url = null): string
    {
        return '<img data-media-asset-id="'.$asset->id.'" src="'.e($url ?? $asset->getFirstMedia('library')->getUrl()).'" alt="Ảnh minh họa">';
    }

    /**
     * =====================================================================
     * INPUT: JSON/status tùy chọn.
     * OUTPUT: AiImport fixture còn retention.
     * =====================================================================
     */
    private function aiRun(array $attributes = []): AiImport
    {
        return AiImport::query()->create(array_replace([
            'created_by' => User::factory()->create()->id, 'source_url' => 'https://example.com/source',
            'source_hash' => hash('sha256', uniqid()), 'status' => 'ready', 'expires_at' => now()->addDay(),
        ], $attributes));
    }

    /**
     * =====================================================================
     * INPUT: Hai link ảnh, một link lặp ở nhiều vị trí.
     * OUTPUT: HTML giữ nguyên; ảnh content không có usage hoặc tự thêm vào gallery.
     * =====================================================================
     */
    public function test_post_keeps_repeated_image_links_without_media_relationships(): void
    {
        $token = $this->token();
        $first = $this->image();
        $second = $this->image();
        $html = '<p>Nội dung</p>'.$this->html($second).$this->html($first).$this->html($second);
        $created = $this->withToken($token)->postJson('/api/admin/posts', [
            'title' => 'Bài có ảnh', 'content' => $html,
        ])->assertCreated()->assertJsonPath('data.content', $html)
            ->assertJsonPath('data.media.gallery_images', [])
            ->assertJsonMissingPath('data.media.content_images');

        $this->assertDatabaseCount('media_asset_usages', 0);
        $this->withToken($token)->getJson('/api/admin/posts/'.$created->json('data.id'))
            ->assertOk()->assertJsonPath('data.content', $html);
    }

    /**
     * =====================================================================
     * INPUT: Xóa ảnh inline và cập nhật title partial.
     * OUTPUT: thumbnail giữ nguyên, không có quan hệ ảnh inline hoặc xóa file.
     * =====================================================================
     */
    public function test_content_update_detaches_removed_images_without_deleting_files(): void
    {
        $token = $this->token();
        $image = $this->image();
        $created = $this->withToken($token)->postJson('/api/admin/posts', [
            'title' => 'Bài có ảnh', 'content' => $this->html($image),
            'media' => ['thumbnail_id' => $image->id],
        ])->assertCreated();
        $id = $created->json('data.id');
        $this->withToken($token)->putJson('/api/admin/posts/'.$id, ['title' => 'Tiêu đề sửa'])
            ->assertOk()->assertJsonPath('data.content', $this->html($image))
            ->assertJsonPath('data.media.gallery_images', []);
        $this->withToken($token)->putJson('/api/admin/posts/'.$id, ['content' => '<p>Đã xóa ảnh</p>'])
            ->assertOk()->assertJsonPath('data.media.gallery_images', [])
            ->assertJsonPath('data.media.thumbnail.id', $image->id);
        $this->assertNotNull(MediaAsset::find($image->id));
    }

    /**
     * =====================================================================
     * INPUT: Asset đúng nhưng URL khác/blob/data.
     * OUTPUT: validator candidate AI từ chối ref sai, không ghi Post hoặc usage.
     * =====================================================================
     */
    public function test_image_url_must_match_the_referenced_public_asset(): void
    {
        $image = $this->image();
        foreach (['https://example.com/forged.png', 'blob:temporary', 'data:image/png;base64,AAAA'] as $url) {
            $this->assertInvalidAiReference($this->html($image, $url));
        }
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('media_asset_usages', 0);
    }

    /**
     * =====================================================================
     * INPUT: ID thiếu/sai hoặc asset thiếu file/private/bị xóa.
     * OUTPUT: 422 rõ field content.
     * =====================================================================
     */
    public function test_image_requires_valid_existing_public_image_with_file(): void
    {
        $image = $this->image();
        $private = $this->image(['visibility' => 'private']);
        $empty = MediaAsset::factory()->image()->create();
        $deleted = $this->image();
        $deleted->delete();
        foreach ([
            '<img src="https://example.com/a.png">',
            '<img data-media-asset-id="-1" src="https://example.com/a.png">',
            $this->html($private), $this->html($deleted),
            '<img data-media-asset-id="'.$empty->id.'" src="https://example.com/a.png">',
            '<img data-media-asset-id="999999" src="'.e($image->getFirstMedia('library')->getUrl()).'">',
        ] as $html) {
            $this->assertInvalidAiReference($html);
        }
    }

    /**
     * =====================================================================
     * INPUT: Actor thiếu quyền attach.
     * OUTPUT: 403 dù ID/URL ảnh đều đúng.
     * =====================================================================
     */
    public function test_ai_candidate_image_reference_requires_media_attach_permission(): void
    {
        $image = $this->image();
        $actor = User::factory()->create();
        $actor->givePermissionTo('posts.manage');
        $this->flushPermissionCache();
        $this->expectException(AuthorizationException::class);
        app(ContentMediaReferenceService::class)->validate($this->html($image), $actor);
    }

    /**
     * =====================================================================
     * INPUT: img event/srcset hoặc picture/source thay URL dù img.src hợp lệ.
     * OUTPUT: 422 trước khi lưu; HTML định dạng hợp lệ khác không bị sửa.
     * =====================================================================
     */
    public function test_image_event_and_alternative_sources_cannot_bypass_verified_url(): void
    {
        $token = $this->token();
        $image = $this->image();
        $verified = $this->html($image);
        foreach ([
            str_replace('<img ', '<img onerror="alert(1)" ', $verified),
            str_replace('<img ', '<img onload="alert(1)" ', $verified),
            str_replace('<img ', '<img srcset="https://example.com/forged.png 2x" ', $verified),
            '<picture><source srcset="https://example.com/forged.png">'.$verified.'</picture>',
            '<source srcset="https://example.com/forged.png">'.$verified,
        ] as $html) {
            $this->withToken($token)->postJson('/api/admin/posts', ['title' => 'Nguồn ảnh giả', 'content' => $html])
                ->assertUnprocessable()->assertJsonValidationErrors('content');
        }
        $this->assertDatabaseCount('posts', 0);
        $formatted = '<p style="text-align:center"><strong>Nội dung</strong></p>'.$verified;
        $this->withToken($token)->postJson('/api/admin/posts', ['title' => 'Giữ định dạng', 'content' => $formatted])
            ->assertCreated()->assertJsonPath('data.content', $formatted);
    }

    /**
     * =====================================================================
     * INPUT: Conversion URL chưa/sau generated.
     * OUTPUT: chỉ nhận conversion thực sự đã sẵn sàng.
     * =====================================================================
     */
    public function test_only_completed_conversion_urls_are_accepted(): void
    {
        $image = $this->image();
        $media = $image->getFirstMedia('library');
        $html = $this->html($image, $media->getUrl('web'));
        $this->assertInvalidAiReference($html);
        $media->update(['generated_conversions' => ['web' => true]]);
        $actor = User::factory()->create();
        $actor->givePermissionTo('media.attach');
        $this->flushPermissionCache();
        $this->assertSame([$image->id], app(ContentMediaReferenceService::class)->validate($html, $actor));
    }

    /** Input: ref candidate sai. Output: lỗi content từ validator AI; không gọi Post API hoặc tạo usage. */
    private function assertInvalidAiReference(string $html): void
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo('media.attach');
        $this->flushPermissionCache();
        try {
            app(ContentMediaReferenceService::class)->validate($html, $actor);
            $this->fail('Candidate AI phải từ chối ảnh có ref sai.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('content', $exception->errors());
        }
    }

    /** Input: ảnh AI được chèn bằng link trong Post. Output: cleanup giữ file dù không có media usage. */
    public function test_cleanup_keeps_an_image_linked_from_post_without_creating_a_relationship(): void
    {
        $image = $this->image();
        $post = Post::factory()->create(['content' => '<img src="'.e($image->getFirstMediaUrl('library')).'" alt="Ảnh link">']);
        $expired = $this->aiRun(['expires_at' => now()->subDay(), 'result_json' => ['draft' => ['thumbnail' => ['media_asset_id' => $image->id]]]]);

        app(AiRunAssetCleaner::class)->cleanup($expired);
        $this->assertNotNull(MediaAsset::find($image->id));
        $this->assertDatabaseCount('media_asset_usages', 0);
        $post->update(['content' => '<p>Đã bỏ link ảnh</p>']);
        app(AiRunAssetCleaner::class)->cleanup($expired);
        $this->assertNull(MediaAsset::find($image->id));
    }

    /**
     * =====================================================================
     * INPUT: Thumbnail tạm xuất hiện inline trong candidate khác.
     * OUTPUT: cleaner giữ file.
     * =====================================================================
     */
    public function test_cleanup_protects_inline_images_in_other_candidates_and_checkpoints(): void
    {
        $image = $this->image();
        $expired = $this->aiRun(['expires_at' => now()->subDay(), 'result_json' => ['draft' => ['thumbnail' => ['media_asset_id' => $image->id]]]]);
        $other = $this->aiRun(['result_json' => ['draft' => ['content_html' => $this->html($image)]]]);
        app(AiRunAssetCleaner::class)->cleanup($expired);
        $this->assertNotNull(MediaAsset::find($image->id));
        $other->update(['status' => 'writing', 'result_json' => [], 'source_meta_json' => ['source_snapshot' => ['html' => $this->html($image)]]]);
        app(AiRunAssetCleaner::class)->cleanup($expired);
        $this->assertNotNull(MediaAsset::find($image->id));
        $other->delete();
        app(AiRunAssetCleaner::class)->cleanup($expired);
        $this->assertNull(MediaAsset::find($image->id));
    }

    /**
     * =====================================================================
     * INPUT: Ref trùng và ref sai.
     * OUTPUT: extraction cleanup không cấp quyền, chỉ giữ ID dương.
     * =====================================================================
     */
    public function test_cleanup_reference_reader_is_conservative_and_deduplicates(): void
    {
        $this->assertSame([7], app(ContentMediaReferenceService::class)->referencedIds('<img data-media-asset-id="7"><img data-media-asset-id="7"><img data-media-asset-id="-2">'));
    }

    /**
     * =====================================================================
     * INPUT: Candidate/snapshot đang giữ ảnh và actor có media.delete.
     * OUTPUT: DELETE 409 trong retention; sau hết hạn DELETE thành công.
     * =====================================================================
     */
    public function test_direct_delete_protects_retained_candidate_and_failed_run_snapshot(): void
    {
        $token = $this->token(['media.delete']);
        $image = $this->image();
        $run = $this->aiRun(['result_json' => ['draft' => ['content_html' => $this->html($image)]]]);
        $this->withToken($token)->deleteJson('/api/admin/media-assets/'.$image->id)->assertConflict();
        $this->assertNotNull(MediaAsset::find($image->id));
        $run->update(['status' => 'failed', 'result_json' => [], 'source_meta_json' => ['article_source' => ['inline_image_refs' => ['I1' => $this->html($image)]]]]);
        $this->withToken($token)->deleteJson('/api/admin/media-assets/'.$image->id)->assertConflict();
        $run->update(['expires_at' => now()->subMinute()]);
        $this->withToken($token)->deleteJson('/api/admin/media-assets/'.$image->id)->assertNoContent();
        $this->assertNull(MediaAsset::find($image->id));
    }

    /**
     * =====================================================================
     * INPUT: Validator được gọi ngoài/trong transaction Post/candidate.
     * OUTPUT: Read-only ngoài transaction; FOR UPDATE khi ghi để serialize với DELETE.
     * =====================================================================
     */
    public function test_media_validation_locks_asset_rows_only_inside_a_transaction(): void
    {
        $image = $this->image();
        $actor = User::factory()->create();
        $actor->givePermissionTo('media.attach');
        $this->flushPermissionCache();
        $locks = [];
        $scopes = MediaAsset::getAllGlobalScopes();
        MediaAsset::addGlobalScope('observe_inline_lock', function (Builder $query) use (&$locks): void {
            $locks[] = $query->getQuery()->lock;
        });
        try {
            $service = app(ContentMediaReferenceService::class);
            $this->assertSame([$image->id], $service->validate($this->html($image), $actor));
            $this->assertSame([null], $locks);
            $locks = [];
            DB::transaction(function () use ($service, $image, $actor): void {
                $this->assertSame([$image->id], $service->validate($this->html($image), $actor));
            });
            $this->assertSame([true], $locks);
        } finally {
            MediaAsset::setAllGlobalScopes($scopes);
        }
    }

    /**
     * =====================================================================
     * INPUT: Queued child giữ parent_draft_snapshot, parent sau đó bỏ ảnh/xóa run.
     * OUTPUT: DELETE 409 đến khi child hết retention; không mất ảnh baseline.
     * =====================================================================
     */
    public function test_queued_child_parent_draft_snapshot_retains_images_after_parent_changes(): void
    {
        $token = $this->token(['media.delete']);
        $image = $this->image();
        $parent = $this->aiRun(['result_json' => ['draft' => ['content_html' => $this->html($image)]]]);
        $child = $this->aiRun(['status' => 'queued', 'parent_id' => $parent->id,
            'input_json' => ['parent_draft_snapshot' => ['content_html' => $this->html($image)]]]);
        $parent->update(['result_json' => ['draft' => ['content_html' => '<p>Parent đã bỏ ảnh.</p>']]]);
        $parent->delete();

        $this->assertTrue(app(ContentMediaReferenceService::class)->isReferencedByRetainedAiRun($image->id));
        $this->withToken($token)->deleteJson('/api/admin/media-assets/'.$image->id)->assertConflict();
        $child->update(['expires_at' => now()->subMinute()]);
        $this->withToken($token)->deleteJson('/api/admin/media-assets/'.$image->id)->assertNoContent();
    }
}
