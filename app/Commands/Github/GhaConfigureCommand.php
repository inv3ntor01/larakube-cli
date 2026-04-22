<?php

namespace App\Commands\Github;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class GhaConfigureCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithGlobalConfig, LaraKubeOutput;

    protected $signature = 'gha:configure {environment? : The environment to configure (production, staging, etc.)}';

    protected $description = 'Configure GitHub Actions secrets using the native GitHub CLI container';

    public function handle(): int
    {
        $this->renderHeader();

        $environment = $this->argument('environment') ?? 'production';
        $upperEnv = strtoupper($environment);
        $projectPath = getcwd();

        $this->laraKubeInfo("Configuring GitHub Secrets for '{$environment}' via Docker...");

        // 1. Check for .env.{env} file
        $envFile = ".env.{$environment}";
        if (! file_exists($projectPath.'/'.$envFile)) {
            $this->laraKubeError("Crucial file '{$envFile}' is missing!");
            $this->line('Please create it before configuring GitHub Actions.');

            return 1;
        }

        $gh = $this->getGhDockerCommand();

        // 2. Upload ENV_FILE_BASE64
        $this->laraKubeInfo("Uploading {$upperEnv}_ENV_FILE_BASE64...");
        $base64Env = base64_encode(file_get_contents($projectPath.'/'.$envFile));
        passthru("echo '{$base64Env}' | {$gh} secret set {$upperEnv}_ENV_FILE_BASE64");
        // 3. Upload KUBECONFIG
        $this->laraKubeInfo("Setting KUBECONFIG secret for {$environment}...");
        $kubeConfigPath = $_SERVER['HOME'].'/.kube/config';

        $secretName = "{$upperEnv}_KUBECONFIG";

        if (file_exists($kubeConfigPath)) {
            $kubeConfigContent = file_get_contents($kubeConfigPath);
            passthru("echo '{$kubeConfigContent}' | {$gh} secret set {$secretName}");
            // Also set a generic one as fallback if it doesn't exist
            passthru("echo '{$kubeConfigContent}' | {$gh} secret set KUBECONFIG --no-overwrite 2>/dev/null");
        } else {
            $this->laraKubeError("Local Kubeconfig not found. Please set the {$secretName} secret manually.");
        }

        $this->laraKubeInfo("GitHub Secrets configured successfully for '{$environment}'!");

        return 0;
    }
}
