<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_image_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('status', 20)->default('queued');
            $table->boolean('is_enabled')->default(false);
            $table->string('reference_path');
            $table->string('reference_mime', 40);
            $table->string('reference_hash', 64);
            $table->json('connection_snapshot_json');
            $table->text('analysis_instructions');
            $table->text('summary')->nullable();
            $table->text('prompt')->nullable();
            $table->text('negative_prompt')->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->timestamps();
            $table->index(['created_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_image_profiles');
    }
};
