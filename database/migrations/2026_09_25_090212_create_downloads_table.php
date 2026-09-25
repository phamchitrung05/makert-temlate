<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('downloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('resource_id')->constrained('resources')->restrictOnDelete();
            $table->foreignId('resource_version_id')->constrained('resource_versions')->restrictOnDelete();
            $table->foreignId('media_id')->constrained('media')->restrictOnDelete();
            $table->string('ip_hash', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('status', 32)->default('started');
            $table->timestamp('downloaded_at')->useCurrent();
            $table->timestamps();

            $table->index(['customer_id', 'downloaded_at']);
            $table->index(['resource_id', 'downloaded_at']);
            $table->index(['resource_version_id', 'downloaded_at']);
            $table->index(['ip_hash', 'downloaded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('downloads');
    }
};
