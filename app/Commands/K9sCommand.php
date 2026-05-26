<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class K9sCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'k9s {environment? : The environment to monitor (local or production)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Launch K9s terminal UI pre-scoped to your project namespace';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $hasK9s = shell_exec('which k9s') !== null;

        if (! $hasK9s) {
            $this->laraKubeError('K9s is not installed.');
            $isLinux = PHP_OS_FAMILY === 'Linux';
            $k9sCmd = $isLinux ? 'sudo snap install k9s' : 'brew install k9s';
            $this->warn("  👉 Install it for the best CLI experience: {$k9sCmd}");

            return 1;
        }

        $environment = $this->argument('environment');

        if (! $environment) {
            $environment = $this->askForEnvironment('Which environment would you like to monitor?');
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        $namespace = $this->getNamespace($environment, $config->getName());

        $this->laraKubeInfo("Launching K9s for project <fg=cyan;options=bold>{$config->getName()}</> in namespace: <fg=yellow;options=bold>{$namespace}</>...");

        // Execute k9s
        passthru("k9s -n {$namespace}");

        return 0;
    }
}
