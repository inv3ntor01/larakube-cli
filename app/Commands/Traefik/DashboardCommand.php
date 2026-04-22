<?php

namespace App\Commands\Traefik;

use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class DashboardCommand extends Command
{
    use LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'traefik:dashboard';

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
