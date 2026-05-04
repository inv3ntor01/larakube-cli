<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\CausesActivity;

class User extends Model
{
    use CausesActivity;

    protected $fillable = [
        'name',
        'email',
    ];

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
