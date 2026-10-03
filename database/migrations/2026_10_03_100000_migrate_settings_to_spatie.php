<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuyển key/value cũ sang schema settings của Spatie.
 * CÁC HÀM/METHOD TRONG FILE: up(), down().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : bảng settings cũ, giữ type/updated_by trong legacy_settings.
 * - OUTPUT: bảng settings chuẩn; toàn bộ payload và timestamp được giữ.
 * - SIDE EFFECT: DDL và copy dữ liệu; rollback đồng bộ payload mới về bảng cũ.
 * =====================================================================
 */
return new class extends Migration
{
    /** Input: Schema cũ. Output: settings chuẩn và bản lưu legacy; không gọi AI. */
    public function up(): void
    {
        Schema::rename('settings', 'legacy_settings');
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group');
            $table->string('name');
            $table->boolean('locked')->default(false);
            $table->json('payload');
            $table->timestamps();
            $table->unique(['group', 'name']);
        });
        DB::table('legacy_settings')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                $value = $row->value === null ? null : json_decode($row->value, true, 512, JSON_THROW_ON_ERROR);
                $value = match ($row->type) {
                    'integer' => $value === null ? null : (int) $value,
                    'float' => (float) $value,
                    'boolean' => (bool) $value,
                    default => $value,
                };
                DB::table('settings')->insert([
                    'group' => $row->group, 'name' => $row->key,
                    'payload' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'locked' => false, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
                ]);
            }
        });
    }

    /** Input: Schema Spatie. Output: khôi phục schema cũ với giá trị đã sửa sau migration. */
    public function down(): void
    {
        DB::table('settings')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                $value = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
                DB::table('legacy_settings')->updateOrInsert(['group' => $row->group, 'key' => $row->name], [
                    'value' => $row->payload,
                    'type' => match (true) {
                        is_int($value), $value === null => 'integer',
                        is_float($value) => 'float',
                        is_bool($value) => 'boolean',
                        default => 'string',
                    },
                    'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
                ]);
            }
        });
        Schema::drop('settings');
        Schema::rename('legacy_settings', 'settings');
    }
};
