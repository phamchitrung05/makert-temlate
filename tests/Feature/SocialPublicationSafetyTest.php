<?php

namespace Tests\Feature;

use App\Enums\MediaAssetField;
use App\Enums\SocialPublicationStatus;
use App\Jobs\PublishSocialPostJob;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPublication;
use App\Models\User;
use App\Services\Marketing\Publishing\FacebookPublisher;
use App\Services\Marketing\SocialPostLifecycleService;
use App\Services\Marketing\SocialPublicationService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm target unique và journal chống đăng Facebook trùng.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp()/tearDown(): cô lập database, storage, queue và HTTP.
 * - test_target_sync_uses_variant_key_and_preserves_published_status(): kiểm sync.
 * - test_successful_job_is_not_published_twice(): kiểm redelivery.
 * - test_feed_timeout_blocks_redelivery_and_manual_retry(): kiểm kết quả chưa rõ.
 * - test_gateway_timeout_after_remote_acceptance_does_not_create_another_post(): kiểm 504 sau khi đã đăng.
 * - test_unconfirmed_feed_blocks_new_generation_and_queue(): kiểm đường publish lại.
 * - test_interrupted_feed_is_not_sent_again(): kiểm worker bị dừng.
 * - test_confirmed_external_id_is_recovered_without_http(): kiểm checkpoint thành công.
 * - test_photo_upload_retry_reuses_already_uploaded_media(): kiểm upload bị gián đoạn.
 * - test_feed_rate_limit_can_retry_without_uploading_photos_again(): kiểm lỗi 429.
 * - test_ambiguous_feed_response_is_not_retried()/ambiguousResponses(): kiểm 5xx và JSON thiếu ID.
 * - test_legacy_post_gets_non_null_target_variant(): kiểm post cũ thiếu variant.
 * - test_interrupted_upload_is_flagged_for_confirmation(): kiểm job gián đoạn trước feed.
 * - test_deselecting_then_reselecting_page_keeps_unconfirmed_history(): kiểm đổi selection không xóa journal.
 * - fixture(): dựng post đã duyệt, gallery và publication.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): fixture/HTTP fake -> assertion DB và số request.
 * =====================================================================
 */
final class SocialPublicationSafetyTest extends TestCase
{
    use UsesIsolatedDatabase;

    /** Input: không có. Output: môi trường test độc lập, chặn HTTP thật. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        Queue::fake();
        Storage::fake('media_public');
        config()->set('media-library.asset_disks.public', 'media_public');
        Http::preventStrayRequests();
    }

    /** Input: database test. Output: giải phóng connection. */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Input: account lặp và target đã đăng. Output: một target đúng variant, giữ published. */
    public function test_target_sync_uses_variant_key_and_preserves_published_status(): void
    {
        [, $post, $publication] = $this->fixture();
        $target = $publication->socialPostTarget;
        $target->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $service = app(SocialPostLifecycleService::class);
        $ids = [$target->social_account_id, $target->social_account_id];
        $service->syncTargets($post, $ids);
        $service->syncTargets($post, $ids);

        $this->assertSame(1, $post->targets()->count());
        $this->assertSame($post->variants()->firstOrFail()->id, $target->fresh()->social_post_variant_id);
        $this->assertSame(SocialPublicationStatus::Published, $target->fresh()->status);
    }

    /** Input: job giao hai lần. Output: chỉ ba upload và một feed, status published. */
    public function test_successful_job_is_not_published_twice(): void
    {
        [$actor, $post, $publication] = $this->fixture();
        Http::fakeSequence()->push(['id' => 'photo-1'])->push(['id' => 'photo-2'])
            ->push(['id' => 'photo-3'])->push(['id' => 'page_post']);
        $job = new PublishSocialPostJob($publication->id);
        $job->handle(app(FacebookPublisher::class));
        $job->handle(app(FacebookPublisher::class));

        Http::assertSentCount(4);
        $this->assertSame(SocialPublicationStatus::Published, $publication->fresh()->status);
        $this->assertSame('page_post', $publication->fresh()->external_post_id);
        $this->assertSame('published', $post->fresh()->publication_status->value);
        app(SocialPublicationService::class)->queue($post->fresh(), $actor);
        $this->assertSame('published', $post->fresh()->publication_status->value);
        $this->assertDatabaseCount('social_publications', 1);
    }

