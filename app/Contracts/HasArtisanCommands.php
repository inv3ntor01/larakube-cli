<?php

namespace App\Contracts;

use App\Data\ConfigData;

interface HasArtisanCommands
{
    /**
     * @return string[]
     */
    public function getArtisanCommands(?ConfigData $context = null): array;
}
