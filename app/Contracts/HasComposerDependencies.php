<?php

namespace App\Contracts;

use App\Data\ConfigData;

interface HasComposerDependencies
{
    /**
     * @return string[]
     */
    public function getComposerDependencies(?ConfigData $context = null): array;
}
