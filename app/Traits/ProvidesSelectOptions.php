<?php

namespace App\Traits;

use App\Contracts\HasHiddenComponents;
use App\Data\ConfigData;

trait ProvidesSelectOptions
{
    public static function getSelectOptions(?ConfigData $config = null): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }
}
