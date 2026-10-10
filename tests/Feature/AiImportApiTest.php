<?php

namespace Tests\Feature;

use App\Enums\MediaAssetVisibility;
use App\Jobs\ProcessAiImportJob;
use App\Models\AiImport;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Ai\Content\ArticleImportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm tạo/poll/hủy run và quyền truy cập với queue bất đồng bộ.
 * =====================================================================
 *
 * PHPUnit kiểm tạo/poll/hủy run, quyền admin và danh sách Content AI của owner.
 * Kiểm pagination/expiry, payload summary an toàn và thumbnail/media tải theo lô.
 * HTTP/queue và database được cô lập; không gọi AI hoặc sửa dữ liệu ứng dụng.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp().
 * - tearDown().
 * - token().
 * - test_session_list_requires_authenticated_post_manager().
 * - test_session_list_validates_pagination().
 * - test_session_list_filters_owner_root_and_expiry_and_returns_safe_summaries().
 * - test_session_thumbnails_are_loaded_in_batches_and_returned_in_polling().
 * - test_session_thumbnails_handle_missing_deleted_and_private_assets().
 * - thumbnailAsset().
 * - test_store_queues_import_and_persists_options().
 * - test_import_status_is_private_to_creator().
 * - test_capabilities_are_resolved_from_registries().
 * - test_store_rejects_provider_model_and_prompt_outside_allowlist().
 * - test_store_accepts_inline_text_source().
 * - test_generic_session_endpoint_normalizes_nested_input().
 * - test_cancelled_import_is_terminal_for_queued_worker().
 * - test_cleanup_command_removes_expired_import().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Fixtures, HTTP request và dependency test đã cô lập.
 * - OUTPUT: Assertions cho output, quyền, validation và lifecycle hiện có.
 * - SIDE EFFECT: Tạo/đọc/sửa dữ liệu ở DB test; setup/teardown quản lý schema và connection riêng.
 * - EXCEPTION/TRANSACTION: Lỗi assertion/dependency truyền ra PHPUnit; transaction code nghiệp vụ chạy trong môi trường test.
 * =====================================================================
 */
class AiImportApiTest extends TestCase
{
    use UsesIsolatedDatabase;

    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo database và permission cô lập cho test AI import.

     * =====================================================================
     * INPUT: PHPUnit lifecycle.
     * OUTPUT: schema cô lập và permission đã seed.
     * SIDE EFFECT: reset database và permission cache.
     * EXCEPTION/TRANSACTION: chỉ setup test; không gọi provider thật.

     * =====================================================================
     */
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('queue.default', 'database');
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giải phóng database cô lập sau test AI import.

     * =====================================================================
     * INPUT: PHPUnit lifecycle.
     * OUTPUT: tài nguyên test được giải phóng.
     * SIDE EFFECT: dọn database cô lập.
     * EXCEPTION/TRANSACTION: chỉ cleanup test; không gọi provider thật.

     * =====================================================================
     */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo Sanctum token với quyền quản lý Post cho test.

     * =====================================================================
     * INPUT: không có.
     * OUTPUT: personal Sanctum token có posts.manage.
     * SIDE EFFECT: tạo user test và flush permission cache.
     * EXCEPTION/TRANSACTION: dùng database cô lập; không gọi provider thật.

     * =====================================================================
     */
    private function token(): string
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('posts.manage');
        $this->flushPermissionCache();
        Auth::forgetGuards();

