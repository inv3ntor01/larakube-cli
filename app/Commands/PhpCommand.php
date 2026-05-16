<?php

namespace App\Commands;

use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class PhpCommand extends Command
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
    protected $signature = 'php {commands* : The php command to run} {--environment=local : The environment to target}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a php command inside the cluster';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Capture everything from the original command line after 'php'
        $rawArgs = $_SERVER['argv'];
        $cmdIndex = array_search('php', $rawArgs);

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

            $phpCommand = implode(' ', $commands);
        } else {
            $phpCommand = implode(' ', $this->argument('commands'));
            $env = $this->option('environment');
        }

        return $this->call('exec', [
            'commands' => ["php {$phpCommand}"],
            '--environment' => $env,
            '--service' => 'web',
        ]);
    }
}
