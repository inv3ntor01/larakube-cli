<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class ShareCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'share {--stop : Stop the active share}';

    /**
     * The console command description.
     */
    protected $description = 'Expose your local LaraKube project to the internet via Cloudflare Tunnel';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->ensureIsProject()) {
            return 1;
        }

        $namespace = $this->getNamespace('local');

        if ($this->option('stop')) {
            $this->stopShare($namespace);

            return 0;
        }

        return $this->startShare($namespace);
    }

    protected function startShare(string $namespace): int
    {
        $this->laraKubeInfo('Initializing Cloudflare Tunnel...');

        // 1. Prepare Manifest
        $stub = file_get_contents(resource_path('views/k8s/cloudflared/deployment.blade.php'));

        // 2. Apply Manifest
        $this->withSpin('Deploying tunnel pod...', function () use ($namespace, $stub) {
            $process = proc_open("kubectl apply -f - -n {$namespace}", [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);

            fwrite($pipes[0], $stub);
            fclose($pipes[0]);
            proc_close($process);

            return true;
        });

        // 3. Wait for Pod to be ready
        $this->withSpin('Waiting for Cloudflare to assign a URL...', function () use ($namespace) {
            exec("kubectl wait --for=condition=ready pod -l app=larakube-share -n {$namespace} --timeout=60s");

            return true;
        });

        // 4. Extract URL from logs
        $url = null;
        $attempts = 0;
        while (! $url && $attempts < 10) {
            $logs = shell_exec("kubectl logs -l app=larakube-share -n {$namespace} --tail=20 2>&1");
            if (preg_match('/https:\/\/[a-z0-9-]+\.trycloudflare\.com/', $logs, $matches)) {
                $url = $matches[0];
            }
            $attempts++;
            sleep(2);
        }

        if (! $url) {
            $this->laraKubeError('Failed to retrieve tunnel URL. Check logs with: larakube logs larakube-share');

            return 1;
        }

        $this->laraKubeInfo('Tunnel active! Your project is now public:');
        $this->line('');
        $this->line("    🌐 <fg=cyan;options=bold>{$url}</>");
        $this->line('');
        $this->info('Press Ctrl+C to stop sharing (or run larakube share --stop)');

        // Keep alive and trap Ctrl+C if possible, or just wait for input
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () use ($namespace) {
                $this->stopShare($namespace);
                exit(0);
            });

            while (true) {
                sleep(1);
            }
        } else {
            $this->confirm('Sharing... Press Enter to stop', true);
            $this->stopShare($namespace);
        }

        return 0;
    }

    protected function stopShare(string $namespace): void
    {
        $this->withSpin('Tearing down tunnel...', function () use ($namespace) {
            exec("kubectl delete deployment larakube-share -n {$namespace} --ignore-not-found");

            return true;
        });
        $this->laraKubeInfo('Tunnel stopped successfully.');
    }
}
