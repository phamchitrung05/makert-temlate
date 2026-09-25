<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_user_id');
            $table->string('provider_email')->nullable();
            $table->string('provider_name')->nullable();
            $table->text('provider_avatar_url')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->index(['customer_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_identities');
    }
};
