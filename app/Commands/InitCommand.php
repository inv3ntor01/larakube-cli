<?php

namespace App\Commands;

use App\Traits\CheckPrerequisites;
use App\Traits\GathersInfrastructureConfig;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithDynamicOptions;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;

class InitCommand extends Command
{
    use CheckPrerequisites, GathersInfrastructureConfig, GeneratesProjectInfrastructure, InteractsWithDocker, InteractsWithDynamicOptions, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'init {--fast : Skip the wizard and use ideal defaults}
                                 {--dry-run : Show what will be done without making any changes}';

    /**
     * Configure the command to ignore validation errors so we can forward arbitrary flags.
     */
    protected function configure(): void
    {
        $this->ignoreValidationErrors();
        $this->addArchitecturalOptions();
    }

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize LaraKube for an existing Laravel project';

    /**
     * Execute the console command.
     *
     * @throws RandomException
     */
    public function handle(): int
    {
        $this->renderHeader();

        // 1. Nesting Protection & Reset Suggestion
        if ($this->isLaraKubeProject(false)) {
            $this->newLine();
            $this->warn(' ⚠ ALREADY INITIALIZED: This directory is already a LaraKube CLI project.');
            $this->line('   Running "init" again may conflict with your existing configuration.');
            $this->newLine();
            $this->info('   👉 BEST PRACTICE: If you want to start fresh, run "larakube reset" first.');
            $this->newLine();

            if (! confirm('Are you sure you want to proceed with re-initialization?')) {
                $this->laraKubeInfo('Initialization cancelled.');

                return 0;
            }

            $this->logActivity('Project re-initialization confirmed', ['action' => 'init'], getcwd());
        }

        if (! $this->checkPrerequisites()) {
            return 1;
        }

        $config = $this->gatherConfig($this->buildConfigFromFlags());
        $config->setPath(getcwd());
        $config->setName(Str::slug(basename($config->getPath())));
        $config->setEnvironments(['local', 'production']);

        $this->laraKubeInfo("Initializing LaraKube for project: {$config->getName()}...");

        $installFeatures = false;

        if (! empty($config->getFeatures())) {
            $installFeatures = confirm('Would you like to install the selected Laravel features now?');
        }

        // 1. Show Preview
        $this->orchestrateProjectScaffolding($config, $installFeatures, dryRun: true);

        if ($this->option('dry-run')) {
            return 0;
        }

        // 2. Confirm (Skip if --fast or --no-interaction)
        if (! $this->option('fast') && ! $this->option('no-interaction')) {
            if (! confirm('Would you like to initialize LaraKube with these settings?')) {
                $this->laraKubeInfo('Initialization cancelled.');

                return 0;
            }
        }

        $this->orchestrateProjectScaffolding($config, $installFeatures);

        $this->laraKubeInfo("LaraKube initialized successfully for {$config->getName()}!");
        info('Next steps: larakube up');

        return 0;
    }
}
