<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AiImageProfile extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['reference_path', 'connection_snapshot_json', 'analysis_instructions', 'reference_hash'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'connection_snapshot_json' => 'array'];
    }
}
