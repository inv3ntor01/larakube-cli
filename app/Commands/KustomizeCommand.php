<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class KustomizeCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kustomize {environment? : The environment to preview (local or production)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Preview the final, merged Kubernetes manifests for a given environment';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $environment = $this->argument('environment');

        if (! $environment) {
            $environment = $this->askForEnvironment('Which environment would you like to preview?');
        }

        $projectPath = getcwd();
        $overlayPath = "{$projectPath}/.infrastructure/k8s/overlays/{$environment}";

        if (! is_dir($overlayPath)) {
            $this->laraKubeError("Infrastructure overlay not found for environment: {$environment}");
            $this->info('Try running "larakube heal" to regenerate your manifests.');

            return 1;
        }

        $this->laraKubeInfo("Rendering merged manifests for environment: <fg=cyan;options=bold>{$environment}</>");
        $this->newLine();

        // Use kubectl kustomize to render the final YAML
        passthru("kubectl kustomize {$overlayPath}");

        return 0;
    }
}
