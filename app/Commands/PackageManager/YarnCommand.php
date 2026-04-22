<?php

namespace App\Commands\PackageManager;

use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class YarnCommand extends Command
{
    use LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'yarn {commands* : The yarn command to run} {--environment=local : The environment to target}';

    /**
     * The console command description.
     */
    protected $description = 'Run a yarn command inside the Node pod';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $yarnCommand = implode(' ', $this->argument('commands'));

        return $this->call('node', [
            'commands' => ["yarn {$yarnCommand}"],
            '--environment' => $this->option('environment'),
        ]);
    }
}
