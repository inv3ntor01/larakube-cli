<?php

namespace App\Traits;

use App\Contracts\HasHiddenComponents;

trait ProvidesCommandOptions
{
    public static function getCommandOptionArrays(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            $options[] = [
                'name' => $case->value,
                'description' => $case->getLabel(),
            ];
        }

        return $options;
    }

    public static function getCommandOptions(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            $options[] = $case->value;
        }

        return $options;
    }
}
