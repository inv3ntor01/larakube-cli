<?php

namespace App\Commands;

use App\Traits\CheckPrerequisites;
use App\Traits\GathersInfrastructureConfig;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

use function Laravel\Prompts\info;

class InitCommand extends Command
{
    use CheckPrerequisites, GathersInfrastructureConfig, GeneratesProjectInfrastructure, InteractsWithDocker, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'init {--fast : Skip the wizard and use ideal defaults}
                                 {--dry-run : Show what will be done without making any changes}';

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

        // 1. Nesting Protection
        if (file_exists(getcwd().'/.larakube.json')) {
            $this->line('');
            $this->warn(' ⚠ NESTING WARNING: This directory is already a LaraKube CLI project.');
            $this->line('   Running "init" again may overwrite your existing configuration.');
            $this->line('');

            if (! $this->confirm('Are you sure you want to re-initialize or nest?', false)) {
                $this->laraKubeInfo('Initialization cancelled.');

                return 0;
            }

            $this->logActivity('Project nesting warning ignored', ['action' => 'init'], getcwd());
        }

        if (! $this->checkPrerequisites()) {
            return 1;
        }

        $projectPath = getcwd();
        $appName = basename($projectPath);

        $this->laraKubeInfo("Initializing LaraKube for project: {$appName}...");

        $config = $this->gatherConfig();

        $installFeatures = false;
        if (! empty($config['features'])) {
            $installFeatures = $this->confirm('Would you like to install the selected Laravel features now?', true);
        }

        // 1. Show Preview
        $this->orchestrateProjectScaffolding($projectPath, $appName, $config, $installFeatures, true, true);

        if ($this->option('dry-run')) {
            return 0;
        }

        // 2. Confirm (Skip if --fast or --no-interaction)
        if (! $this->option('fast') && ! $this->option('no-interaction')) {
            if (! $this->confirm('Would you like to initialize LaraKube with these settings?', true)) {
                $this->laraKubeInfo('Initialization cancelled.');

                return 0;
            }
        }

        $this->orchestrateProjectScaffolding($projectPath, $appName, $config, $installFeatures, true);

        $this->laraKubeInfo("LaraKube initialized successfully for {$appName}!");
        info('Next steps: larakube up');

        return 0;
    }
}
