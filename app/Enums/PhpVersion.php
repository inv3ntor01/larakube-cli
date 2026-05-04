<?php

namespace App\Enums;

use App\Contracts\HasCommandOptions;
use App\Contracts\HasSelectOptions;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;

enum PhpVersion: string implements HasCommandOptions, HasSelectOptions
{
    use ProvidesCommandOptions, ProvidesSelectOptions;

    case PHP_8_5 = '8.5';
    case PHP_8_4 = '8.4';
    case PHP_8_3 = '8.3';
    case PHP_8_2 = '8.2';

    public function getLabel(): string
    {
        return match ($this) {
            self::PHP_8_5 => 'PHP 8.5 (Latest)',
            self::PHP_8_4 => 'PHP 8.4',
            self::PHP_8_3 => 'PHP 8.3',
            self::PHP_8_2 => 'PHP 8.2',
        };
    }
}
