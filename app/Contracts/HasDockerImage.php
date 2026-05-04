<?php

namespace App\Contracts;

interface HasDockerImage
{
    public function getDockerImage(): string;
}
