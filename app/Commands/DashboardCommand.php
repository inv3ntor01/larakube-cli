<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class DashboardCommand extends Command
{
    use InteractsWithEnvironments, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dashboard {environment? : The environment to monitor (local or production)} 
                            {--simple : Use simple kubectl view instead of k9s}
                            {--traefik : Open the Traefik network dashboard}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Open a dashboard to monitor your Kubernetes cluster or network';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $environment = $this->argument('environment') ?? 'local';
        $namespace = $this->getNamespace($environment);

        if ($this->option('traefik')) {
            return $this->showTraefikDashboard();
        }

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
