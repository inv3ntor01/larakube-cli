<?php

namespace App\Commands\Traefik;

use App\Traits\InteractsWithTraefik;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class SetupCommand extends Command
{
    use InteractsWithTraefik, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'traefik:setup';

    /**
     * The console command description.
     */
    protected $description = 'Install or upgrade the Traefik Ingress Controller';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();
        $this->setupTraefik();
        $this->laraKubeInfo('✅ Traefik setup complete.');

        return 0;
    }
}
