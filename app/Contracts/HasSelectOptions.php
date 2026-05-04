<?php

namespace App\Contracts;

use App\Data\ConfigData;

interface HasSelectOptions
{
    public static function getSelectOptions(?ConfigData $config = null): array;
}
