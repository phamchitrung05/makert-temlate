<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đánh index cho catalog, post list và metadata lọc AI run.
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): tạo cột JSON dẫn xuất tự đồng bộ và index cho các đường đọc.
 * - down(): gỡ index/cột dẫn xuất, giữ nguyên JSON và dữ liệu nghiệp vụ.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): schema MySQL/SQLite -> index đọc hoặc rollback.
 * =====================================================================
 */
return new class extends Migration
{
    /** Input: schema hiện tại. Output: index và cột virtual; không sửa input_json gốc. */
    public function up(): void
    {
        $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
        $target = $mysql
            ? "CASE WHEN JSON_TYPE(JSON_EXTRACT(input_json, '$.target_type')) = 'NULL' THEN NULL ELSE JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.target_type')) END"
            : "json_extract(input_json, '$.target_type')";
        $postId = $mysql
            ? "CASE WHEN JSON_TYPE(JSON_EXTRACT(input_json, '$.social_post_id')) = 'INTEGER' THEN JSON_EXTRACT(input_json, '$.social_post_id') END"
            : "CASE WHEN json_type(input_json, '$.social_post_id') = 'integer' THEN json_extract(input_json, '$.social_post_id') END";
        $generation = $mysql
            ? "CASE WHEN JSON_TYPE(JSON_EXTRACT(input_json, '$.generation_no')) = 'INTEGER' THEN JSON_EXTRACT(input_json, '$.generation_no') END"
            : "CASE WHEN json_type(input_json, '$.generation_no') = 'integer' THEN json_extract(input_json, '$.generation_no') END";

        Schema::table('ai_imports', function (Blueprint $table) use ($target, $postId, $generation, $mysql): void {
            // Target thiếu/null là post ở dữ liệu cũ; literal "null" vẫn là target không hợp lệ.
            $targetColumn = $table->string('input_target_type', 191)->virtualAs("COALESCE(($target), 'post')");
            if ($mysql) {
                $targetColumn->collation('utf8mb4_bin');
            }
            $table->unsignedBigInteger('input_social_post_id')->nullable()->virtualAs($postId);
            $table->unsignedInteger('input_generation_no')->nullable()->virtualAs($generation);
            $table->index(['created_by', 'input_target_type', 'created_at', 'id'], 'ai_imports_owner_target_created_index');
            $table->index(['input_social_post_id', 'input_generation_no', 'operation'], 'ai_imports_social_generation_index');
        });
        Schema::table('posts', fn (Blueprint $table) => $table->index(['deleted_at', 'created_at', 'id'], 'posts_active_created_index'));
        Schema::table('resources', fn (Blueprint $table) => $table->index(['type', 'status', 'visibility', 'published_at'], 'resources_public_catalog_index'));
    }

    /** Input: schema có index đọc. Output: gỡ đúng phần bổ sung, giữ mọi dữ liệu JSON gốc. */
    public function down(): void
    {
        Schema::table('resources', fn (Blueprint $table) => $table->dropIndex('resources_public_catalog_index'));
        Schema::table('posts', fn (Blueprint $table) => $table->dropIndex('posts_active_created_index'));
        Schema::table('ai_imports', function (Blueprint $table): void {
            $table->dropIndex('ai_imports_owner_target_created_index');
            $table->dropIndex('ai_imports_social_generation_index');
            $table->dropColumn(['input_target_type', 'input_social_post_id', 'input_generation_no']);
        });
    }
};
