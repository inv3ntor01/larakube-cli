<?php

namespace App\Contracts;

interface HasAutoUsedComponents
{
    /**
     * @return self[]
     */
    public static function getAutoUsedComponents(): array;
}
