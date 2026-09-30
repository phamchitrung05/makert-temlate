<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Xóa cột SEO legacy sau khi đã backfill metadata dùng chung.
 * =====================================================================
 *
 * Metadata SEO đã được chuyển sang seo_metadata ở migration trước. Cột
 * `excerpt` của Post vẫn thuộc nội dung nên được giữ lại.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): xóa các cột SEO legacy của Post/Resource
 * - down(): tạo lại cột legacy để rollback schema
 * - assertBackfill(): đối chiếu dữ liệu trước khi xóa cột
 * - restoreData(): phục hồi field legacy từ metadata khi rollback
 * - dropIfPresent(): chỉ xóa cột đã tồn tại
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : bảng posts/resources đã có seo_metadata.
 * - OUTPUT: schema không còn lưu trùng metadata SEO theo model.
 * =====================================================================
 */
return new class extends Migration
{
    /** Input: schema đã có seo_metadata. Output: cột SEO legacy được xóa an toàn. */
    public function up(): void
    {
        $this->assertBackfill('posts', 'post', [
            'focus_keyword', 'seo_title', 'seo_description', 'canonical_url',
            'robots_index', 'robots_follow', 'og_title', 'og_description',
        ]);
        $this->assertBackfill('resources', 'resource', ['seo_title', 'seo_description', 'canonical_url']);
        $this->dropIfPresent('posts', [
            'focus_keyword', 'seo_title', 'seo_description', 'canonical_url',
            'robots_index', 'robots_follow', 'og_title', 'og_description',
        ]);
        $this->dropIfPresent('resources', ['seo_title', 'seo_description', 'canonical_url']);
    }

    /** Input: rollback migration. Output: cột legacy được tạo lại với default hợp lệ. */
    public function down(): void
    {
        if (Schema::hasTable('posts')) {
            Schema::table('posts', function (Blueprint $table): void {
                if (! Schema::hasColumn('posts', 'focus_keyword')) {
                    $table->string('focus_keyword')->nullable();
                }
                if (! Schema::hasColumn('posts', 'seo_title')) {
                    $table->string('seo_title')->nullable();
                }
                if (! Schema::hasColumn('posts', 'seo_description')) {
                    $table->text('seo_description')->nullable();
                }
                if (! Schema::hasColumn('posts', 'canonical_url')) {
                    $table->string('canonical_url', 2048)->nullable();
                }
                if (! Schema::hasColumn('posts', 'robots_index')) {
                    $table->boolean('robots_index')->default(true);
                }
                if (! Schema::hasColumn('posts', 'robots_follow')) {
                    $table->boolean('robots_follow')->default(true);
                }
                if (! Schema::hasColumn('posts', 'og_title')) {
                    $table->string('og_title')->nullable();
                }
                if (! Schema::hasColumn('posts', 'og_description')) {
                    $table->text('og_description')->nullable();
                }
            });
        }

        if (Schema::hasTable('resources')) {
            Schema::table('resources', function (Blueprint $table): void {
                if (! Schema::hasColumn('resources', 'seo_title')) {
                    $table->string('seo_title')->nullable();
                }
                if (! Schema::hasColumn('resources', 'seo_description')) {
                    $table->text('seo_description')->nullable();
                }
                if (! Schema::hasColumn('resources', 'canonical_url')) {
                    $table->string('canonical_url', 2048)->nullable();
                }
            });
        }

        $this->restoreData('posts', 'post', [
            'focus_keyword', 'seo_title', 'seo_description', 'canonical_url',
            'robots_index', 'robots_follow', 'og_title', 'og_description',
        ]);
        $this->restoreData('resources', 'resource', ['seo_title', 'seo_description', 'canonical_url']);
    }

    /** Input: bảng/model/field legacy. Output: dừng migration nếu backfill chưa khớp, không xóa dữ liệu. */
    private function assertBackfill(string $table, string $type, array $fields): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $fields[0])) {
            return;
        }
        DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($type, $fields): void {
            $metadata = DB::table('seo_metadata')->where('seoable_type', $type)
                ->whereIn('seoable_id', $rows->pluck('id'))->get()->keyBy('seoable_id');
            foreach ($rows as $row) {
                foreach ($fields as $field) {
                    $default = str_starts_with($field, 'robots_') ? true : null;
                    $actual = $metadata->get($row->id)?->$field ?? $default;
                    $matches = str_starts_with($field, 'robots_')
                        ? (bool) $row->$field === (bool) $actual
                        : (string) ($row->$field ?? '') === (string) ($actual ?? '');
                    if (! $matches) {
                        throw new RuntimeException("Backfill SEO chưa khớp: {$type} #{$row->id}, {$field}. Không xóa cột legacy.");
                    }
                }
            }
        });
    }

    /** Input: bảng/model/field legacy đã tạo lại. Output: sao chép metadata mới nhất, gồm bản ghi soft-deleted. */
    private function restoreData(string $table, string $type, array $fields): void
    {
        if (! Schema::hasTable('seo_metadata') || ! Schema::hasTable($table)) {
            return;
        }
        DB::table('seo_metadata')->where('seoable_type', $type)->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $fields): void {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->seoable_id)
                        ->update(array_intersect_key((array) $row, array_flip($fields)));
                }
            });
    }

    /** Input: bảng và danh sách cột. Output: bỏ cột tồn tại, không tác động bảng khác. */
    private function dropIfPresent(string $tableName, array $columns): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $existing = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($tableName, $column)));
        if ($existing !== []) {
            Schema::table($tableName, fn (Blueprint $table): mixed => $table->dropColumn($existing));
        }
    }
};
