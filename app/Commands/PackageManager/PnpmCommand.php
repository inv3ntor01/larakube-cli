<?php

namespace App\Commands\PackageManager;

use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class PnpmCommand extends Command
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
    protected $signature = 'pnpm {commands* : The pnpm command to run} {--environment=local : The environment to target}';

    /**
     * The console command description.
     */
    protected $description = 'Run a pnpm command inside the Node pod';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Capture everything from the original command line after 'pnpm'
        $rawArgs = $_SERVER['argv'];
        $cmdIndex = array_search('pnpm', $rawArgs);

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

            $pnpmCommand = implode(' ', $commands);
        } else {
            $pnpmCommand = implode(' ', $this->argument('commands'));
            $env = $this->option('environment');
        }

        return $this->call('node', [
            'commands' => ["pnpm {$pnpmCommand}"],
            '--environment' => $env,
        ]);
    }
}
