<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_technology', function (Blueprint $table): void {
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->foreignId('technology_id')->constrained('technologies')->cascadeOnDelete();
            $table->string('version_constraint')->nullable();
            $table->timestamps();

            $table->unique(['resource_id', 'technology_id'], 'resource_technology_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_technology');
    }
};
