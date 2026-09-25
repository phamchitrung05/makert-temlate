<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->nullable()->unique()->after('name');
            $table->string('status')->default('active')->index()->after('password');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_username_unique');
            $table->dropIndex('users_status_index');
            $table->dropColumn(['username', 'status', 'last_login_at', 'deleted_at']);
        });
    }
};
