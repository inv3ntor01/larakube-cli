<?php

namespace App\Commands\PackageManager;

use App\Traits\CapturesPassthroughArgs;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class YarnCommand extends Command
{
    use CapturesPassthroughArgs, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'yarn {commands* : The yarn command to run} {--environment=local : The environment to target}';

    /**
     * The console command description.
     */
    protected $description = 'Run a yarn command inside the Node pod';

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
        ['command' => $yarnCommand, 'options' => $opts] = $this->capturePassthroughArgs('yarn');

        return $this->call('node', [
            'commands' => ["yarn {$yarnCommand}"],
            '--environment' => $opts['environment'],
        ]);
    }
}
