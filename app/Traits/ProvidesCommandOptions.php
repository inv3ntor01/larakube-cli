<?php

namespace App\Traits;

use App\Contracts\HasHiddenComponents;
use App\Data\ConfigData;

trait ProvidesCommandOptions
{
    public static function getCommandOptionArrays(?ConfigData $config = null): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden($config)) {

                continue;
            }

            $options[] = [
                'name' => $case->value,
                'description' => $case->getLabel(),
            ];
        }

        return $options;
    }

    public static function getCommandOptions(?ConfigData $config = null): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden($config)) {

                continue;
            }

            $options[] = $case->value;
        }

        return $options;
    }
}
