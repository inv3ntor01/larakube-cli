<?php

namespace App\Traits;

use App\Enums\Blueprint;
use App\Enums\DatabaseEngine;
use App\Enums\LaravelFeature;
use App\Enums\ObjectStorage;
use App\Enums\OperatingSystem;
use App\Enums\PackageManager;
use App\Enums\PhpVersion;
use App\Enums\ScoutDriver;
use App\Enums\ServerVariation;

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
    protected function gatherConfig(): array
    {
        $config = [];

        $config['blueprint'] = select(
            label: 'Which application blueprint would you like to use?',
            options: collect(Blueprint::cases())->mapWithKeys(fn (Blueprint $case) => [$case->value => $case->label()])->all(),
            default: Blueprint::LARAVEL->value
        );

        $blueprint = Blueprint::from($config['blueprint']);
        if ($blueprintAction = $blueprint->action()) {
            $config = array_merge($config, $blueprintAction->gatherConfig());
        }

        $config['serverVariation'] = select(
            label: 'What server variation would you like to use?',
            options: collect(ServerVariation::cases())->mapWithKeys(fn (ServerVariation $case) => [$case->value => $case->label()])->all(),
            default: ServerVariation::FPM_NGINX->value
        );

        $config['phpVersion'] = select(
            label: 'What PHP version would you like to use?',
            options: collect(PhpVersion::cases())->mapWithKeys(fn (PhpVersion $case) => [$case->value => $case->label()])->all(),
            default: PhpVersion::PHP_8_5->value
        );

        $config['os'] = select(
            label: 'What operating system would you like to use?',
            options: collect(OperatingSystem::cases())->mapWithKeys(fn (OperatingSystem $case) => [$case->value => $case->label()])->all(),
            default: OperatingSystem::ALPINE->value
        );

        $config['email'] = text(
            label: 'Set an email contact for SSL renewals:',
            default: $this->getEmail() ?? '',
            placeholder: 'admin@example.com',
            validate: fn (string $value) => ! filter_var($value, FILTER_VALIDATE_EMAIL) ? 'Invalid email address.' : null
        );

        $this->setEmail($config['email']);

        info('Default extensions: ctype, curl, dom, fileinfo, filter, hash, mbstring, mysqli, opcache, openssl, pcntl, pcre, pdo_mysql, pdo_pgsql, redis, session, tokenizer, xml, zip');
        $additionalExtensionsInput = text(label: 'Enter additional extensions (comma-separated):', placeholder: 'gd,imagick');
        $config['additionalExtensions'] = array_filter(explode(',', str_replace(' ', '', $additionalExtensionsInput ?? '')));

        $featureOptions = collect(LaravelFeature::cases())
            ->filter(fn ($c) => $c !== LaravelFeature::OCTANE || $config['serverVariation'] === ServerVariation::FRANKENPHP->value)
            ->mapWithKeys(fn ($c) => [$c->value => $c->value])->all();

        $config['features'] = multiselect(
            label: 'Select Laravel features:',
            options: $featureOptions,
            validate: function (array $values) {
                if (in_array(LaravelFeature::HORIZON->value, $values) && in_array(LaravelFeature::QUEUES->value, $values)) {
                    return 'You cannot select both Horizon and Queues. Please choose one.';
                }

                return null;
            }
        );

        if (in_array(LaravelFeature::SCOUT->value, $config['features'])) {
            $config['scoutDriver'] = select(
                label: 'Which search driver would you like to use for Scout?',
                options: collect(ScoutDriver::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all(),
                default: ScoutDriver::MEILISEARCH->value
            );
        }

        $config['packageManager'] = select(
            label: 'Choose your JavaScript package manager:',
            options: collect(PackageManager::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value])->all(),
            default: PackageManager::NPM->value
        );

        $config['objectStorage'] = select(
            label: 'Would you like to include an S3-compatible Object Storage?',
            options: array_merge(['none' => 'None'], collect(ObjectStorage::cases())->mapWithKeys(fn ($case) => [$case->name => $case->label()])->all()),
            default: 'none'
        );

        $dbOptions = collect(DatabaseEngine::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value])->all();
        $dbDefault = [DatabaseEngine::MYSQL->value];
        if (in_array(LaravelFeature::HORIZON->value, $config['features'])) {
            $dbDefault[] = DatabaseEngine::REDIS->value;
        }

        $config['databases'] = multiselect(
            label: 'What database engine(s) would you like to use?',
            options: $dbOptions,
            default: array_unique($dbDefault),
            validate: function (array $values) {
                if (empty($values)) {
                    return 'You must select at least one database engine.';
                }
                $persistentDbs = array_filter($values, fn ($v) => $v !== DatabaseEngine::REDIS->value);
                if (empty($persistentDbs)) {
                    return 'You must select at least one persistent database (MySQL, MariaDB, PostgreSQL, or MongoDB).';
                }

                return null;
            }
        );

        $config['githubActions'] = confirm(label: 'Would you like to use GitHub Actions?', default: true);

        return $config;
    }
}
