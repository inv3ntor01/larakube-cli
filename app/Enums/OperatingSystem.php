<?php

namespace App\Enums;

use App\Contracts\HasCommandOptions;
use App\Contracts\HasLabel;
use App\Contracts\HasSelectOptions;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;

enum OperatingSystem: string implements HasCommandOptions, HasLabel, HasSelectOptions
{
    use ProvidesCommandOptions, ProvidesSelectOptions;

    public function getLabel(): ?string
    {
        return match ($this) {
            self::DEBIAN => 'Debian (Stable, widely compatible, larger image)',
            self::ALPINE => 'Alpine (Lightweight, smaller image, minimal footprint)',
        };
    }

    public function getSuffix(): ?string
    {
        return match ($this) {
            self::ALPINE => '-alpine',
            default => null,
        };
    }
    case DEBIAN = 'debian';
    case ALPINE = 'alpine';
}
