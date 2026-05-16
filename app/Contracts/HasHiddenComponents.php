<?php

namespace App\Contracts;

use App\Data\ConfigData;

interface HasHiddenComponents
{
    public function isHidden(?ConfigData $config = null): bool;
}
