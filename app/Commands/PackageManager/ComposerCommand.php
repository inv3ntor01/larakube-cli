<?php

namespace App\Commands\PackageManager;

use App\Traits\CapturesPassthroughArgs;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class ComposerCommand extends Command
{
    use CapturesPassthroughArgs, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'composer {commands* : The composer command to run} {--environment=local : The environment to target}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a composer command inside the cluster';

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
        ['command' => $composerCommand, 'options' => $opts] = $this->capturePassthroughArgs('composer');

        return $this->call('exec', [
            'commands' => ["composer {$composerCommand}"],
            '--environment' => $opts['environment'],
            '--service' => 'web',
        ]);
    }
}
