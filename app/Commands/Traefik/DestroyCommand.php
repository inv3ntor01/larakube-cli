<?php

namespace App\Commands\Traefik;

use App\Traits\InteractsWithTraefik;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;

class DestroyCommand extends Command
{
    use InteractsWithTraefik, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'traefik:destroy {--force : Skip confirmation}';

    /**
     * The console command description.
     */
    protected $description = 'Completely remove the Traefik Ingress Controller and its permissions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->option('force')) {
            $this->laraKubeError('WARNING: This will completely REMOVE the networking stack from your cluster.');
            $this->warn('  ● All local .dev.test domains will become unreachable.');
            $this->line('');

            if (! confirm('Are you absolutely sure you want to destroy Traefik?', false)) {
                $this->laraKubeInfo('Destroy cancelled.');

                return 0;
            }
        }

        $this->destroyTraefik();
        $this->laraKubeInfo('✅ Traefik has been removed from the cluster.');

        return 0;
    }
}
