<?php

namespace App\Models;

use App\Enums\Blueprint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\HasActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property Blueprint|null $blueprint
 */
class Project extends Model
{
    use HasActivity;

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
        'blueprint' => Blueprint::class,
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
