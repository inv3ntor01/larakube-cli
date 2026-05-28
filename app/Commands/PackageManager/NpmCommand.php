<?php

namespace App\Commands\PackageManager;

use App\Traits\CapturesPassthroughArgs;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class NpmCommand extends Command
{
    use CapturesPassthroughArgs, LaraKubeOutput;

    public function __construct()
    {
        parent::__construct();

        $this->ignoreValidationErrors();
    }

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
        ['command' => $npmCommand, 'options' => $opts] = $this->capturePassthroughArgs('npm');

        return $this->call('node', [
            'commands' => ["npm {$npmCommand}"],
            '--environment' => $opts['environment'],
        ]);
    }
}
