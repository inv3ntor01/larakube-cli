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
    use CheckPrerequisites, GathersInfrastructureConfig, GeneratesProjectInfrastructure, InteractsWithDocker, InteractsWithMcpConfig, InteractsWithProjectConfig, LaraKubeOutput;

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
                            {--sqlite : Use SQLite database}
                            {--redis : Use Redis cache}
                            {--horizon : Install Laravel Horizon}
                            {--reverb : Install Laravel Reverb}
                            {--meilisearch : Install Laravel Scout with Meilisearch}
                            {--typesense : Install Laravel Scout with Typesense}
                            {--queue : Enable background queue workers}
                            {--schedule : Enable task scheduling}
                            {--mailpit : Enable local Mailpit SMTP}
                            {--minio : Enable MinIO object storage}
                            {--seaweedfs : Enable SeaweedFS object storage}';

    /**
     * The console command description.
     *
     * @var string
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
            'filament', 'statamic', 'mysql', 'postgres', 'mariadb', 'sqlite', 'redis',
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
        if (file_exists($projectPath.'/.env')) {
            copy($projectPath.'/.env', $projectPath.'/.env.production');
        }

        $this->withSpin('Orchestrating infrastructure manifests...', function () use ($projectPath, $name, $config) {
            $this->orchestrateProjectScaffolding($projectPath, $name, $config);
            $this->scaffoldMcpConfigs($projectPath);
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
        if ($this->option('sqlite')) {
            $databases[] = DatabaseEngine::SQLITE->value;
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
        if ($this->option('queue')) {
            $features[] = LaravelFeature::QUEUE->value;
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

        return [
            'blueprint' => $blueprint,
            'serverVariation' => $serverVariation,
            'phpVersion' => PhpVersion::PHP_8_5->value,
            'os' => OperatingSystem::ALPINE->value,
            'email' => $this->getEmail() ?? 'admin@larakube.local',
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
                'mariadb', 'sqlite', 'redis', 'horizon', 'reverb', 'meilisearch', 'typesense',
                'queue', 'schedule', 'mailpit', 'minio', 'seaweedfs',
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
