<?php

namespace App\Commands;

use App\Enums\Blueprint;
use App\Enums\DatabaseEngine;
use App\Enums\LaravelFeature;
use App\Enums\OperatingSystem;
use App\Enums\PackageManager;
use App\Enums\PhpVersion;
use App\Enums\ServerVariation;
use App\Traits\CheckPrerequisites;
use App\Traits\GathersInfrastructureConfig;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithInternalDatabase;
use App\Traits\InteractsWithMcpConfig;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class NewCommand extends Command
{
    use CheckPrerequisites, GathersInfrastructureConfig, GeneratesProjectInfrastructure, InteractsWithDocker, InteractsWithInternalDatabase, InteractsWithMcpConfig, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'new {name? : The name of the app}
                            {--fast : Skip the LaraKube wizard and use ideal defaults}
                            {--frankenphp : Use FrankenPHP server (Recommended)}
                            {--nginx : Use FPM + Nginx server}
                            {--apache : Use FPM + Apache server}
                            {--filament : Use FilamentPHP blueprint}
                            {--statamic : Use Statamic blueprint}
                            {--mysql : Use MySQL database}
                            {--postgres : Use PostgreSQL database}
                            {--mariadb : Use MariaDB database}
                            {--mongodb : Use MongoDB database}
                            {--redis : Use Redis cache}
                            {--horizon : Install Laravel Horizon}
                            {--reverb : Install Laravel Reverb}
                            {--meilisearch : Install Laravel Scout with Meilisearch}
                            {--typesense : Install Laravel Scout with Typesense}
                            {--react : Use React frontend stack}
                            {--vue : Use Vue frontend stack}
                            {--livewire : Use Livewire frontend stack}
                            {--svelte : Use Svelte frontend stack}
                            {--monitoring : Install Prometheus and Grafana}
                            {--queue : Enable background queue workers}
                            {--schedule : Enable task scheduling}
                            {--mailpit : Enable local Mailpit SMTP}
                            {--minio : Enable MinIO object storage}
                            {--seaweedfs : Enable SeaweedFS object storage}
                            {--dry-run : Show what will be done without making any changes}';

    /**
     * The console command description.
     */
    protected $description = 'Create a new Laravel application with a custom Kubernetes architecture';

    /**
     * Configure the command to ignore validation errors so we can forward arbitrary flags.
     */
    protected function configure(): void
    {
        $this->ignoreValidationErrors();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        // 1. Nesting Protection
        if (file_exists(getcwd().'/.larakube.json')) {
            $this->line('');
            $this->warn(' ⚠ NESTING WARNING: You are already inside a LaraKube CLI project.');
            $this->line('   Running "new" here will create a nested project structure.');
            $this->line('');

            if (! $this->confirm('Are you sure you want to proceed with a nested project?', false)) {
                $this->laraKubeInfo('Action cancelled to prevent project nesting.');

                return 0;
            }

            $this->logActivity('Project nesting warning ignored', ['action' => 'new'], getcwd());
        }

        if (! $this->checkPrerequisites(true)) {
            return 1;
        }

        $inputName = $this->argument('name');

        $nameFromInput = $inputName ?? text(
            label: 'What is the name of your app?',
            placeholder: 'my-laravel-app',
            required: true
        );

        $name = Str::slug($nameFromInput);

        // Detect if any architectural flags were provided
        $hasArchFlags = collect($this->options())->only([
            'frankenphp', 'nginx', 'apache',
            'filament', 'statamic', 'mysql', 'postgres', 'mariadb', 'mongodb', 'redis',
            'horizon', 'reverb', 'meilisearch', 'typesense', 'queue', 'schedule', 'mailpit',
            'minio', 'seaweedfs', 'fast',
        ])->filter()->isNotEmpty();

        $config = $hasArchFlags ? $this->buildConfigFromFlags() : $this->gatherConfig();

        $projectPath = getcwd().'/'.$name;
        $osSuffix = $config['os'] === 'alpine' ? '-alpine' : '';
        $image = "serversideup/php:{$config['phpVersion']}-{$config['serverVariation']}{$osSuffix}";

        $this->laraKubeInfo("Scaffolding architectural masterpiece: {$name}...");

        $this->runLaravelNew($projectPath, $name, $inputName, $image, $config['packageManager']);

        if (! is_dir($projectPath)) {
            $this->laraKubeError('Failed to create Laravel application.');

            return 1;
        }

        // Create .env.production
        if (file_exists($projectPath.'/.env') && ! $this->option('dry-run')) {
            copy($projectPath.'/.env', $projectPath.'/.env.production');
        }

        // 1. Always show the preview first
        $this->orchestrateProjectScaffolding($projectPath, $name, $config, true, true, true);

        // 2. Stop if it's an explicit dry-run
        if ($this->option('dry-run')) {
            return 0;
        }

        // 3. Confirm before applying (skip if --fast or --no-interaction)
        if (! $this->option('fast') && ! $this->option('no-interaction')) {
            if (! $this->confirm('Would you like to apply these architectural changes?', true)) {
                $this->laraKubeInfo('Scaffolding cancelled. No files were modified.');

                return 0;
            }
        }

        $this->withSpin('Orchestrating infrastructure manifests...', function () use ($projectPath, $name, $config) {
            $this->orchestrateProjectScaffolding($projectPath, $name, $config);
            $this->scaffoldMcpConfigs($projectPath);

            $this->logActivity('New architectural masterpiece created', [
                'name' => $name,
                'blueprint' => $config['blueprint'],
                'server' => $config['serverVariation'],
            ], $projectPath);
        });

        $this->laraKubeInfo("Project {$name} created successfully!");

        $blueprint = Blueprint::from($config['blueprint']);
        if ($instructions = $blueprint->action()?->getPostInstallInstructions()) {
            $this->line('');
            warning('Blueprint Next Steps:');
            foreach ($instructions as $line) {
                $this->line("  {$line}");
            }
        }

        $this->line('');
        info("Next steps: cd {$name} && larakube up");

        $this->renderStarPrompt();

        return 0;
    }

    protected function buildConfigFromFlags(): array
    {
        $blueprint = Blueprint::LARAVEL->value;
        if ($this->option('filament')) {
            $blueprint = Blueprint::FILAMENT->value;
        }
        if ($this->option('statamic')) {
            $blueprint = Blueprint::STATAMIC->value;
        }

        $serverVariation = ServerVariation::FRANKENPHP->value;
        if ($this->option('nginx')) {
            $serverVariation = ServerVariation::FPM_NGINX->value;
        }
        if ($this->option('apache')) {
            $serverVariation = ServerVariation::FPM_APACHE->value;
        }

        $databases = [];
        if ($this->option('mysql')) {
            $databases[] = DatabaseEngine::MYSQL->value;
        }
        if ($this->option('postgres')) {
            $databases[] = DatabaseEngine::POSTGRESQL->value;
        }
        if ($this->option('mariadb')) {
            $databases[] = DatabaseEngine::MARIADB->value;
        }
        if ($this->option('mongodb')) {
            $databases[] = DatabaseEngine::MONGODB->value;
        }
        if ($this->option('redis')) {
            $databases[] = DatabaseEngine::REDIS->value;
        }

        // Default to MySQL if no DB provided in fast/arch mode
        if (empty($databases)) {
            $databases = [DatabaseEngine::MYSQL->value, DatabaseEngine::REDIS->value];
        }

        $features = [];
        if ($this->option('horizon')) {
            $features[] = LaravelFeature::HORIZON->value;
        }
        if ($this->option('reverb')) {
            $features[] = LaravelFeature::REVERB->value;
        }
        if ($this->option('monitoring')) {
            $features[] = LaravelFeature::MONITORING->value;
        }
        if ($this->option('queue')) {
            $features[] = LaravelFeature::QUEUES->value;
        }
        if ($this->option('schedule')) {
            $features[] = LaravelFeature::TASK_SCHEDULING->value;
        }
        if ($this->option('meilisearch') || $this->option('typesense')) {
            $features[] = LaravelFeature::SCOUT->value;
        }

        $storage = 'none';
        if ($this->option('minio')) {
            $storage = 'minio';
        }
        if ($this->option('seaweedfs')) {
            $storage = 'seaweedfs';
        }

        if ($serverVariation === ServerVariation::FRANKENPHP->value) {
            $features[] = LaravelFeature::OCTANE->value;
        }

        $frontend = 'none';
        if ($this->option('react')) {
            $frontend = 'react';
        } elseif ($this->option('vue')) {
            $frontend = 'vue';
        } elseif ($this->option('livewire')) {
            $frontend = 'livewire';
        } elseif ($this->option('svelte')) {
            $frontend = 'svelte';
        }

        return [
            'blueprint' => $blueprint,
            'frontend' => $frontend,
            'serverVariation' => $serverVariation,
            'phpVersion' => PhpVersion::PHP_8_5->value,
            'os' => OperatingSystem::ALPINE->value,
            'email' => $this->getEmail() ?? 'admin@larakube.dev.test',
            'additionalExtensions' => [],
            'features' => array_unique($features),
            'packageManager' => PackageManager::NPM->value,
            'objectStorage' => $storage,
            'databases' => array_unique($databases),
            'githubActions' => true,
        ];
    }

    protected function runLaravelNew($projectPath, $name, $inputName, $image, $packageManager): void
    {
        $uid = function_exists('posix_getuid') ? posix_getuid() : 1000;
        $gid = function_exists('posix_getgid') ? posix_getgid() : 1000;

        $this->laraKubeInfo("Pulling builder image: {$image}...");
        passthru("docker pull {$image} > /dev/null 2>&1");

        $pmFlag = "--{$packageManager}";

        // Filter out LaraKube flags AND the project name to forward only native Laravel flags
        $extraArgs = array_filter(array_slice($_SERVER['argv'], 2), function ($arg) use ($inputName) {
            // 1. Skip if it's the original project name argument
            if ($inputName && $arg === $inputName) {
                return false;
            }

            // 2. Skip LaraKube-specific flags
            $larakubeFlags = [
                'fast', 'frankenphp', 'nginx', 'apache', 'filament', 'statamic', 'mysql', 'postgres',
                'mariadb', 'mongodb', 'redis', 'horizon', 'reverb', 'meilisearch', 'typesense',
                'queue', 'schedule', 'mailpit', 'minio', 'seaweedfs', 'dry-run',
            ];

            if (str_starts_with($arg, '--')) {
                return ! in_array(ltrim($arg, '-'), $larakubeFlags);
            }

            // Keep any other positional arguments or unknown flags (to be safe)
            return true;
        });
        $extraFlags = implode(' ', $extraArgs);

        $pkgCommand = $this->getNodeInstallationCommand($image);
        $baseDir = dirname($projectPath);

        $cmd = 'docker run --rm -it -v '.$baseDir.":/var/www/html -e COMPOSER_CACHE_DIR=/dev/null -e COMPOSER_ALLOW_SUPERUSER=1 --user root $image ".
               "sh -c '$pkgCommand && composer global require laravel/installer && $(composer global config bin-dir --absolute)/laravel new $name $pmFlag $extraFlags && chown -R $uid:$gid $name'";

        passthru($cmd);
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
