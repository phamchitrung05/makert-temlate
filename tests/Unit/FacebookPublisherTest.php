<?php

namespace Tests\Unit;

use App\Enums\MediaAssetVisibility;
use App\Enums\SocialPlatform;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialPostVariant;
use App\Services\Marketing\Publishing\FacebookPublisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

final class FacebookPublisherTest extends TestCase
{
    public function test_publisher_uploads_gallery_then_creates_feed_post(): void
    {
        Http::fakeSequence()
            ->push(['id' => 'media-1'])
            ->push(['id' => 'media-2'])
            ->push(['id' => 'media-3'])
            ->push(['id' => 'post-1']);

        $account = new SocialAccount([
            'provider' => SocialPlatform::Facebook,
            'external_id' => 'page-1',
            'access_token' => 'demo-token',
            'status' => 'active',
        ]);
        $target = new SocialPostTarget(['platform' => SocialPlatform::Facebook]);
        $target->setRelation('socialAccount', $account);
        $variant = new SocialPostVariant([
            'platform' => SocialPlatform::Facebook,
            'content_json' => ['body' => 'Nội dung Facebook', 'bullet_points' => ['Một ý'], 'hashtags' => ['#demo']],
        ]);

        $images = array_map(function (): MediaAsset {
            $image = Mockery::mock(MediaAsset::class)->makePartial();
            $media = Mockery::mock(Media::class);
            $media->shouldReceive('getUrl')->andReturn('https://cdn.example.test/image.jpg');
            $image->shouldReceive('getFirstMedia')->with('library')->andReturn($media);
            $image->visibility = MediaAssetVisibility::Public;

            return $image;
        }, range(1, 3));

        $result = app(FacebookPublisher::class)->publish(new SocialPost(['caption' => 'legacy']), $target, $variant, $images);

        $this->assertSame('post-1', $result['external_id']);
        $this->assertSame(['media-1', 'media-2', 'media-3'], $result['metadata']['media_ids']);
        Http::assertSentCount(4);
        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/feed')
                && $request['message'] === "Nội dung Facebook\n\n✅ Một ý\n\n#demo";
        });
    }
}