        return $user->createToken('ai-import-test', ['admin'])->plainTextToken;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử collection không có quyền
     * =====================================================================
     *
     * INPUT:
     * - collection không có quyền.
     *
     * OUTPUT:
     * - HTTP 401/403; DB cô lập, không gọi AI.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_session_list_requires_authenticated_post_manager(): void
    {
        $this->getJson('/api/admin/ai-agent/sessions')->assertUnauthorized();
        $user = User::factory()->create(['status' => 'active']);
        Auth::forgetGuards();
        $this->withToken($user->createToken('list-test', ['admin'])->plainTextToken)
            ->getJson('/api/admin/ai-agent/sessions')->assertForbidden();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử query sai
     * =====================================================================
     *
     * INPUT:
     * - query sai.
     *
     * OUTPUT:
     * - validation 422; không truy vấn provider hoặc ghi run.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_session_list_validates_pagination(): void
    {
        $this->withToken($this->token())->getJson('/api/admin/ai-agent/sessions?page=0&per_page=101')
            ->assertUnprocessable()->assertJsonValidationErrors(['page', 'per_page']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử run gốc/con/ảnh/hết hạn và run của owner khác
     * =====================================================================
     *
     * INPUT:
     * - run gốc/con/ảnh/hết hạn và run của owner khác.
     *
     * OUTPUT:
     * - root/candidate con còn hạn của owner; không lộ body/input/URL query/key.
     *
     * SIDE EFFECT:
     * - fake queue và SQLite cô lập; không gọi provider thật.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_session_list_filters_owner_root_and_expiry_and_returns_safe_summaries(): void
    {
        Queue::fake();
        $token = $this->token();
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', [
            'target_type' => 'post', 'operation' => 'create', 'input' => ['type' => 'text', 'text' => 'Private source content'],
            'provider' => 'deterministic',
        ])->assertAccepted();
        $root = AiImport::query()->firstOrFail();
        $root->forceFill([
            'status' => 'ready', 'source_url' => 'https://example.test/article?token=private-token',
            'input_json' => ['source_type' => 'url', 'model' => 'text-model', 'api_key' => 'private-key'],
            'result_json' => ['draft' => ['title' => 'Article title', 'content_html' => '<p>Private full article</p>']],
        ])->save();
        $second = $root->replicate();
        $second->save();
        $child = $root->replicate()->fill(['parent_id' => $root->id, 'operation' => 'regenerate']);
        $child->save();
        $image = $root->replicate()->fill(['operation' => 'image']);
        $image->save();
        $expired = $root->replicate()->fill(['expires_at' => now()->subMinute()]);
        $expired->save();
        $other = $root->replicate()->fill(['created_by' => User::factory()->create(['status' => 'active'])->id]);
        $other->save();

        $response = $this->withToken($token)->getJson('/api/admin/ai-agent/sessions?per_page=1');
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.last_page', 3)->assertJsonPath('data.0.title', 'Article title')
            ->assertJsonPath('data.0.source_host', 'example.test');
        $this->assertArrayNotHasKey('input_json', $response->json('data.0'));
        $this->assertArrayNotHasKey('result_json', $response->json('data.0'));
        $this->assertStringNotContainsString('private-key', $response->getContent());
        $this->assertStringNotContainsString('private-token', $response->getContent());
        $this->assertStringNotContainsString('Private full article', $response->getContent());
        $this->withToken($token)->getJson('/api/admin/ai-agent/sessions?per_page=1&page=2')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử fixture và môi trường test đã cô lập
     * =====================================================================
     *
     * Saved thumbnails appear in lists and polling without querying each asset or conversion parent.
     *
     * INPUT:
     * - Fixture và môi trường test đã cô lập.
     *
     * OUTPUT:
     * - Assertions xác nhận contract/hành vi mong đợi.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_session_thumbnails_are_loaded_in_batches_and_returned_in_polling(): void
    {
        Queue::fake();
        Storage::fake('media_public');
        $token = $this->token();
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', [
            'text' => 'Article source', 'provider' => 'deterministic', 'generate_thumbnail' => false,
        ])->assertAccepted();
        $root = AiImport::query()->sole();
        $expected = [];

        for ($index = 0; $index < 3; $index++) {
            $asset = $this->thumbnailAsset($root->created_by);
            $run = $index === 0 ? $root : $root->replicate()->fill(['parent_id' => $root->id, 'operation' => 'regenerate']);
            $run->fill(['status' => 'ready', 'result_json' => ['draft' => [
                'title' => 'Article '.$index,
                'thumbnail' => ['media_asset_id' => $asset->id, 'source_url' => 'https://source.test/image?token=private-token'],
            ]]]);
            $run->save();
            $expected[$run->id] = $asset;
        }

        foreach (['/api/admin/ai-agent/sessions', '/api/admin/ai-agent/sessions/'.$root->id.'/candidates'] as $endpoint) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->withToken($token)->getJson($endpoint)->assertOk()->assertJsonCount(3, 'data');
            $queries = collect(DB::getQueryLog());
            DB::disableQueryLog();

            $mediaQueries = $queries->filter(fn (array $query): bool => str_contains($query['query'], 'from "media_assets"')
                || str_contains($query['query'], 'from "media"'));
            $this->assertCount(2, $mediaQueries, 'Media assets and files must each use one batch query, including conversion parents.');
            $items = collect($response->json('data'))->keyBy($endpoint === '/api/admin/ai-agent/sessions' ? 'id' : 'job_id');
            foreach ($expected as $id => $asset) {
                $thumbnail = $items->get($id)['thumbnail'];
                $this->assertSame($asset->id, $thumbnail['id']);
                $this->assertSame('Article cover', $thumbnail['alt_text']);
                $this->assertStringContainsString('cover.webp', $thumbnail['file']['url']);
                $this->assertStringContainsString('thumb', $thumbnail['file']['preview_url']);
                $this->assertArrayNotHasKey('disk', $thumbnail['file']);
            }
        }

