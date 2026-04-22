<?php

namespace App\Models;

use Spatie\Activitylog\Models\Activity;

class LaraKubeActivity extends Activity
{
    protected $connection = 'sqlite';
}
