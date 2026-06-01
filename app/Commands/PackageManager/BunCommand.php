<?php

namespace App\Commands\PackageManager;

use App\Traits\CapturesPassthroughArgs;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class BunCommand extends Command
{
    use CapturesPassthroughArgs, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bun {commands* : The bun command to run} {--environment=local : The environment to target}';

    /**
     * The console command description.
     */
    protected $description = 'Run a bun command inside the Node pod';

    public function __construct()
    {
        parent::__construct();

        $this->ignoreValidationErrors();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        ['command' => $bunCommand, 'options' => $opts] = $this->capturePassthroughArgs('bun');

        return $this->call('node', [
            'commands' => ["bun {$bunCommand}"],
            '--environment' => $opts['environment'],
        ]);
    }
}
