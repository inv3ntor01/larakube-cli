<?php

namespace App\Enums;

use App\Actions\Blueprints\FilamentAction;
use App\Actions\Blueprints\StatamicAction;
use App\Actions\Contracts\BlueprintAction;

enum Blueprint: string
{
    case LARAVEL = 'Laravel (Standard)';
    case FILAMENT = 'FilamentPHP (Admin Panel)';
    case STATAMIC = 'Statamic (CMS)';

    public function label(): string
    {
        return $this->value;
    }

    /**
     * Get the description of the blueprint.
     */
    public function description(): string
    {
        return match ($this) {
            self::LARAVEL => 'A clean, modern Laravel application.',
            self::FILAMENT => 'The elegant TALL stack admin panel for Laravel.',
            self::STATAMIC => 'The radical, flat-file (or database) CMS for Laravel.',
        };
    }

    public function action(): ?BlueprintAction
    {
        return match ($this) {
            self::STATAMIC => new StatamicAction,
            self::FILAMENT => new FilamentAction,
            default => null,
        };
    }
}
