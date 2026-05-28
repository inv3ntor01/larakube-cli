<?php

namespace App\Commands;

use App\Traits\CapturesPassthroughArgs;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class ArtisanCommand extends Command
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
    protected $signature = 'art {commands* : The artisan command to run} {--environment=local : The environment to target}';

    /**
     * The console command aliases.
     *
     * @var array
     */
    protected $aliases = ['artisan'];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a php artisan command inside the cluster';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        ['command' => $artisanCommand, 'options' => $opts] = $this->capturePassthroughArgs(['art', 'artisan']);

        if (static::looksLikeTestRunner($artisanCommand)) {
            return $this->delegateToTestCommand();
        }

        return $this->call('exec', [
            'commands' => ["php artisan {$artisanCommand}"],
            '--environment' => $opts['environment'],
            '--service' => 'web',
        ]);
    }
}
