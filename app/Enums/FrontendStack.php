<?php

namespace App\Enums;

use App\Contracts\HasCommandOptions;
use App\Contracts\HasLabel;
use App\Contracts\HasSelectOptions;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;

enum FrontendStack: string implements HasCommandOptions, HasLabel, HasSelectOptions
{
    use ProvidesCommandOptions, ProvidesSelectOptions;

    case REACT = 'react';
    case VUE = 'vue';
    case SVELTE = 'svelte';
    case LIVEWIRE = 'livewire';

    public function getLabel(): string
    {
        return match ($this) {
            self::REACT => 'React',
            self::VUE => 'Vue',
            self::SVELTE => 'Svelte',
            self::LIVEWIRE => 'Livewire',
        };
    }

    public static function getCommandOptionArrays(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[] = [
                'name' => $case->value,
                'description' => "Use {$case->getLabel()} frontend",
            ];
        }

        return $options;
    }

    public function echoPackage(): ?string
    {
        return match ($this) {
            self::REACT => '@laravel/echo-react',
            self::VUE => '@laravel/echo-vue',
            default => null,
        };
    }

    public function getOptionFlag(): string
    {
        return "--$this->value";
    }

    public function requiresNodePod(): bool
    {
        return match ($this) {
            self::LIVEWIRE => false,
            default => true,
        };
    }
}
