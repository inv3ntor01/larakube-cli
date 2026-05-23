<?php

namespace App\Commands;

use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class ArtisanCommand extends Command
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
        // Capture everything from the original command line after the trigger ('art' or 'artisan')
        $rawArgs = $_SERVER['argv'];

        $trigger = 'art';
        $artIndex = array_search('art', $rawArgs);

        if ($artIndex === false) {
            $artIndex = array_search('artisan', $rawArgs);
            $trigger = 'artisan';
        }

        if ($artIndex !== false) {
            // Get everything after the trigger, but filter out options that belong to larakube itself if needed
            $passedArgs = array_slice($rawArgs, $artIndex + 1);

            // Filter out --environment from the passed args so it's not passed to artisan twice
            $commands = [];
            $env = $this->option('environment');

            foreach ($passedArgs as $arg) {
                if (str_starts_with($arg, '--environment=')) {
                    $env = str_replace('--environment=', '', $arg);

                    continue;
                }
                $commands[] = $arg;
            }

            $artisanCommand = implode(' ', $commands);
        } else {
            $artisanCommand = implode(' ', $this->argument('commands'));
            $env = $this->option('environment');
        }

        return $this->call('exec', [
            'commands' => ["php artisan {$artisanCommand}"],
            '--environment' => $env,
            '--service' => 'web',
        ]);
    }
}
