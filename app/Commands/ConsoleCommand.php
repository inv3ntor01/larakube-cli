<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithHosts;
use App\Traits\InteractsWithSslTrust;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;

class ConsoleCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithHosts, InteractsWithSslTrust, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'console {environment? : The environment to monitor (local or production)} 
                            {--cli : Force CLI-based monitoring (K9s)}
                            {--web : Open the LaraKube Console}
                            {--down : Remove the LaraKube Console from the cluster}
                            {--update : Force update the LaraKube Console manifests}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Open a dashboard (K9s or Web) to monitor your cluster';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if ($this->option('down')) {
            return $this->removeConsole();
        }

        // If --web is requested, show the Console
        if ($this->option('web')) {
            return $this->showConsole();
        }

        // If an environment is specified or --cli is forced, go to K9s
        if ($this->option('cli') || $this->argument('environment')) {
            return $this->call('k9s', [
                'environment' => $this->argument('environment'),
            ]);
        }

        // Default behavior: Ask the user or promote K9s
        $this->info('  LaraKube offers two ways to monitor your cluster:');
        $this->line('  1. <fg=cyan;options=bold>K9s</> (Recommended) - Powerful, real-time terminal UI.');
        $this->line('  2. <fg=yellow;options=bold>Web UI</> - Clean, web-based management at console.dev.test.');
        $this->newLine();

        $choice = $this->choice('Which would you like to open?', [
            'k9s' => 'K9s Terminal UI (Fast & Powerful)',
            'web' => 'LaraKube Console (Visual Management)',
        ], 'k9s');

        return $choice === 'k9s' ? $this->call('k9s') : $this->showConsole();
    }

    protected function showConsole(): int
    {
        $this->laraKubeInfo('Launching LaraKube Console...');

        // Consistent workspace resolution:
        // If we are in a project, the workspace is the parent directory.
        // Otherwise, the workspace is the current directory.
        $workspace = getcwd();
        if (file_exists($workspace.'/.larakube.json')) {
            $workspace = dirname($workspace);
        }

        $this->line("  <fg=gray>Workspace mapped to:</> <fg=blue>$workspace</>");
        $this->line('  <fg=gray>This allows the Console to manage projects in this directory.</>');
        $this->newLine();

        // 🛡 Automated Host Mapping & SSL Trust
        $this->ensureHostsAreSet(['console.dev.test', 'traefik.dev.test'], 'larakube-system');

        if (! $this->isSslTrusted()) {
            $this->newLine();
            $this->warn(' 🔒 The LaraKube Console requires a trusted LaraKube Local CA for HTTPS.');
            if (confirm('Would you like to install the trust now? (Requires sudo/admin)', true)) {
                $this->call('trust');
            }
        }

        $exists = shell_exec('kubectl get namespace larakube-system --no-headers 2>/dev/null');

        if (! $exists || $this->option('update')) {
            $label = $this->option('update') ? 'Updating LaraKube Console...' : 'The LaraKube Console is not installed. Would you like to install it now?';

            if (! $exists || $this->confirm($label, true)) {
                $this->withSpin($this->option('update') ? 'Updating manifests...' : 'Installing LaraKube Console...', function () use ($workspace) {
                    // Resolve the absolute path to the currently running LaraKube binary
                    $binaryPath = realpath($_SERVER['argv'][0]) ?: '/usr/local/bin/larakube';

                    $manifest = view('k8s.system-dashboard', [
                        'binaryPath' => $binaryPath,
                        'workspacePath' => $workspace,
                    ])->render();

                    $tmp = sys_get_temp_dir().'/larakube-dashboard.yaml';
                    file_put_contents($tmp, $manifest);
                    passthru("kubectl apply -f {$tmp}");
                    unlink($tmp);
                });

                $this->laraKubeInfo($this->option('update') ? '✅ LaraKube Console updated.' : '✅ LaraKube Console installed.');
            }
        }

        $url = 'https://console.dev.test';

        $this->laraKubeInfo("Opening: {$url}");

        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start',
            default => 'xdg-open',
        };

        passthru("{$command} {$url}");

        return 0;
    }

    protected function removeConsole(): int
    {
        if (! $this->confirm('Are you sure you want to remove the LaraKube Console?', true)) {
            return 0;
        }

        $this->withSpin('Removing LaraKube Console resources...', function () {
            passthru('kubectl delete namespace larakube-system --wait=false');
        });

        $this->laraKubeInfo('✅ LaraKube Console removal initiated.');

        return 0;
    }
}
