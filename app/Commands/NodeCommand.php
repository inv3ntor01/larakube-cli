<?php

namespace App\Commands;

use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class NodeCommand extends Command
{
    use LaraKubeOutput;

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
        // Capture everything from the original command line after 'node'
        $rawArgs = $_SERVER['argv'];
        $cmdIndex = array_search('node', $rawArgs);

        if ($cmdIndex !== false) {
            $passedArgs = array_slice($rawArgs, $cmdIndex + 1);

            $commands = [];
            $env = $this->option('environment');

            foreach ($passedArgs as $arg) {
                if (str_starts_with($arg, '--environment=')) {
                    $env = str_replace('--environment=', '', $arg);

                    continue;
                }
                $commands[] = $arg;
            }

            $nodeCommand = implode(' ', $commands);
        } else {
            $nodeCommand = implode(' ', $this->argument('commands'));
            $env = $this->option('environment');
        }

        return $this->call('exec', [
            'commands' => [$nodeCommand],
            '--service' => 'node',
            '--environment' => $env,
        ]);
    }
}
