<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Project extends Model
{
    use LogsActivity;

    protected $fillable = [
        'uuid',
        'user_id',
        'name',
        'path',
        'blueprint',
        'config',
    ];

    protected $casts = [
        'config' => 'array',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('projects')
            ->logOnly(['name', 'path', 'blueprint', 'config'])
            ->logOnlyDirty();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
