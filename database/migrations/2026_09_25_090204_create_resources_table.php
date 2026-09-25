<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 64);
            $table->string('title');
            $table->string('code')->nullable()->unique();
            $table->string('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('visibility', 32)->default('public');
            $table->boolean('is_featured')->default(false);
            $table->string('demo_url')->nullable();
            $table->string('documentation_url')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->string('canonical_url')->nullable();
            $table->unsignedBigInteger('view_count')->default(0);
            $table->unsignedBigInteger('download_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status', 'published_at']);
            $table->index(['visibility', 'status', 'published_at']);
            $table->index(['author_id', 'status']);
            $table->index(['is_featured', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
