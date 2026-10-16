<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_post_versions', function (Blueprint $table): void {
            $table->foreignId('social_post_variant_id')
                ->nullable()
                ->after('social_post_id')
                ->constrained('social_post_variants')
                ->nullOnDelete();
            $table->json('content_json')->nullable()->after('caption');
            $table->index(
                ['social_post_variant_id', 'version_no'],
                'social_post_versions_variant_version_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('social_post_versions', function (Blueprint $table): void {
            $table->dropIndex('social_post_versions_variant_version_index');
            $table->dropForeign(['social_post_variant_id']);
            $table->dropColumn(['social_post_variant_id', 'content_json']);
        });
    }
};
