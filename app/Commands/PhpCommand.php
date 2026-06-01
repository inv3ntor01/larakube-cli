<?php

namespace App\Commands;

use App\Traits\CapturesPassthroughArgs;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class PhpCommand extends Command
{
    use CapturesPassthroughArgs, LaraKubeOutput;

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

    public function __construct()
    {
        parent::__construct();

        $this->ignoreValidationErrors();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        ['command' => $phpCommand, 'options' => $opts] = $this->capturePassthroughArgs('php');

        // Test-runner safety net: `larakube php artisan test`,
        // `larakube php vendor/bin/pest`, etc. would otherwise wipe the dev DB.
        if (static::looksLikeTestRunner($phpCommand)) {
            return $this->delegateToTestCommand();
        }

        return $this->call('exec', [
            'commands' => ["php {$phpCommand}"],
            '--environment' => $opts['environment'],
            '--service' => 'web',
        ]);
    }
}
