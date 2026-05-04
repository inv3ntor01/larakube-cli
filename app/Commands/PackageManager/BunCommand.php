<?php

namespace App\Commands\PackageManager;

use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class BunCommand extends Command
{
    use LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'bun {commands* : The bun command to run} {--environment=local : The environment to target}';

    /**
     * The console command description.
     */
    protected $description = 'Run a bun command inside the Node pod';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $bunCommand = implode(' ', $this->argument('commands'));

        return $this->call('node', [
            'commands' => ["bun {$bunCommand}"],
            '--environment' => $this->option('environment'),
        ]);
    }
}
