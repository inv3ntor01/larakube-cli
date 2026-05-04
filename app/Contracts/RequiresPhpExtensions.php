<?php

namespace App\Contracts;

interface RequiresPhpExtensions
{
    public function getPhpExtensions(): array;
}
