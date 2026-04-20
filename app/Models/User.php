<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    protected $fillable = [
        'name',
        'email',
    ];

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
