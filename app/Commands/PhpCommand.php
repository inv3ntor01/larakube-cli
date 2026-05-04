<?php

namespace App\Commands;

use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class PhpCommand extends Command
{
    use LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'php {commands* : The php command to run} {--environment=local : The environment to target}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a php command inside the cluster';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $phpCommand = implode(' ', $this->argument('commands'));

        return $this->call('exec', [
            'commands' => ["php {$phpCommand}"],
            '--environment' => $this->option('environment'),
            '--service' => 'web',
        ]);
    }
}
