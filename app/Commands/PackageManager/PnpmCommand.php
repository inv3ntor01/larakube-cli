<?php

namespace App\Commands\PackageManager;

use App\Traits\CapturesPassthroughArgs;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class PnpmCommand extends Command
{
    use CapturesPassthroughArgs, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pnpm {commands* : The pnpm command to run} {--environment=local : The environment to target}';

    /**
     * The console command description.
     */
    protected $description = 'Run a pnpm command inside the Node pod';

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
        ['command' => $pnpmCommand, 'options' => $opts] = $this->capturePassthroughArgs('pnpm');

        return $this->call('node', [
            'commands' => ["pnpm {$pnpmCommand}"],
            '--environment' => $opts['environment'],
        ]);
    }
}
