<?php

namespace App\Commands\PackageManager;

use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class BunCommand extends Command
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
    protected $signature = 'bun {commands* : The bun command to run} {--environment=local : The environment to target}';

    /**
     * The console command description.
     */
    protected $description = 'Run a bun command inside the Node pod';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Capture everything from the original command line after 'bun'
        $rawArgs = $_SERVER['argv'];
        $cmdIndex = array_search('bun', $rawArgs);

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

            $bunCommand = implode(' ', $commands);
        } else {
            $bunCommand = implode(' ', $this->argument('commands'));
            $env = $this->option('environment');
        }

        return $this->call('node', [
            'commands' => ["bun {$bunCommand}"],
            '--environment' => $env,
        ]);
    }
}
