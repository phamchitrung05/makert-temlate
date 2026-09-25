<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorizables', function (Blueprint $table): void {
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('categorizable_type');
            $table->unsignedBigInteger('categorizable_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'categorizable_type', 'categorizable_id'], 'categorizables_unique');
            $table->index(['categorizable_type', 'categorizable_id'], 'categorizables_model_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorizables');
    }
};
