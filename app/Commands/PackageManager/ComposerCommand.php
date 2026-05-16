<?php

namespace App\Commands\PackageManager;

use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class ComposerCommand extends Command
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
    protected $signature = 'composer {commands* : The composer command to run} {--environment=local : The environment to target}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a composer command inside the cluster';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Capture everything from the original command line after 'composer'
        $rawArgs = $_SERVER['argv'];
        $cmdIndex = array_search('composer', $rawArgs);

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

            $composerCommand = implode(' ', $commands);
        } else {
            $composerCommand = implode(' ', $this->argument('commands'));
            $env = $this->option('environment');
        }

        return $this->call('exec', [
            'commands' => ["composer {$composerCommand}"],
            '--environment' => $env,
            '--service' => 'web',
        ]);
    }
}