    /** Input: feed đã nhận request nhưng kết nối mất phản hồi. Output: không gửi feed lần hai. */
    public function test_feed_timeout_blocks_redelivery_and_manual_retry(): void
    {
        [, , $publication] = $this->fixture();
        $feedRequests = 0;
        Http::fake([
            '*/photos' => Http::response(['id' => 'photo']),
            '*/feed' => function () use (&$feedRequests) {
                $feedRequests++;
                throw new ConnectionException('Operation timed out');
            },
        ]);
        $job = new PublishSocialPostJob($publication->id);
        $job->handle(app(FacebookPublisher::class));
        $this->assertSame('SOCIAL_PUBLISH_UNCERTAIN', $publication->fresh()->error_code);
        $this->assertSame(SocialPublicationStatus::Failed, $publication->fresh()->status);
        $job->handle(app(FacebookPublisher::class));
        Http::assertSentCount(3);
        $this->assertSame(1, $feedRequests);

        $this->expectException(ValidationException::class);
        app(SocialPublicationService::class)->retry($publication->fresh());
    }

    /** Input: Facebook đã tạo bài nhưng gateway trả 504. Output: redelivery/manual retry không tạo bài thứ hai. */
    public function test_gateway_timeout_after_remote_acceptance_does_not_create_another_post(): void
    {
        [, , $publication] = $this->fixture();
        $remotePosts = [];
        Http::fake([
            '*/photos' => Http::response(['id' => 'photo']),
            '*/feed' => function () use (&$remotePosts) {
                $remotePosts[] = 'accepted-page-post';

                return Http::response(['error' => ['message' => 'Gateway Timeout']], 504);
            },
        ]);
        $job = new PublishSocialPostJob($publication->id);
        $job->handle(app(FacebookPublisher::class));
        $job->handle(app(FacebookPublisher::class));
        try {
            app(SocialPublicationService::class)->retry($publication->fresh());
            $this->fail('Không được retry khi gateway timeout sau khi đã gửi feed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('publication', $exception->errors());
        }

        Http::assertSentCount(4);
        $this->assertSame(['accepted-page-post'], $remotePosts);
        $this->assertSame('SOCIAL_PUBLISH_UNCERTAIN', $publication->fresh()->error_code);
        $this->assertNotEmpty($publication->fresh()->response_metadata_json['feed_request_started_at']);
    }

    /** Input: request cũ chưa rõ kết quả, generation mới. Output: publish API bị chặn trước HTTP. */
    public function test_unconfirmed_feed_blocks_new_generation_and_queue(): void
    {
        [$actor, $post, $publication] = $this->fixture();
        $publication->forceFill([
            'status' => 'failed', 'response_metadata_json' => ['feed_request_started_at' => now()->toIso8601String()],
        ])->save();
        $post->forceFill(['generation_no' => 1])->save();
        Http::fake();

        try {
            app(SocialPublicationService::class)->queue($post->fresh(), $actor);
            $this->fail('Publish chưa xác nhận phải bị chặn.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('publication', $exception->errors());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('social_publications', 1);
    }

    /** Input: worker bị dừng sau checkpoint trước khi lưu ID. Output: job giao lại không gọi API. */
    public function test_interrupted_feed_is_not_sent_again(): void
    {
        [, , $publication] = $this->fixture();
        $publication->forceFill([
            'status' => 'publishing', 'response_metadata_json' => ['feed_request_started_at' => now()->toIso8601String()],
        ])->save();
        Http::fake();
        (new PublishSocialPostJob($publication->id))->handle(app(FacebookPublisher::class));

        Http::assertNothingSent();
        $this->assertSame('SOCIAL_PUBLISH_UNCERTAIN', $publication->fresh()->error_code);
    }

    /** Input: ID Facebook đã lưu nhưng aggregate chưa cập nhật. Output: phục hồi published không HTTP. */
    public function test_confirmed_external_id_is_recovered_without_http(): void
    {
        [, $post, $publication] = $this->fixture();
        $publication->forceFill([
            'status' => 'publishing', 'external_post_id' => 'confirmed-post',
            'response_metadata_json' => ['feed_request_started_at' => now()->toIso8601String()],
        ])->save();
        Http::fake();
        (new PublishSocialPostJob($publication->id))->handle(app(FacebookPublisher::class));

        Http::assertNothingSent();
        $this->assertSame(SocialPublicationStatus::Published, $publication->fresh()->status);
        $this->assertSame('published', $post->fresh()->publication_status->value);
    }

    /** Input: ảnh thứ hai lỗi mạng. Output: retry chỉ upload ảnh còn thiếu và tạo đúng một feed. */
    public function test_photo_upload_retry_reuses_already_uploaded_media(): void
    {
        [, , $publication] = $this->fixture();
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if (++$calls === 2) {
                throw new ConnectionException('Operation timed out');
            }

            return Http::response(['id' => 'external-'.$calls]);
        });
        $job = new PublishSocialPostJob($publication->id);
        try {
            $job->handle(app(FacebookPublisher::class));
            $this->fail('Lỗi upload trước feed phải được retry.');
        } catch (ConnectionException) {
            $this->assertSame(SocialPublicationStatus::Retryable, $publication->fresh()->status);
        }
        $job->handle(app(FacebookPublisher::class));

        Http::assertSentCount(4);
        $this->assertSame(5, $calls);
        $this->assertSame(3, count($publication->fresh()->response_metadata_json['media_ids']));
        $this->assertSame(SocialPublicationStatus::Published, $publication->fresh()->status);
    }

    /** Input: feed bị từ chối 429 rồi thành công. Output: retry feed, dùng lại ba photo ID. */
    public function test_feed_rate_limit_can_retry_without_uploading_photos_again(): void
    {
        [, , $publication] = $this->fixture();
        Http::fakeSequence()->push(['id' => 'photo-1'])->push(['id' => 'photo-2'])->push(['id' => 'photo-3'])
            ->push(['error' => ['message' => 'Rate limited']], 429)->push(['id' => 'page_post']);
        $job = new PublishSocialPostJob($publication->id);
        try {
            $job->handle(app(FacebookPublisher::class));
            $this->fail('429 phải được retry.');
        } catch (\RuntimeException) {
            $this->assertSame(SocialPublicationStatus::Retryable, $publication->fresh()->status);
        }
        $job->handle(app(FacebookPublisher::class));

        Http::assertSentCount(5);
        $this->assertSame(SocialPublicationStatus::Published, $publication->fresh()->status);
    }

    /** Input: feed trả 5xx hoặc thiếu ID. Output: không retry dù job bị giao lại. */
    #[DataProvider('ambiguousResponses')]
    public function test_ambiguous_feed_response_is_not_retried(int $status, array $body): void
    {
        [, , $publication] = $this->fixture();
        Http::fakeSequence()->push(['id' => 'photo-1'])->push(['id' => 'photo-2'])
            ->push(['id' => 'photo-3'])->push($body, $status);
        $job = new PublishSocialPostJob($publication->id);
        $job->handle(app(FacebookPublisher::class));
        $job->handle(app(FacebookPublisher::class));

        Http::assertSentCount(4);
        $this->assertSame('SOCIAL_PUBLISH_UNCERTAIN', $publication->fresh()->error_code);
    }

    /** Input: không có. Output: các phản hồi chưa xác định được bài đã tạo hay chưa. */
    public static function ambiguousResponses(): array
    {
        return ['server error' => [503, ['error' => ['message' => 'Unavailable']]], 'missing ID' => [200, []]];
    }

    /** Input: post cũ chưa có variant. Output: sync luôn tạo target có variant thật và không trùng. */
    public function test_legacy_post_gets_non_null_target_variant(): void
    {
        [$actor, , $publication] = $this->fixture();
        $post = SocialPost::query()->create(['caption' => 'Legacy post', 'created_by' => $actor->id]);
        $ids = [$publication->socialPostTarget->social_account_id];
        $lifecycle = app(SocialPostLifecycleService::class);
        $lifecycle->syncTargets($post, $ids);
        $lifecycle->syncTargets($post, $ids);

        $this->assertSame(1, $post->targets()->count());
        $this->assertNotNull($post->targets()->firstOrFail()->social_post_variant_id);
    }

    /** Input: job gián đoạn trong pha upload. Output: báo cần xác nhận, chặn retry khi chưa rõ worker cũ. */
    public function test_interrupted_upload_is_flagged_for_confirmation(): void
    {
        [, , $publication] = $this->fixture();
        $publication->forceFill(['status' => 'publishing'])->save();
        Http::fake();
        (new PublishSocialPostJob($publication->id))->handle(app(FacebookPublisher::class));

        Http::assertNothingSent();
        $this->assertTrue($publication->fresh()->requiresConfirmation());
        $this->expectException(ValidationException::class);
        app(SocialPublicationService::class)->retry($publication->fresh());
    }

    /** Input: bỏ chọn rồi chọn lại Page có kết quả publish chưa rõ. Output: giữ journal và chặn đăng lại. */
    public function test_deselecting_then_reselecting_page_keeps_unconfirmed_history(): void
    {
        [$actor, $post, $publication] = $this->fixture();
        $publication->forceFill([
            'status' => 'failed', 'response_metadata_json' => ['feed_request_started_at' => now()->toIso8601String()],
        ])->save();
        $target = $publication->socialPostTarget;
        $lifecycle = app(SocialPostLifecycleService::class);
        $lifecycle->syncTargets($post, []);
        $this->assertFalse($target->fresh()->enabled);
        $this->assertDatabaseHas('social_publications', ['id' => $publication->id]);
        $lifecycle->syncTargets($post, [$target->social_account_id]);
        $this->assertTrue($target->fresh()->enabled);
        $this->assertSame(1, $post->targets()->count());

        $this->expectException(ValidationException::class);
        app(SocialPublicationService::class)->queue($post->fresh(), $actor);
    }

    /** Input: database test. Output: actor, post approved có gallery và publication queued. */
    private function fixture(): array
    {
        $actor = User::factory()->create();
        $account = SocialAccount::query()->create([
            'provider' => 'facebook', 'account_type' => 'page', 'external_id' => 'test-page',
            'display_name' => 'Test Page', 'access_token' => 'test-token', 'status' => 'active', 'created_by' => $actor->id,
        ]);
        $post = app(SocialPostLifecycleService::class)->create([
            'caption' => 'Nội dung đã duyệt', 'target_account_ids' => [$account->id],
        ], $actor->id);
        $variant = $post->variants()->firstOrFail();
        $variant->forceFill(['review_status' => 'approved', 'content_status' => 'ready', 'generation_status' => 'ready'])->save();
        foreach (MediaAsset::factory()->count(3)->image()->create(['created_by' => $actor->id]) as $index => $asset) {
            $asset->media()->create([
                'collection_name' => 'library', 'name' => 'Photo', 'file_name' => 'photo.jpg',
                'disk' => 'media_public', 'mime_type' => 'image/jpeg', 'size' => 100,
                'manipulations' => [], 'custom_properties' => [], 'generated_conversions' => [], 'responsive_images' => [],
            ]);
            MediaAssetUsage::query()->create([
                'media_asset_id' => $asset->id, 'linkable_type' => 'social_post_variant', 'linkable_id' => $variant->id,
                'field' => MediaAssetField::SocialPostFacebookImages, 'sort_order' => $index,
            ]);
        }
        $post->forceFill(['review_status' => 'approved', 'generation_status' => 'ready'])->save();
        $queued = app(SocialPublicationService::class)->queue($post->fresh(), $actor);
        $publication = SocialPublication::query()->findOrFail($queued['publications'][0]['publication_id']);

        return [$actor, $post->fresh(), $publication];
    }
}