        $this->withToken($token)->getJson('/api/admin/ai-agent/sessions/'.$root->id)
            ->assertOk()->assertJsonPath('data.thumbnail.id', $expected[$root->id]->id)
            ->assertJsonPath('data.thumbnail.alt_text', 'Article cover');
        $this->withToken($token)->getJson('/api/admin/ai-agent/sessions')
            ->assertOk()->assertDontSee('private-token', false);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm thử fixture và môi trường test đã cô lập
     * =====================================================================
     *
     * Missing/deleted assets return null, and private thumbnails never expose a storage URL.
     *
     * INPUT:
     * - Fixture và môi trường test đã cô lập.
     *
     * OUTPUT:
     * - Assertions xác nhận contract/hành vi mong đợi.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    public function test_session_thumbnails_handle_missing_deleted_and_private_assets(): void
    {
        Queue::fake();
        Storage::fake('media_public');
        Storage::fake('media_private');
        $token = $this->token();
        $this->withToken($token)->postJson('/api/admin/ai-agent/sessions', [
            'text' => 'Article source', 'provider' => 'deterministic', 'generate_thumbnail' => false,
        ])->assertAccepted();
        $run = AiImport::query()->sole();
        $deleted = $this->thumbnailAsset($run->created_by);
        $deleted->delete();

        foreach ([null, 999999, $deleted->id] as $assetId) {
            $run->update(['result_json' => ['draft' => ['thumbnail' => ['media_asset_id' => $assetId]]]]);
            $this->withToken($token)->getJson('/api/admin/ai-agent/sessions')
                ->assertOk()->assertJsonPath('data.0.thumbnail', null);
            $this->withToken($token)->getJson('/api/admin/ai-agent/sessions/'.$run->id)
                ->assertOk()->assertJsonPath('data.thumbnail', null);
        }

        $private = $this->thumbnailAsset($run->created_by, MediaAssetVisibility::Private);
        $run->update(['result_json' => ['draft' => ['thumbnail' => ['media_asset_id' => $private->id]]]]);
        $this->withToken($token)->getJson('/api/admin/ai-agent/sessions')->assertOk()
            ->assertJsonPath('data.0.thumbnail.id', $private->id)
            ->assertJsonPath('data.0.thumbnail.file.url', null)
            ->assertJsonPath('data.0.thumbnail.file.preview_url', null);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo thumbnail fixture phục vụ kiểm payload MediaLibrary
     * =====================================================================
     *
     * Persist file metadata for DTO/query tests; no real upload or image conversion is required.
     *
     * INPUT:
     * - Fixture và môi trường test đã cô lập.
     *
     * OUTPUT:
     * - Assertions xác nhận contract/hành vi mong đợi.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý fixture hoặc dữ liệu trong database test; không gọi AI thật hay sửa dữ liệu ứng dụng.
     *
     * EXCEPTION/TRANSACTION:
     * - Lỗi assertion hoặc dependency truyền ra PHPUnit; transaction nghiệp vụ chạy trên DB test, setup/teardown quản lý schema riêng.
     *
     * =====================================================================
     */
    private function thumbnailAsset(int $ownerId, MediaAssetVisibility $visibility = MediaAssetVisibility::Public): MediaAsset
    {
        $asset = MediaAsset::factory()->image()->create([
            'created_by' => $ownerId, 'visibility' => $visibility, 'alt_text' => 'Article cover',
        ]);
        $disk = $visibility === MediaAssetVisibility::Public ? 'media_public' : 'media_private';
        $asset->media()->create([
            'collection_name' => 'library', 'name' => 'cover', 'file_name' => 'cover.webp',
            'mime_type' => 'image/webp', 'disk' => $disk, 'conversions_disk' => $disk,
            'size' => 100, 'manipulations' => [], 'custom_properties' => [],
            'generated_conversions' => ['thumb' => true], 'responsive_images' => [],
        ]);

        return $asset;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng import lưu options và dispatch queue.

     * =====================================================================
     * INPUT: URL/options import.
     * OUTPUT: assertion 202 và job queued.
     * SIDE EFFECT: fake queue, ghi AiImport test.
     * EXCEPTION/TRANSACTION: dùng database cô lập; không gọi provider thật.

     * =====================================================================
     */
    public function test_store_queues_import_and_persists_options(): void
    {
        Queue::fake();
        $response = $this->withToken($this->token())->postJson('/api/admin/posts/ai/import', [
            'url' => 'https://example.test/article', 'language' => 'vi', 'generate_thumbnail' => true,
            'provider' => 'deterministic',
        ]);

        $response->assertStatus(202)->assertJsonPath('data.status', 'queued')->assertJsonPath('data.progress', 0);
        $this->assertDatabaseHas('ai_imports', ['status' => 'queued', 'source_url' => 'https://example.test/article']);
        Queue::assertPushed(ProcessAiImportJob::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng status import chỉ được đọc bởi owner.

     * =====================================================================
     * INPUT: UUID import của user khác.
     * OUTPUT: assertion ownership 404.
     * SIDE EFFECT: fake queue và database test.
     * EXCEPTION/TRANSACTION: dùng database cô lập; không gọi provider thật.

     * =====================================================================
     */
    public function test_import_status_is_private_to_creator(): void
    {
        Queue::fake();
        $this->withToken($this->token())->postJson('/api/admin/posts/ai/import', ['url' => 'https://example.test/article', 'provider' => 'deterministic'])->assertStatus(202);
        $import = AiImport::query()->firstOrFail();
        $other = User::factory()->create(['status' => 'active']);
        $other->givePermissionTo('posts.manage');
        $this->flushPermissionCache();
        $otherToken = $other->createToken('other', ['admin'])->plainTextToken;
        Auth::forgetGuards();

        $this->withToken($otherToken)->getJson('/api/admin/posts/ai/import/'.$import->id)->assertNotFound();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng capabilities được resolve và redact từ registry.

     * =====================================================================
     * INPUT: target Post và quyền AI của admin.
     * OUTPUT: capabilities không lộ endpoint/API key, chỉ chứa provider/model allowlist.
     * SIDE EFFECT: chỉ đọc config; không tạo job hoặc gọi provider.
     * EXCEPTION/TRANSACTION: dùng database cô lập; không gọi provider thật.

     * =====================================================================
     */
    public function test_capabilities_are_resolved_from_registries(): void
    {
        $response = $this->withToken($this->token())
            ->getJson('/api/admin/ai-agent/capabilities/post');

        $response->assertOk()
            ->assertJsonPath('data.target_type', 'post')
            ->assertJsonPath('data.prompts.0.key', 'post.create.from_url')
            ->assertJsonPath('data.schemas.0.key', 'post.content.v1');
        $configuredKey = (string) config('ai.providers.connections.http-json.key');
        if ($configuredKey !== '') {
            $this->assertStringNotContainsString($configuredKey, $response->getContent());
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng provider/model/prompt phải nằm trong allowlist.

     * =====================================================================
     * INPUT: provider/model/prompt không nằm allowlist.
     * OUTPUT: validation 422.
     * SIDE EFFECT: không tạo AiImport hoặc dispatch queue.
     * EXCEPTION/TRANSACTION: validation ở HTTP boundary; dùng database cô lập.

     * =====================================================================
     */
    public function test_store_rejects_provider_model_and_prompt_outside_allowlist(): void
    {
        Queue::fake();
        $token = $this->token();

        $this->withToken($token)->postJson('/api/admin/posts/ai/import', [
            'url' => 'https://example.test/article', 'provider' => 'openai',
        ])->assertStatus(422)->assertJsonValidationErrors('provider');

        $this->withToken($token)->postJson('/api/admin/posts/ai/import', [
            'url' => 'https://example.test/article', 'provider' => 'deterministic', 'model' => 'unknown',
        ])->assertStatus(422)->assertJsonValidationErrors('model');

        $this->withToken($token)->postJson('/api/admin/posts/ai/import', [
            'url' => 'https://example.test/article', 'prompt_key' => 'post.unknown',
            'provider' => 'deterministic',
        ])->assertStatus(422)->assertJsonValidationErrors('prompt_key');

        Queue::assertNothingPushed();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng import hỗ trợ nguồn text inline.

     * =====================================================================
     * INPUT: text inline thay cho URL.
     * OUTPUT: queued run có source_type=text.
     * SIDE EFFECT: lưu source_text và dispatch job qua Queue fake; không gọi HTTP.
     * EXCEPTION/TRANSACTION: dùng database cô lập; không gọi provider thật.

     * =====================================================================
     */
    public function test_store_accepts_inline_text_source(): void
    {
        Queue::fake();
        $response = $this->withToken($this->token())->postJson('/api/admin/posts/ai/import', [
            'text' => "Tiêu đề inline\n\nNội dung do quản trị viên nhập.",
            'provider' => 'deterministic',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.source_type', 'text')
            ->assertJsonPath('data.source_url', null);
        $this->assertDatabaseHas('ai_imports', [
            'status' => 'queued',
            'source_url' => '',
            'source_text' => "Tiêu đề inline\n\nNội dung do quản trị viên nhập.",
        ]);
        $this->assertSame('post.create.from_text', data_get(AiImport::query()->firstOrFail()->input_json, 'prompt_key'));
        Queue::assertPushed(ProcessAiImportJob::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng session endpoint chuẩn hóa nested input.

     * =====================================================================
     * INPUT: contract session generic từ aiAgentService.
     * OUTPUT: AiImport queued tương thích pipeline hiện tại.
     * SIDE EFFECT: chuẩn hóa input ở FormRequest, lưu run và dispatch qua Queue fake.
     * EXCEPTION/TRANSACTION: dùng database cô lập; không gọi provider thật.

     * =====================================================================
     */
    public function test_generic_session_endpoint_normalizes_nested_input(): void
    {
        Queue::fake();
        $response = $this->withToken($this->token())->postJson('/api/admin/ai-agent/sessions', [
            'target_type' => 'post',
            'operation' => 'create',
            'input' => ['type' => 'text', 'text' => 'Nội dung generic session'],
            'output_language' => 'vi',
            'requested_outputs' => ['title', 'content'],
            'provider' => 'deterministic',
            'model' => 'deterministic',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.source_type', 'text')
            ->assertJsonPath('data.status', 'queued');
        $import = AiImport::query()->firstOrFail();
        $this->assertSame('Nội dung generic session', $import->source_text);
        $this->assertFalse((bool) data_get($import->input_json, 'generate_thumbnail'));
        Queue::assertPushed(ProcessAiImportJob::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng cancelled là trạng thái terminal của worker.

     * =====================================================================
     * INPUT: import đang chạy và request cancel của chính owner.
     * OUTPUT: lifecycle cancelled; worker nhận job cũ không chạy pipeline lại.
     * SIDE EFFECT: cập nhật status/error code, không gọi provider hoặc source fetcher.
     * EXCEPTION/TRANSACTION: dùng database cô lập và mock service.

     * =====================================================================
     */
    public function test_cancelled_import_is_terminal_for_queued_worker(): void
    {
        Queue::fake();
        $token = $this->token();
        $this->withToken($token)->postJson('/api/admin/posts/ai/import', [
            'url' => 'https://example.test/cancel',
            'provider' => 'deterministic',
        ])->assertStatus(202);
        $import = AiImport::query()->firstOrFail();
        $import->update(['status' => 'fetching', 'current_step' => 'fetching', 'progress' => 15]);

        $this->withToken($token)
            ->postJson('/api/admin/posts/ai/import/'.$import->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.error_code', 'CANCELLED');

        $service = Mockery::mock(ArticleImportService::class);
        $service->shouldNotReceive('run');
        (new ProcessAiImportJob($import->id))->handle($service);

        $this->assertSame('cancelled', $import->fresh()->status);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm chứng cleanup command dọn candidate hết hạn.

     * =====================================================================
     * INPUT: candidate đã quá expires_at.
     * OUTPUT: scheduler command xóa candidate hết hạn.
     * SIDE EFFECT: dọn AiImport qua command chính thức, không gọi provider.
     * EXCEPTION/TRANSACTION: chỉ xóa fixture trong database test cô lập.

     * =====================================================================
     */
    public function test_cleanup_command_removes_expired_import(): void
    {
        Queue::fake();
        $this->withToken($this->token())->postJson('/api/admin/posts/ai/import', [
            'url' => 'https://example.test/expired',
            'provider' => 'deterministic',
        ])->assertStatus(202);
        $import = AiImport::query()->firstOrFail();
        // Fixture legacy dựng trực tiếp, chưa chạy worker/contract archive v1.
        $import->update(['archive_version' => null, 'status' => 'ready', 'expires_at' => now()->subMinute()]);

        $this->artisan('ai-import:cleanup')
            ->expectsOutput('Đã dọn 1 AI import.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('ai_imports', ['id' => $import->id]);
    }
}
