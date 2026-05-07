<?php

namespace App\Contracts;

use App\Data\ConfigData;

interface HasDockerImage
{
    public function getDockerImage(?ConfigData $config = null): string;
}
