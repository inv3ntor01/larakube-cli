<?php

namespace App\Contracts;

use App\Data\ConfigData;

interface HasJsDependencies
{
    /**
     * @return string[]
     */
    public function getJsDependencies(?ConfigData $context = null): array;
}
