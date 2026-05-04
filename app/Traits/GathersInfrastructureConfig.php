<?php

namespace App\Traits;

use App\Data\ConfigData;
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

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

trait GathersInfrastructureConfig
{
    use InteractsWithGlobalConfig;

    /**
     * Gather all configuration needed for infrastructure generation.
     */
    protected function gatherConfig(ConfigData $config): ConfigData
    {
        if (! $config->getBlueprint()) {
            $blueprint = select(
                label: 'Which application blueprint would you like to use?',
                options: Blueprint::getSelectOptions(),
                default: Blueprint::LARAVEL->value
            );

            $config->setBlueprint(Blueprint::from($blueprint));
        }

        if (! $config->getServerVariation()) {
            $variation = select(
                label: 'What server variation would you like to use?',
                options: ServerVariation::getSelectOptions(),
                default: ServerVariation::FPM_NGINX->value
            );

            $config->setServerVariation(ServerVariation::from($variation));

            if ($config->getServerVariation() === ServerVariation::FRANKENPHP) {
                $config->addFeature(LaravelFeature::OCTANE);
            }
        }

        if (! $config->getFrontend()) {
            $frontend = select(
                label: 'Which frontend stack are you using?',
                options: FrontendStack::getSelectOptions(),
                default: FrontendStack::LIVEWIRE->value
            );

            $config->setFrontend(FrontendStack::from($frontend));
        }

        if (! $config->getPhpVersion()) {
            $version = select(
                label: 'What PHP version would you like to use?',
                options: PhpVersion::getSelectOptions(),
                default: PhpVersion::PHP_8_5->value
            );

            $config->setPhpVersion(PhpVersion::from($version));
        }

        if (! $config->getOs()) {
            $os = select(
                label: 'What operating system would you like to use?',
                options: OperatingSystem::getSelectOptions(),
                default: OperatingSystem::ALPINE->value
            );

            $config->setOs(OperatingSystem::from($os));
        }

        if (! $config->hasEmail()) {
            $email = text(
                label: 'Set an email contact for SSL renewals:',
                placeholder: $this->getDefaultEmail(),
                default: $this->getEmail() ?? $this->getDefaultEmail(),
                validate: fn (string $value) => ! filter_var($value, FILTER_VALIDATE_EMAIL) ? 'Invalid email address.' : null
            );

            $this->setEmail($email);
            $config->setEmail($email);
        }

        if (! $config->hasAdditionalExtensions()) {
            info('Default extensions: ctype, curl, dom, fileinfo, filter, hash, mbstring, mysqli, opcache, openssl, pcntl, pcre, pdo_mysql, pdo_pgsql, redis, session, tokenizer, xml, zip');
            $extensions = text(label: 'Enter additional extensions (comma-separated):', placeholder: 'intl,gd');
            $config->setAdditionalExtensions(array_filter(explode(',', str_replace(' ', '', $extensions))));
        }

        $options = LaravelFeature::getSelectOptions();
        $features = multiselect(
            label: 'Select Laravel features:',
            options: $options,
            scroll: count($options),
            validate: function (array $values) {
                if (in_array(LaravelFeature::HORIZON->value, $values) && in_array(LaravelFeature::QUEUES->value, $values)) {
                    return 'You cannot select both Horizon and Queues. Please choose one.';
                }

                return null;
            }
        );

        $config->setFeatures(Arr::map($features, fn (string $feature) => LaravelFeature::from($feature)));

        if (in_array(LaravelFeature::SCOUT, $config->getFeatures()) && ! $config->getScoutDriver()) {
            $driver = select(
                label: 'Which search driver would you like to use for Scout?',
                options: ScoutDriver::getSelectOptions(),
                default: ScoutDriver::MEILISEARCH->value
            );

            $config->setScoutDriver(ScoutDriver::from($driver));
        }

        if (! $config->getFrontend()) {
            $frontend = select(
                label: 'Which frontend stack are you using?',
                options: array_merge(['none' => 'None'], FrontendStack::getSelectOptions()),
                default: 'none',
            );

            if ($frontend = FrontendStack::tryFrom($frontend)) {
                $config->setFrontend($frontend);
            }
        }

        if (! $config->hasPackageManager()) {
            $packageManager = select(
                label: 'Choose your JavaScript package manager:',
                options: PackageManager::getSelectOptions(),
                default: PackageManager::NPM->value
            );

            $config->setPackageManager(PackageManager::from($packageManager));
        }

        if (! $config->getObjectStorage()) {
            $storage = select(
                label: 'Which object storage would you like to use?',
                options: array_merge(['none' => 'None'], StorageDriver::getSelectOptions()),
                default: 'none'
            );

            if ($driver = StorageDriver::tryFrom($storage)) {
                $config->setObjectStorage($driver);
            }
        }

        if (! $config->hasDatabases()) {
            $defaultDatabases = [DatabaseDriver::MYSQL->value];
            if ($config->hasFeature(LaravelFeature::HORIZON)) {
                $defaultDatabases[] = DatabaseDriver::REDIS->value;
            }

            $databases = multiselect(
                label: 'What database engine(s) would you like to use?',
                options: DatabaseDriver::getSelectOptions(),
                default: array_unique($defaultDatabases),
                validate: function (array $values) {
                    if (empty($values)) {
                        return 'You must select at least one database engine.';
                    }

                    $persistentDatabases = DatabaseDriver::getPersistentDatabases(asValues: true);

                    if (array_any($values, fn ($value) => in_array($value, $persistentDatabases))) {
                        return null;
                    }

                    $dbs = Arr::join($persistentDatabases, ', ', ' and ');

                    return "You must select at least one persistent database like $dbs.";
                }
            );

            $config->setDatabases($databases);
        }

        if (! $config->hasGithubActions()) {
            $config->setGithubActions(confirm(label: 'Would you like to use GitHub Actions?'));
        }

        $config->resolveDependencies();

        return $config;
    }
}
