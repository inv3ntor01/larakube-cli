<?php

namespace App\Contracts;

interface HasHiddenComponents
{
    public function isHidden(): bool;
}
