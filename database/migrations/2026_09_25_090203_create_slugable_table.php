<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slugable', function (Blueprint $table): void {
            $table->id();
            $table->string('sluggable_type');
            $table->unsignedBigInteger('sluggable_id');
            $table->string('slug', 250);
            $table->string('locale', 16)->default(config('app.locale', 'en'));
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->unique(['sluggable_type', 'slug', 'locale'], 'slugable_type_slug_locale_unique');
            $table->index(['sluggable_type', 'sluggable_id', 'locale'], 'slugable_model_locale_index');
            $table->index(['slug', 'locale'], 'slugable_slug_locale_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slugable');
    }
};
