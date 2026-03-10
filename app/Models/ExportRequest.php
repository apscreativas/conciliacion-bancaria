<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportRequest extends Model
{
    use HasFactory;
    use \App\Models\Traits\TeamOwned;

    protected $fillable = [
        'team_id',
        'user_id',
        'type',
        'status',
        'file_path',
        'file_name',
        'filters',
        'error_message',
    ];

    protected $casts = [
        'filters' => 'array',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
