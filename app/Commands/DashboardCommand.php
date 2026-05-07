<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithHosts;
use App\Traits\InteractsWithInternalDatabase;
use App\Traits\InteractsWithSslTrust;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;

class DashboardCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithHosts, InteractsWithInternalDatabase, InteractsWithSslTrust, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dashboard {environment? : The environment to monitor (local or production)} 
                            {--simple : Use simple kubectl view instead of k9s}
                            {--traefik : Open the Traefik network dashboard}
                            {--cli : Force CLI-based monitoring (K9s/kubectl)}
                            {--web : Open the LaraKube System Web Dashboard}
                            {--down : Remove the LaraKube System Dashboard from the cluster}
                            {--update : Force update the System Dashboard manifests}';

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
            return $this->removeSystemDashboard();
        }

        if ($this->option('traefik')) {
            return $this->showTraefikDashboard();
        }

        // If --web is requested, show the System Dashboard
        if ($this->option('web')) {
            return $this->showSystemDashboard();
        }

        // If an environment is specified or --cli is forced, go to K9s/CLI view
        if ($this->option('cli') || $this->argument('environment')) {
            return $this->showCliDashboard();
        }

        // Default behavior: Ask the user or promote K9s
        $this->info('  LaraKube offers two ways to monitor your cluster:');
        $this->line('  1. <fg=cyan;options=bold>K9s</> (Recommended) - Powerful, real-time terminal UI.');
        $this->line('  2. <fg=yellow;options=bold>Web UI</> - Clean, web-based monitoring at larakube.dev.test.');
        $this->newLine();

        $choice = $this->choice('Which dashboard would you like to open?', [
            'k9s' => 'K9s Terminal UI (Fast & Powerful)',
            'web' => 'LaraKube Web Dashboard (Visual)',
        ], 'k9s');

        return $choice === 'k9s' ? $this->showCliDashboard() : $this->showSystemDashboard();
    }

    protected function showCliDashboard(): int
    {
        $environment = $this->argument('environment') ?? 'local';
        $namespace = $this->getNamespace($environment);

        $hasK9s = shell_exec('which k9s') !== null;
        $hasWatch = shell_exec('which watch') !== null;

        // Best experience: K9s
        if (! $this->option('simple') && $hasK9s) {
            $this->laraKubeInfo("Launching K9s for namespace: {$namespace}...");
            passthru("k9s -n {$namespace}");

            return 0;
        }

        // Fallback or Simple experience
        $this->laraKubeInfo("Monitoring namespace: {$namespace} (Live View)");

        $isLinux = PHP_OS_FAMILY === 'Linux';
        $watchCmd = $isLinux ? 'sudo apt install watch' : 'brew install watch';
        $k9sCmd = $isLinux ? 'snap install k9s' : 'brew install k9s';

        if (! $hasK9s) {
            $this->warn("  💡 TIP: For a much better experience, install K9s: {$k9sCmd}");
        }

        if (! $hasWatch) {
            $this->warn("  💡 TIP: For a smoother live view, install watch: {$watchCmd}");
        }

        $this->info('  Press Ctrl+C to stop.');
        $this->line('');

        if ($hasWatch) {
            passthru("watch -n 1 kubectl get pods -n {$namespace}");
        } else {
            // Jarring fallback loop
            while (true) {
                passthru('clear');
                $this->laraKubeInfo("Monitoring namespace: {$namespace} (Live View)");
                $this->warn("  TIP: install 'watch' ({$watchCmd}) to stop the blinking.");
                $this->line('');
                passthru("kubectl get pods -n {$namespace}");
                sleep(1);
            }
        }

        return 0;
    }

    protected function showSystemDashboard(): int
    {
        $this->laraKubeInfo('Launching LaraKube System Dashboard...');

        // 🛡 Automated Host Mapping & SSL Trust
        $this->ensureHostsAreSet(['larakube.dev.test'], 'larakube-system');

        if (! $this->isSslTrusted()) {
            $this->newLine();
            $this->warn(' 🔒 The System Dashboard requires a trusted LaraKube Local CA for HTTPS.');
            if (confirm('Would you like to install the trust now? (Requires sudo/admin)', true)) {
                $this->call('trust');
            }
        }

        $exists = shell_exec('kubectl get namespace larakube-system --no-headers 2>/dev/null');

        if (! $exists || $this->option('update')) {
            $label = $this->option('update') ? 'Updating System Dashboard...' : 'The LaraKube System Dashboard is not installed. Would you like to install it now?';

            if (! $exists || $this->confirm($label, true)) {
                $this->withSpin($this->option('update') ? 'Updating manifests...' : 'Installing System Dashboard...', function () {
                    $manifest = view('k8s.system-dashboard')->render();
                    $tmp = sys_get_temp_dir().'/larakube-dashboard.yaml';
                    file_put_contents($tmp, $manifest);
                    passthru("kubectl apply -f {$tmp}");
                    unlink($tmp);
                });

                $this->laraKubeInfo($this->option('update') ? '✅ System Dashboard updated.' : '✅ System Dashboard installed.');
            }
        }

        $url = 'https://larakube.dev.test';

        $this->laraKubeInfo("Opening: {$url}");

        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start',
            default => 'xdg-open',
        };

        passthru("{$command} {$url}");

        return 0;
    }

    protected function removeSystemDashboard(): int
    {
        if (! $this->confirm('Are you sure you want to remove the LaraKube System Dashboard?', true)) {
            return 0;
        }

        $this->withSpin('Removing System Dashboard resources...', function () {
            passthru('kubectl delete namespace larakube-system --wait=false');
        });

        $this->laraKubeInfo('✅ System Dashboard removal initiated.');

        return 0;
    }

    /**
     * Open a tunnel to the Traefik Dashboard.
     */
    protected function showTraefikDashboard(): int
    {
        $this->laraKubeInfo('Opening Traefik Network Dashboard...');

        $this->line('');
        $this->line('    🌐 <fg=cyan;options=bold>http://localhost:8080/dashboard/</>');
        $this->line('');
        $this->info('  Keep this process running to maintain the connection.');
        $this->info('  Press Ctrl+C to stop.');
        $this->line('');

        passthru('kubectl port-forward -n traefik svc/traefik 8080:8080');

        return 0;
    }
}
