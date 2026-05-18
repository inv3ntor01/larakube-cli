<?php

namespace App\Commands;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\text;

class EnvCommand extends Command
{
    use InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'env {name? : The name of the new environment}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Kubernetes environment overlay';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfigObject($projectPath);
        $appName = $config->getName() ?? basename($projectPath);

        $envName = $this->argument('name') ?? text(
            label: 'What is the name of the new environment?',
            placeholder: 'staging',
            required: true
        );

        $baseOverlayPath = "{$projectPath}/.infrastructure/k8s/overlays";
        $newEnvPath = "{$baseOverlayPath}/{$envName}";

        if (! is_dir("{$baseOverlayPath}/production")) {
            $this->laraKubeError('Base production environment not found.');

            return 1;
        }

        if (is_dir($newEnvPath)) {
            $this->laraKubeInfo("Environment '{$envName}' filesystem structure already exists.");
        } else {
            $this->laraKubeInfo("Creating environment '{$envName}'...");

            @mkdir($newEnvPath, 0755, true);

            // 1. Create .env.{env} file
            $newEnvFile = ".env.{$envName}";
            if (! file_exists($projectPath.'/'.$newEnvFile)) {
                copy($projectPath.'/.env', $projectPath.'/'.$newEnvFile);
                $this->laraKubeInfo("Created {$newEnvFile}");
            }

            // 2. Update .gitignore
            $gitignorePath = $projectPath.'/.gitignore';
            if (file_exists($gitignorePath)) {
                $gitignore = file_get_contents($gitignorePath);
                if (! str_contains($gitignore, '.env.*')) {
                    $gitignore .= "\n.env.*\n";
                    file_put_contents($gitignorePath, $gitignore);
                    $this->laraKubeInfo('Updated .gitignore to exclude .env.* files');
                }
            }

            // Copy from production as a safe base
            $files = ['kustomization.yaml', 'namespace.yaml', 'deployment-patch.yaml', 'ingress-patch.yaml'];
            foreach ($files as $file) {
                if (! file_exists("{$baseOverlayPath}/production/{$file}")) {
                    continue;
                }

                $content = file_get_contents("{$baseOverlayPath}/production/{$file}");

                // Update the namespace in the new files
                $oldNamespace = "{$appName}-production";
                $newNamespace = "{$appName}-{$envName}";
                $content = str_replace($oldNamespace, $newNamespace, $content);

                file_put_contents("{$newEnvPath}/{$file}", $content);
            }
        }

        // 3. Update Project DNA
        $config->addEnvironment($envName);
        $this->saveProjectConfig($projectPath, $config);

        $this->laraKubeInfo("Environment '{$envName}' is now part of your project DNA.");
        info("Next steps: larakube up {$envName}");
    }
}
