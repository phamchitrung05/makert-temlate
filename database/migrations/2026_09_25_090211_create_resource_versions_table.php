<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->string('version', 64);
            $table->longText('changelog')->nullable();
            $table->json('requirements')->nullable();
            $table->string('status', 32)->default('draft');
            $table->boolean('is_default')->default(false);
            $table->timestamp('released_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['resource_id', 'version']);
            $table->index(['resource_id', 'status', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_versions');
    }
};
