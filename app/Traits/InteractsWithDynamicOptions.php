<?php

namespace App\Traits;

use App\Contracts\HasHiddenComponents;
use App\Data\ConfigData;
use App\Enums\AiProvider;
use App\Enums\Blueprint;
use App\Enums\DatabaseDriver;
use App\Enums\FrontendStack;
use App\Enums\LaravelFeature;
use App\Enums\OperatingSystem;
use App\Enums\PackageManager;
use App\Enums\PhpVersion;
use App\Enums\ScoutDriver;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;
use Illuminate\Support\Arr;
use Symfony\Component\Console\Input\InputOption;

trait InteractsWithDynamicOptions
{
    /**
     * Define the dynamic architectural options for the command.
     */
    protected function addArchitecturalOptions(): void
    {
        $options = [
            ...Blueprint::getCommandOptionArrays(),
            ...ServerVariation::getCommandOptionArrays(),
            ...OperatingSystem::getCommandOptionArrays(),
            ...PhpVersion::getCommandOptionArrays(),
            ...DatabaseDriver::getCommandOptionArrays(),
            ...LaravelFeature::getCommandOptionArrays(),
            ...FrontendStack::getCommandOptionArrays(),
            ...PackageManager::getCommandOptionArrays(),
            ...StorageDriver::getCommandOptionArrays(),
            ...ScoutDriver::getCommandOptionArrays(),
        ];

        foreach ($options as $option) {
            // Options are already filtered by traits, but let's double check by name
            $this->addOption(
                name: Arr::get($option, 'name'),
                mode: InputOption::VALUE_NONE,
                description: Arr::get($option, 'description'),
            );
        }
    }

    /**
     * Define the dynamic AI provider options for the command.
     */
    protected function addAiProviderOptions(int $mode = InputOption::VALUE_NONE): void
    {
        foreach (AiProvider::getCommandOptionArrays() as $option) {
            $this->addOption(
                name: Arr::get($option, 'name'),
                mode: $mode,
                description: Arr::get($option, 'description'),
            );
        }
    }

    /**
     * Build the configuration array from CLI flags.
     */
    protected function buildConfigFromFlags(): ConfigData
    {
        $config = new ConfigData;

        // Blueprints
        foreach (Blueprint::cases() as $case) {
            if ($this->option($case->value)) {
                $config->setBlueprint($case);
                break;
            }
        }

        // Laravel Features
        $features = [];

        foreach (LaravelFeature::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $features[] = $case;
            }
        }

        $config->setFeatures($features);

        if ($config->hasFeature(LaravelFeature::HORIZON)) {
            $config->addDatabase(DatabaseDriver::REDIS);
        }

        // Server Variations
        foreach (ServerVariation::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->setServerVariation($case);

                if ($case === ServerVariation::FRANKENPHP) {
                    $config->addFeature(LaravelFeature::OCTANE);
                }
                break;
            }
        }

        // Operating Systems
        foreach (OperatingSystem::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->setOs($case);
                break;
            }
        }

        // PHP Versions
        foreach (PhpVersion::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->setPhpVersion($case);
                break;
            }
        }

        // Database Drivers
        foreach (DatabaseDriver::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->addDatabase($case);
            }
        }

        // Storage Drivers
        foreach (StorageDriver::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->setObjectStorage($case);
                break;
            }
        }

        // Scout Drivers
        foreach (ScoutDriver::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->setScoutDriver($case);
                break;
            }
        }

        // Frontend Stacks
        foreach (FrontendStack::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->setFrontend($case);
                break;
            }
        }

        // Package Managers
        foreach (PackageManager::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->setPackageManager($case);
                break;
            }
        }

        // Default to fast-mode compatible values if not provided
        if ($this->option('fast')) {
            if (! $config->hasBlueprint()) {
                $config->setBlueprint(Blueprint::LARAVEL);
            }

            if (! $config->hasServerVariation()) {
                $config->setServerVariation(ServerVariation::FRANKENPHP);
            }

            if (! $config->hasPhpVersion()) {
                $config->setPhpVersion(PhpVersion::PHP_8_5);
            }

            if (! $config->hasOs()) {
                $config->setOs(OperatingSystem::ALPINE);
            }

            if (! $config->hasPackageManager()) {
                $config->setPackageManager(PackageManager::NPM);
            }

            if (! $config->hasPersistentDatabase()) {
                $config->addDatabase(DatabaseDriver::MYSQL);
            }

            if (! $config->hasDatabase(DatabaseDriver::REDIS)) {
                $config->addDatabase(DatabaseDriver::REDIS);
            }
        }

        $config->resolveDependencies();

        return $config;
    }
}
