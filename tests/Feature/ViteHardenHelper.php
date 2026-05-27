<?php

namespace Tests\Feature;

use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\LaraKubeOutput;

class ViteHardenHelper
{
    use GeneratesProjectInfrastructure, LaraKubeOutput;

    public function laraKubeInfo(string $message): void {}
}
