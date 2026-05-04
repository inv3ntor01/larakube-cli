<?php

namespace App\Commands\PackageManager;

use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class NpmCommand extends Command
{
    use LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'npm {commands* : The npm command to run} {--environment=local : The environment to target}';

    /**
     * The console command description.
     */
    protected $description = 'Run an npm command inside the Node pod';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $npmCommand = implode(' ', $this->argument('commands'));

        return $this->call('node', [
            'commands' => ["npm {$npmCommand}"],
            '--environment' => $this->option('environment'),
        ]);
    }
}
