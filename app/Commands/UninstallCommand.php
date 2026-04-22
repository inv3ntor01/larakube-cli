<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithInternalDatabase;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

class UninstallCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithInternalDatabase, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'uninstall {--image : Also delete the local Docker image}
                                     {--dry-run : Show what will be removed without making changes}
                                     {--force : Skip all confirmations (Danger)}';

    /**
     * The console command description.
     */
    protected $description = 'Completely remove LaraKube footprint, cluster resources, and optionally images';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $projectPath = getcwd();
        $appName = basename($projectPath);

        if (! file_exists($projectPath.'/.larakube.json')) {
            $this->laraKubeError('Not a LaraKube project.');

            return 1;
        }

        $filesToRemove = [
            '.infrastructure',
            '.larakube.json',
            'Dockerfile.php',
            'Dockerfile.node',
            'docker-compose.yml',
        ];

        $foundFiles = [];
        foreach ($filesToRemove as $file) {
            if (file_exists($projectPath.'/'.$file)) {
                $foundFiles[] = $file;
            }
        }

        // 1. Show Architectural Preview (Transparency-by-Default)
        $this->laraKubeInfo('Architectural Preview: Total Project Cleanup');

        $this->line('  <fg=red>[CLUSTER]</> Would delete ALL namespaces (local, production, etc.)');
        foreach ($foundFiles as $file) {
            $type = is_dir($projectPath.'/'.$file) ? 'DIR' : 'FILE';
            $this->line("  <fg=red>[DELETE]</> {$file} ({$type})");
        }

        if ($this->option('image')) {
            $this->line("  <fg=red>[DOCKER]</> Would delete local image '{$appName}:latest'");
        }

        $this->line('  <fg=gray>[INFO]</> Your Laravel source code, migrations, and .env files are safe.');
        $this->line('');

        if ($this->option('dry-run')) {
            $this->line('  <fg=yellow;options=bold>⚠ No changes have been applied yet.</>');

            return 0;
        }

        // 2. Multi-Step Confirmation
        if (! $this->option('force')) {
            $confirmName = text(
                label: "To confirm UNINSTALL, please type the project name '{$appName}':",
                required: true
            );

            if ($confirmName !== $appName) {
                $this->laraKubeError('Project name mismatch. Uninstall aborted.');

                return 1;
            }

            if (! confirm('Are you absolutely sure? This will WIPE all cluster data and local manifests.', false)) {
                $this->laraKubeInfo('Uninstall cancelled.');

                return 0;
            }
        }

        // 3. Execute Cleanup
        $this->withSpin('Tearing down cluster resources...', function () use ($appName) {
            // Delete all environments
            foreach (['local', 'production', 'staging'] as $env) {
                $namespace = "{$appName}-{$env}";
                exec("kubectl delete namespace {$namespace} 2>/dev/null");
            }

            return true;
        });

        $this->withSpin('Removing LaraKube footprint...', function () use ($projectPath, $foundFiles) {
            $this->logActivity('LaraKube project uninstalled', ['files_removed' => $foundFiles], $projectPath);

            foreach ($foundFiles as $file) {
                $path = $projectPath.'/'.$file;
                if (is_dir($path)) {
                    $this->recursiveDelete($path);
                } else {
                    @unlink($path);
                }
            }

            // Remove from the internal list
            $this->unregisterProject($projectPath);

            return true;
        });

        if ($this->option('image')) {
            $this->withSpin('Cleaning up Docker images...', function () use ($appName) {
                exec("docker rmi -f {$appName}:latest 2>/dev/null");
                exec("docker rmi -f {$appName}:local 2>/dev/null");

                return true;
            });
        }

        $this->laraKubeInfo('LaraKube has been successfully removed from this project.');

        return 0;
    }

    protected function recursiveDelete($dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->recursiveDelete("$dir/$file") : @unlink("$dir/$file");
        }
        @rmdir($dir);
    }
}
