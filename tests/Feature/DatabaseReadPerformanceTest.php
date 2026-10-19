<?php

namespace Tests\Feature;

use App\Models\AiImport;
use App\Models\Post;
use App\Models\Resource;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Ai\Runs\AiImportQueryService;
use App\Services\Catalog\ResourceCatalogService;
use App\Services\Marketing\SocialPostGenerationStateService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm index thực thi, JSON projection và rollback trên DB test.
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp()/tearDown(): cô lập SQLite và quyền post.
 * - test_ai_list_uses_index_and_preserves_owner_and_legacy_target_scopes(): kiểm list thật.
 * - test_social_refresh_uses_embedded_generation_index_instead_of_retry_generation(): kiểm generation.
 * - test_post_and_catalog_queries_use_ordered_indexes(): kiểm kế hoạch truy vấn.
 * - test_migration_rollback_preserves_json_and_rebuilds_projections(): kiểm dữ liệu trước/sau rollback.
 * - test_replicated_runs_recompute_json_projections(): kiểm clone không ghi cột virtual.
 * - createRun(): tạo AI import fixture.
 * - recordedQueries(): ghi SQL trong callback.
 * - assertIndexed(): chạy EXPLAIN QUERY PLAN cho SQL thật.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): service/schema trong SQLite test -> assertion scope, plan và dữ liệu.
 * =====================================================================
 */
final class DatabaseReadPerformanceTest extends TestCase
{
    use UsesIsolatedDatabase;

    private User $actor;

    /** Input: không có. Output: schema và admin chỉ có quyền quản lý bài viết. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
        $this->seed(RolePermissionSeeder::class);
        $this->actor = User::factory()->create(['status' => 'active']);
        $this->actor->givePermissionTo('posts.manage');
        $this->flushPermissionCache();
    }

    /** Input: môi trường test. Output: giải phóng schema và connection. */
    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Input: target rõ ràng, legacy null, target lạ và owner khác. Output: scope không đổi và index lọc/sort được dùng. */
    public function test_ai_list_uses_index_and_preserves_owner_and_legacy_target_scopes(): void
    {
        $post = $this->createRun(['target_type' => 'post']);
        $missing = $this->createRun([]);
        $null = $this->createRun(['target_type' => null]);
        $literalNull = $this->createRun(['target_type' => 'null']);
        $this->createRun(['target_type' => 'social_post']);
        $this->createRun(['target_type' => 'POST']);
        $this->createRun(['target_type' => 'post'], ['created_by' => User::factory()->create()->id]);
        $service = app(AiImportQueryService::class);
        $queries = $this->recordedQueries(function () use ($service, $post, $missing, $null): void {
            $this->assertEqualsCanonicalizing([$post->id, $missing->id, $null->id], $service->paginateFor($this->actor, [])->getCollection()->modelKeys());
        });
        $select = collect($queries)->first(fn (array $query): bool => str_contains($query['query'], 'order by') && str_contains($query['query'], 'ai_imports'));
        $this->assertIndexed($select, 'ai_imports_owner_target_created_index');
        $this->assertSame('null', $literalNull->fresh()->input_target_type);

        $post->update(['input_json' => ['target_type' => 'social_post']]);
        $this->assertSame('social_post', $post->fresh()->input_target_type);
        $this->assertNotContains($post->id, $service->paginateFor($this->actor, [])->getCollection()->modelKeys());
    }

    /** Input: retry generation khác generation của post trong JSON. Output: refresh đọc đúng generation và không scan toàn bảng. */
    public function test_social_refresh_uses_embedded_generation_index_instead_of_retry_generation(): void
    {
        $post = SocialPost::query()->create(['generation_no' => 3, 'created_by' => $this->actor->id]);
        $input = ['social_post_id' => $post->id, 'generation_no' => 3];
        $this->createRun($input, ['operation' => 'social_caption', 'status' => 'ready', 'generation_no' => 99]);
        $this->createRun($input, ['operation' => 'social_image', 'status' => 'failed', 'generation_no' => 98]);
        $this->createRun(array_replace($input, ['generation_no' => 2]), ['operation' => 'social_image', 'status' => 'ready']);
        $queries = $this->recordedQueries(function () use ($post): void {
            $result = app(SocialPostGenerationStateService::class)->refresh($post, 3);
            $this->assertSame('partial', $result->generation_status->value);
        });
        $select = collect($queries)->first(fn (array $query): bool => str_contains($query['query'], 'from "ai_imports"'));

        $this->assertIndexed($select, 'ai_imports_social_generation_index');
    }

