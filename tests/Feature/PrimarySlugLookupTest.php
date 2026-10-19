<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Resource;
use App\Services\SlugService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\UsesIsolatedDatabase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm lookup slug dùng chung và loại query lặp sau eager load.
 * CÁC HÀM/METHOD TRONG FILE:
 * - setUp()/tearDown(): cô lập schema.
 * - test_loaded_slugs_need_no_query_and_invalidate_after_sync(): kiểm cache và rename.
 * - test_loaded_relation_respects_locale_and_missing_primary(): kiểm locale/slug cũ.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): Category/Post/Resource -> slug đúng và số query.
 * =====================================================================
 */
final class PrimarySlugLookupTest extends TestCase
{
    use UsesIsolatedDatabase;

    /** Input: không có. Output: database cô lập. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedDatabase();
    }

    /** Input: database test. Output: giải phóng connection. */
    protected function tearDown(): void
    {
        DB::disableQueryLog();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** Input: ba model đã eager-load slug. Output: không query lặp và không trả slug cũ sau sync. */
    public function test_loaded_slugs_need_no_query_and_invalidate_after_sync(): void
    {
        $models = [Category::factory()->create(), Post::factory()->create(), Resource::factory()->create()];
        foreach ($models as $model) {
            $expected = $model->primarySlug()->id;
            $model->load('slugs');
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->assertSame($expected, $model->primarySlug()->id);
            $this->assertSame($expected, $model->primarySlug()->id);
            $this->assertCount(0, DB::getQueryLog());
            DB::disableQueryLog();

            app(SlugService::class)->syncForModel($model, 'New slug');
            $this->assertFalse($model->relationLoaded('slugs'));
            $this->assertSame('new-slug', $model->primarySlug()->slug);
        }
    }

    /** Input: slug lịch sử vi và primary en. Output: không chọn nhầm locale hoặc slug cũ. */
    public function test_loaded_relation_respects_locale_and_missing_primary(): void
    {
        config()->set('app.locale', 'vi');
        $category = Category::factory()->create();
        $category->slugs()->update(['is_primary' => false]);
        $category->slugs()->create(['slug' => 'english', 'locale' => 'en', 'is_primary' => true]);
        $category->load('slugs');
        $this->assertNull($category->primarySlug());
        config()->set('app.locale', 'en');
        $this->assertSame('english', $category->primarySlug()->slug);
    }
}
