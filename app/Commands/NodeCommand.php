<?php

namespace App\Commands;

use App\Traits\CapturesPassthroughArgs;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class NodeCommand extends Command
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
    protected $signature = 'node {commands* : The npm or node command to run} {--environment=local : The environment to target}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run npm or node commands inside the Kubernetes Node pod';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        ['command' => $nodeCommand, 'options' => $opts] = $this->capturePassthroughArgs('node');

        return $this->call('exec', [
            'commands' => [$nodeCommand],
            '--service' => 'node',
            '--environment' => $opts['environment'],
        ]);
    }
}
