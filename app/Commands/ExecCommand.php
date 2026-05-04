<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class ExecCommand extends Command
{
    use InteractsWithEnvironments, LaraKubeOutput;

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
    protected $signature = 'exec {commands* : The command to run} 
                            {--environment=local : The environment to target} 
                            {--service=web : The service to target (web or node)}
                            {--user=www-data : The user to run the command as}';

    /**
     * The console command description.
     */
    protected $description = 'Execute a command inside a running pod';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Capture everything from the original command line after 'exec'
        $rawArgs = $_SERVER['argv'];
        $execIndex = array_search('exec', $rawArgs);

        $environment = $this->option('environment');
        $service = $this->option('service');
        $user = $this->option('user');

        if ($execIndex !== false) {
            $passedArgs = array_slice($rawArgs, $execIndex + 1);
            $commands = [];

            foreach ($passedArgs as $arg) {
                // Filter out larakube-specific options
                if (str_starts_with($arg, '--environment=')) {
                    $environment = str_replace('--environment=', '', $arg);

                    continue;
                }
                if (str_starts_with($arg, '--service=')) {
                    $service = str_replace('--service=', '', $arg);

                    continue;
                }
                if (str_starts_with($arg, '--user=')) {
                    $user = str_replace('--user=', '', $arg);

                    continue;
                }
                $commands[] = $arg;
            }

            $command = implode(' ', $commands);
        } else {
            $command = implode(' ', $this->argument('commands'));
        }

        $namespace = $this->getNamespace($environment);

        $label = match ($service) {
            'node' => 'app=laravel-node',
            'web' => 'app=laravel-web',
            'horizon' => 'app=laravel-horizon',
            'reverb' => 'app=laravel-reverb',
            default => "app={$service}"
        };

        // Find the pod name
        $podName = trim(shell_exec("kubectl get pods -n {$namespace} -l {$label} -o jsonpath='{.items[0].metadata.name}' 2>/dev/null"));

        if (! $podName) {
            $this->laraKubeError("Could not find a running {$service} pod in namespace '{$namespace}'. Is the app running?");

            return 1;
        }

        $this->laraKubeInfo("Executing in {$podName} as container default user...");

        // Determine the correct container name (default to php for app services, otherwise use service name)
        $container = match ($service) {
            'web', 'horizon', 'reverb', 'scheduler' => 'php',
            'seaweedfs' => 'master',
            default => $service
        };

        // Execute the command.
        passthru("kubectl exec -it -n {$namespace} -c {$container} {$podName} -- /bin/sh -c \"{$command}\" 2>/dev/null || kubectl exec -it -n {$namespace} -c {$container} {$podName} -- {$command}");

        return 0;
    }
}