    /** Input: list post và catalog public. Output: index phục vụ filter và order, không có sort tạm. */
    public function test_post_and_catalog_queries_use_ordered_indexes(): void
    {
        Resource::factory()->create(['type' => 'template', 'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subMinute()]);
        $queries = $this->recordedQueries(function (): void {
            Post::query()->orderByDesc('created_at')->orderByDesc('id')->limit(25)->get();
            app(ResourceCatalogService::class)->paginate([]);
        });
        $posts = collect($queries)->first(fn (array $query): bool => str_contains($query['query'], 'from "posts"'));
        $catalog = collect($queries)->first(fn (array $query): bool => str_contains($query['query'], 'from "resources"') && str_contains($query['query'], 'order by'));

        $this->assertIndexed($posts, 'posts_active_created_index');
        $this->assertIndexed($catalog, 'resources_public_catalog_index');
    }

    /** Input: AI import có dữ liệu trước rollback. Output: JSON còn nguyên và cột virtual tái tạo đúng. */
    public function test_migration_rollback_preserves_json_and_rebuilds_projections(): void
    {
        $input = ['target_type' => 'post', 'social_post_id' => 42, 'generation_no' => 7];
        $run = $this->createRun($input);
        $migration = require database_path('migrations/2026_10_17_090000_add_read_path_indexes.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('ai_imports', 'input_target_type'));
        $this->assertSame($input, $run->fresh()->input_json);
        $migration->up();
        $current = $run->fresh();

        $this->assertSame($input, $current->input_json);
        $this->assertSame('post', $current->input_target_type);
        $this->assertSame(42, $current->input_social_post_id);
        $this->assertSame(7, $current->input_generation_no);
    }

    /** Input: run được load rồi clone/cloneQuietly và thay JSON. Output: không INSERT cột generated; projection tính lại đúng. */
    public function test_replicated_runs_recompute_json_projections(): void
    {
        $source = $this->createRun(['target_type' => 'post', 'social_post_id' => 42, 'generation_no' => 7])->fresh();
        foreach ([false, true] as $quiet) {
            $copy = $quiet ? $source->replicateQuietly() : $source->replicate();
            $copy->fill(['input_json' => ['target_type' => 'social_post', 'social_post_id' => 51, 'generation_no' => 8]])->save();
            $current = $copy->fresh();

            $this->assertNotSame($source->id, $current->id);
            $this->assertSame('social_post', $current->input_target_type);
            $this->assertSame(51, $current->input_social_post_id);
            $this->assertSame(8, $current->input_generation_no);
        }
    }

    /** Input: JSON và thuộc tính tùy chọn. Output: AI run trong DB test, không chạy provider. */
    private function createRun(array $input, array $attributes = []): AiImport
    {
        return AiImport::query()->create(array_replace([
            'created_by' => $this->actor->id, 'source_url' => '', 'input_json' => $input,
            'status' => 'queued', 'operation' => 'create',
        ], $attributes));
    }

    /** Input: callback dùng service thật. Output: SQL đã thực thi; luôn tắt query log sau khi đo. */
    private function recordedQueries(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $callback();

            return DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
    }

    /** Input: SQL/bindings thực tế và index cần dùng. Output: assertion không full scan hoặc sort tạm. */
    private function assertIndexed(?array $query, string $index): void
    {
        $this->assertNotNull($query);
        $plan = implode(' ', array_column(DB::select('EXPLAIN QUERY PLAN '.$query['query'], $query['bindings']), 'detail'));
        $this->assertStringContainsString($index, $plan);
        $this->assertStringNotContainsString('USE TEMP B-TREE', $plan);
        $this->assertStringNotContainsString('SCAN ai_imports', $plan);
    }
}
