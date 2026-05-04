<?php

namespace App\Contracts;

interface HasCommandOptions
{
    public static function getCommandOptionArrays(): array;

    public static function getCommandOptions(): array;
}
