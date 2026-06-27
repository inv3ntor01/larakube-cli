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

    public function getNodeInstallCommand(): string
    {
        return match ($this) {
            self::ALPINE => 'apk add --no-cache nodejs npm',
            self::DEBIAN => 'apt-get update && apt-get install -y --no-install-recommends nodejs npm && rm -rf /var/lib/apt/lists/*',
        };
    }
    case DEBIAN = 'debian';
    case ALPINE = 'alpine';
}
