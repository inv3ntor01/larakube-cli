<?php

namespace App\Commands\Traefik;

use App\Traits\InteractsWithHosts;
use App\Traits\InteractsWithSslTrust;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;

class DashboardCommand extends Command
{
    use InteractsWithHosts, InteractsWithSslTrust, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'traefik:dashboard {--tunnel : Force a local port-forward tunnel}';

    /**
     * The console command description.
     */
    protected $description = 'Open the Traefik network dashboard';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if ($this->option('tunnel')) {
            return $this->runTunnel();
        }

        $this->laraKubeInfo('Opening Traefik Network Dashboard...');

        // 🛡 Automated Host Mapping & SSL Trust
        $this->ensureHostsAreSet(['traefik.dev.test'], 'larakube-system');

        $url = 'https://traefik.dev.test/dashboard/';

        if (! $this->isSslTrusted()) {
            $this->newLine();
            $this->warn(' 🔒 Traefik requires a trusted LaraKube Local CA for HTTPS.');
            $this->line('    Falling back to HTTP tunnel if trust is skipped.');
            if (confirm('Would you like to install the trust now? (Requires sudo/admin)', true)) {
                $this->call('trust');
            } else {
                return $this->runTunnel();
            }
        }

        $this->laraKubeInfo("Opening: {$url}");

        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start',
            default => 'xdg-open',
        };

        passthru("{$command} {$url}");

        return 0;
    }

    protected function runTunnel(): int
    {
        $this->laraKubeInfo('Launching Traefik Tunnel (Port Forward)...');

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
