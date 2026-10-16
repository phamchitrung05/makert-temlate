<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_post_versions', function (Blueprint $table): void {
            $table->dropUnique('social_post_versions_unique');
            $table->dropIndex('social_post_versions_variant_version_index');
            $table->unique(
                ['social_post_variant_id', 'version_no'],
                'social_post_versions_variant_version_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('social_post_versions', function (Blueprint $table): void {
            $table->dropUnique('social_post_versions_variant_version_unique');
            $table->unique(['social_post_id', 'version_no'], 'social_post_versions_unique');
            $table->index(
                ['social_post_variant_id', 'version_no'],
                'social_post_versions_variant_version_index',
            );
        });
    }
};
