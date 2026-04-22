<?php

namespace App\Commands\PackageManager;

use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class PnpmCommand extends Command
{
    use LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'pnpm {commands* : The pnpm command to run} {--environment=local : The environment to target}';

    /**
     * The console command description.
     */
    protected $description = 'Run a pnpm command inside the Node pod';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $pnpmCommand = implode(' ', $this->argument('commands'));

        return $this->call('node', [
            'commands' => ["pnpm {$pnpmCommand}"],
            '--environment' => $this->option('environment'),
        ]);
    }
}
