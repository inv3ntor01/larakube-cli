<?php

namespace App\Commands;

use App\Contracts\HasLifecycleHooks;
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
use App\Traits\CheckPrerequisites;
use App\Traits\GathersInfrastructureConfig;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithDynamicOptions;
use App\Traits\InteractsWithInternalDatabase;
use App\Traits\InteractsWithMcpConfig;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\text;

class NewCommand extends Command
{
    use CheckPrerequisites, GathersInfrastructureConfig, GeneratesProjectInfrastructure, InteractsWithDocker, InteractsWithDynamicOptions, InteractsWithInternalDatabase, InteractsWithMcpConfig, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'new {name? : The name of the app}
                            {--fast : Skip the LaraKube wizard and use ideal defaults}';

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
        $this->addArchitecturalOptions();
    }

    /**
     * Execute the console command.
     *
     * @throws RandomException
     */
    public function handle(): int
    {
        $this->renderHeader();

        $projectPath = getcwd();

        // 1. Nesting Protection
        if (file_exists("$projectPath/.larakube.json")) {
            $this->newLine();
            $this->warn(' ⚠ NESTING WARNING: You are already inside a LaraKube CLI project.');
            $this->line('   Running "new" here will create a nested project structure.');
            $this->newLine();

            if (! confirm('Are you sure you want to proceed with a nested project?')) {
                $this->laraKubeInfo('Action cancelled to prevent project nesting.');

                return 0;
            }

            $this->logActivity('Project nesting warning ignored', ['action' => 'new'], $projectPath);
        }

        if (! $this->checkPrerequisites(true)) {
            return 1;
        }

        $inputName = $this->argument('name') ?? text(
            label: 'What is the name of your app?',
            placeholder: 'my-laravel-app',
            required: true
        );

        $config = $this->gatherConfig($this->buildConfigFromFlags());

        $config->setName(Str::slug($inputName));

        $appName = $config->getName();
        $projectPath .= "/$appName";

        $config->setPath($projectPath, true);
        $config->setEnvironments(['local', 'production']);

        $this->laraKubeInfo("Scaffolding architectural masterpiece: $appName...");

        // Run "laravel new" command
        $this->runLaravelNew($inputName, $config);

        if (! is_dir($projectPath)) {
            $this->laraKubeError('Failed to create Laravel application.');

            return 1;
        }

        // Create .env.production
        if (file_exists("$projectPath/.env")) {
            copy("$projectPath/.env", "$projectPath/.env.production");
        }

        $this->withSpin('Orchestrating infrastructure manifests...', function () use ($config, $projectPath, $appName) {
            $this->orchestrateProjectScaffolding($config);
            $this->scaffoldMcpConfigs($projectPath);

            $this->logActivity('New architectural masterpiece created', [
                'name' => $appName,
                'blueprint' => $config->getBlueprint()?->value,
                'server' => $config->getServerVariation()?->value,
            ], $projectPath);
        });

        $this->laraKubeInfo("Project $appName created successfully!");

        $this->newLine();
        info('First, start your application:');
        $this->line("  cd {$appName} && larakube up");

        // Collect instructions from all components
        $allInstructions = [];
        foreach ($config->getComponents() as $component) {
            if ($component instanceof HasLifecycleHooks) {
                $allInstructions = array_merge($allInstructions, $component->getPostInstallInstructions());
            }
        }

        if (! empty($allInstructions)) {
            $this->newLine();
            $this->warn('Then, perform these one-time architectural steps:');
            foreach ($allInstructions as $line) {
                $this->line("  $line");
            }
        }

        $this->renderStarPrompt();

        return 0;
    }

    protected function runLaravelNew($inputName, ConfigData $config): void
    {
        $appName = $config->getName();
        $projectPath = $config->getPath();

        $uid = function_exists('posix_getuid') ? posix_getuid() : 1000;
        $gid = function_exists('posix_getgid') ? posix_getgid() : 1000;
        $image = $config->getPhpImage(true);

        $this->laraKubeInfo("Pulling builder image: $image...");
        passthru("docker pull $image > /dev/null 2>&1");

        // Skip LaraKube-specific flags (Dynamic from Enums)
        $larakubeFlags = array_merge(
            ['fast', 'force', 'no-interaction'],
            Blueprint::getCommandOptions(),
            ServerVariation::getCommandOptions(),
            OperatingSystem::getCommandOptions(),
            PackageManager::getCommandOptions(),
            FrontendStack::getCommandOptions(),
            PhpVersion::getCommandOptions(),
            DatabaseDriver::getCommandOptions(),
            LaravelFeature::getCommandOptions(),
            StorageDriver::getCommandOptions(),
            ScoutDriver::getCommandOptions(),
        );

        // Filter out LaraKube flags AND the project name to forward only native Laravel flags
        $extraArgs = array_filter(array_slice($_SERVER['argv'], 2), function ($arg) use ($inputName, $larakubeFlags) {
            // Skip if it's the original project name argument
            if ($inputName && $arg === $inputName) {
                return false;
            }

            if (str_starts_with($arg, '--')) {
                return ! in_array(ltrim($arg, '-'), $larakubeFlags);
            }

            // Keep any other positional arguments or unknown flags (to be safe)
            return true;
        });

        // Add Package Manager & Frontend Stack
        if ($pmFlag = $config->getPackageManager()?->getOptionFlag()) {
            $extraArgs[] = $pmFlag;
        }

        if ($frontendFlag = $config->getFrontend()?->getOptionFlag()) {
            $extraArgs[] = $frontendFlag;
        }

        $extraFlags = implode(' ', $extraArgs);

        $pkgCommand = $this->getNodeInstallationCommand($image);
        $baseDir = dirname($projectPath);

        $cmd = 'docker run --rm -it -v '.$baseDir.":/var/www/html -e COMPOSER_CACHE_DIR=/dev/null -e COMPOSER_ALLOW_SUPERUSER=1 --user root $image ".
               "sh -c '$pkgCommand && composer global require laravel/installer && $(composer global config bin-dir --absolute)/laravel new $appName $extraFlags && chown -R $uid:$gid {$appName}'";

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
